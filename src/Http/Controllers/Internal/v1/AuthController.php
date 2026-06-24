<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Auth\AppleVerifier;
use Fleetbase\Auth\FacebookVerifier;
use Fleetbase\Auth\GoogleVerifier;
use Fleetbase\Auth\Office365Verifier;
use Fleetbase\Events\UserCreatedNewCompany;
use Fleetbase\Exceptions\InvalidVerificationCodeException;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Http\Requests\ChangePasswordRequest;
use Fleetbase\Http\Requests\Internal\ResetPasswordRequest;
use Fleetbase\Http\Requests\Internal\UserForgotPasswordRequest;
use Fleetbase\Http\Requests\JoinOrganizationRequest;
use Fleetbase\Http\Requests\LoginRequest;
use Fleetbase\Http\Requests\SignUpRequest;
use Fleetbase\Http\Requests\SwitchOrganizationRequest;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Mail\UserCredentialsMail;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\UserForgotPassword;
use Fleetbase\Support\Auth;
use Fleetbase\Support\TwoFactorAuth;
use Fleetbase\Support\Utils;
use Fleetbase\Twilio\Support\Laravel\Facade as Twilio;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Authenticates a user by email and responds with an auth token.
     *
     * @return \Illuminate\Http\Response
     */
    public function login(LoginRequest $request)
    {
        $identity  = $request->input('identity');
        $password  = $request->input('password');
        $authToken = $request->input('authToken');

        // If an existing auth token is provided, attempt to re-authenticate with it.
        // The token must be valid AND must belong to the user identified by the
        // 'identity' field in this request, preventing token-swap attacks where a
        // token from one user could be used to authenticate as another.
        if ($authToken) {
            $personalAccessToken = PersonalAccessToken::findToken($authToken);

            if ($personalAccessToken) {
                $personalAccessToken->loadMissing('tokenable');
                $tokenOwner = $personalAccessToken->tokenable;

                if (
                    $tokenOwner instanceof User
                    && ($tokenOwner->email === $identity || $tokenOwner->phone === $identity)
                ) {
                    if ($tokenOwner->type === 'customer') {
                        return response()->error('Customer accounts must sign in through the customer portal.', 403, ['code' => 'customer_login_not_allowed']);
                    }

                    return response()->json([
                        'token' => $authToken,
                        'type'  => $tokenOwner->getType(),
                    ]);
                }
            }

            // If the token is invalid or does not match the claimed identity, fall
            // through silently to normal password-based authentication. Do not
            // return an error here to avoid leaking whether the token exists.
        }

        // Find the user using the identity provided
        $user = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity);
        })->first();

        if ($user && $user->type === 'customer') {
            return response()->error('Customer accounts must sign in through the customer portal.', 403, ['code' => 'customer_login_not_allowed']);
        }

        // If the user exists but has no password set (e.g. SSO-invited or provisioned
        // accounts), silently fall through to the generic credentials error below.
        // This guard MUST come before isInvalidPassword() which has a strict string
        // type declaration on $hashedPassword and would throw a TypeError on null.
        // We do NOT return a distinct error here to avoid leaking account state.
        if ($user && empty($user->password)) {
            $user = null;
        }

        // Use a generic error message for both non-existent user and wrong password
        // to prevent user enumeration via differential error responses.
        if (!$user || Auth::isInvalidPassword($password, $user->password)) {
            return response()->error('These credentials do not match our records.', 401, ['code' => 'invalid_credentials']);
        }

        // Check if 2FA enabled
        if (TwoFactorAuth::isEnabled($user)) {
            $twoFaSession = TwoFactorAuth::start($user);

            return response()->json([
                'twoFaSession' => $twoFaSession,
                'isEnabled'    => true,
            ]);
        }

        if ($user->isNotVerified() && $user->isNotAdmin()) {
            return response()->error('User is not verified.', 400, ['code' => 'not_verified']);
        }

        // Login
        $user->updateLastLogin();
        $token = $user->createToken($user->uuid);

        return response()->json(['token' => $token->plainTextToken, 'type' => $user->getType()]);
    }

    /**
     * Takes a request username/ or email and password and attempts to authenticate user
     * will return the user model if the authentication was successful, else will 400.
     *
     * @return \Illuminate\Http\Response
     */
    public function session(Request $request)
    {
        $token    = $request->bearerToken();
        $cacheKey = "session_validation_{$token}";

        // Cache session validation for 5 minutes
        $session = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($request) {
            $user = $request->user();

            if (!$user) {
                return null;
            }

            $sessionData = [
                'token'         => $request->bearerToken(),
                'user'          => $user->uuid,
                'verified'      => $user->isVerified(),
                'type'          => $user->getType(),
                'last_modified' => $user->updated_at,
            ];

            if (session()->has('impersonator')) {
                $sessionData['impersonator'] = session()->get('impersonator');
            }

            return $sessionData;
        });

        if (!$session) {
            return response()->error('Session has expired.', 401, ['restore' => false]);
        }

        // Generate an etag
        $etag = sha1(json_encode($session));

        return response()
            ->json($session)
            ->setEtag($etag)
            ->setLastModified($session['last_modified'])
            ->header('Cache-Control', 'private, no-cache, must-revalidate')
            ->header('X-Cache-Hit', 'false');
    }

    /**
     * Logs out the currently authenticated user.
     *
     * @return \Illuminate\Http\Response
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        Cache::forget("session_validation_{$token}");

        Auth::logout();

        return response()->json(['Goodbye']);
    }

    /**
     * Bootstrap endpoint - combines session, organizations, and installer status.
     *
     * @return \Illuminate\Http\Response
     */
    public function bootstrap(Request $request)
    {
        $user     = $request->user();
        $token    = $request->bearerToken();
        $cacheKey = "auth_bootstrap_{$user->uuid}_{$token}";

        // Cache for 5 minutes
        $bootstrap = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($request, $user) {
            // Get session data
            $session = [
                'token'    => $request->bearerToken(),
                'user'     => $user->uuid,
                'verified' => $user->isVerified(),
                'type'     => $user->getType(),
            ];

            if (session()->has('impersonator')) {
                $session['impersonator'] = session()->get('impersonator');
            }

            // Get organizations (optimized query)
            $organizations = Company::select([
                'companies.uuid',
                'companies.name',
                'companies.owner_uuid',
            ])
                ->join('company_users', 'companies.uuid', '=', 'company_users.company_uuid')
                ->where('company_users.user_uuid', $user->uuid)
                ->whereNull('company_users.deleted_at')
                ->whereNotNull('companies.owner_uuid')
                ->with(['owner:uuid,company_uuid,name,email'])
                ->distinct()
                ->get();

            return [
                'session'       => $session,
                'organizations' => Organization::collection($organizations),
            ];
        });

        return response()->json($bootstrap)
            ->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * Send a verification SMS code.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function sendVerificationSms(Request $request)
    {
        // Users phone number
        $phone       = $queryPhone = $request->input('phone');
        $countryCode = $request->input('countryCode');
        $for         = $request->input('driver');

        // set phone number
        if (!Str::startsWith($queryPhone, '+')) {
            $queryPhone = '+' . $countryCode . $phone;
        }

        // Make sure user exists with phone number
        $userExistsQuery = User::where('phone', $queryPhone)->whereNull('deleted_at')->withoutGlobalScopes();

        if ($for === 'driver') {
            $userExistsQuery->where('type', 'driver');
        }

        $userExists = $userExistsQuery->exists();

        if (!$userExists) {
            return response()->error('No user with this phone # found.');
        }

        // Generate hto
        $verifyCode    = mt_rand(100000, 999999);
        $verifyCodeKey =  Str::slug($queryPhone . '_verify_code', '_');

        // Send user their verification code
        try {
            Twilio::message($queryPhone, 'Your Fleetbase authentication code is ' . $verifyCode);
        } catch (\Exception|\Twilio\Exceptions\RestException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        // Store verify code for this number with a 10-minute TTL to prevent replay attacks
        Redis::setex($verifyCodeKey, 600, $verifyCode);

        // 200 OK
        return response()->json(['status' => 'OK']);
    }

    /**
     * Authenticate a user with SMS code.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function authenticateSmsCode(Request $request)
    {
        // Users phone number
        $phone       = $queryPhone = $request->input('phone');
        $countryCode = $request->input('countryCode');

        // set phone number
        if (!Str::startsWith($queryPhone, '+')) {
            $queryPhone = '+' . $countryCode . $phone;
        }

        // Users verfiy code entered
        $verifyCode    = $request->input('code');
        $verifyCodeKey =  Str::slug($queryPhone . '_verify_code', '_');

        // Retrieve the stored verification code from Redis
        $storedVerifyCode = Redis::get($verifyCodeKey);

        // Retrieve the optional testing bypass code from configuration.
        // This is configurable via the SMS_AUTH_BYPASS_CODE environment variable
        // and is intended for development/testing environments only.
        // It MUST be left unset (null) in production deployments.
        $bypassCode = config('fleetbase.sms_auth_bypass_code');

        // Verify the submitted code against the stored OTP using a constant-time
        // comparison to prevent timing attacks. If a bypass code is configured
        // and the environment is not production, also allow that code.
        $isValidOtp    = !empty($storedVerifyCode) && hash_equals((string) $storedVerifyCode, (string) $verifyCode);
        $isBypassValid = !empty($bypassCode) && !app()->environment('production') && hash_equals((string) $bypassCode, (string) $verifyCode);

        if (!$isValidOtp && !$isBypassValid) {
            return response()->error('Invalid verification code');
        }

        // Remove from redis
        Redis::del($verifyCodeKey);

        // get user for phone number
        $user = User::where('phone', $queryPhone)->first();

        // Attempt authentication
        if ($user) {
            // Set authenticatin user
            Auth::login($user);

            // Generate token
            try {
                $token = $user->createToken($user->phone)->plainTextToken;
            } catch (\Exception $e) {
                return response()->error($e->getMessage());
            }

            if ($user->type === 'driver') {
                $user->load(['driver']);
            }

            // Send message to notify users authentication
            return response()->json([
                'token' => $token,
                'user'  => $user,
            ]);
        }

        // If unable to authenticate user, respond with error
        return response()->json('Authentication failed', 401);
    }

    /**
     * Create resend verification code session.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function createVerificationSession(Request $request)
    {
        $send                     = $request->boolean('send');
        $email                    = $request->input('email');
        $token                    = Str::random(40);
        $verificationSessionToken = base64_encode($email . '|' . $token);

        // If opted to send verification token along with session
        if ($send) {
            // Get user
            $user = User::where('email', $email)->first();

            if ($user) {
                // create verification code
                VerificationCode::generateEmailVerificationFor($user);
            } else {
                Redis::del($token);

                return response()->error('No user found with provided email address.');
            }
        }

        // Store in redis
        Redis::set($token, $verificationSessionToken, 'EX', now()->addMinutes(10)->timestamp);

        return response()->json([
            'token'   => $token,
            'session' => base64_encode($user->uuid),
        ]);
    }

    /**
     * Validates an email verification session.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function validateVerificationSession(Request $request)
    {
        $email                    = $request->input('email');
        $token                    = $request->input('token');
        $verificationSessionToken = base64_encode($email . '|' . $token);
        $sessionToken             = Redis::get($token);
        $isValid                  = $sessionToken === $verificationSessionToken;

        return response()->json([
            'valid' => $isValid,
        ]);
    }

    /**
     * Send/Resend verification email.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function sendVerificationEmail(Request $request)
    {
        $email                    = $request->input('email');
        $token                    = $request->input('token');
        $verificationSessionToken = base64_encode($email . '|' . $token);
        $sessionToken             = Redis::get($token);
        $isValid                  = $sessionToken === $verificationSessionToken;

        // Check in session
        if (!$isValid) {
            return response()->error('Invalid verification session.');
        }

        // Get user
        $user = User::where('email', $email)->first();

        if ($user) {
            // create verification code
            VerificationCode::generateEmailVerificationFor($user);
        } else {
            return response()->error('No user found with provided email address.');
        }

        return response()->json([
            'status' => 'success',
        ]);
    }

    /**
     * Verfiy and validate an email address with code.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function verifyEmail(Request $request)
    {
        $authenticate             = $request->boolean('authenticate');
        $token                    = $request->input('token');
        $email                    = $request->input('email');
        $code                     = $request->input('code');
        $verificationSessionToken = base64_encode($email . '|' . $token);
        $sessionToken             = Redis::get($token);
        $isValid                  = $sessionToken === $verificationSessionToken;

        // Check in session
        if (!$isValid) {
            return response()->error('Invalid verification session.');
        }

        // Check user
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->error('No user found with provided email.');
        }

        // If user is already verified
        if ($user->isVerified()) {
            return response()->error('User is already verified.');
        }

        // Verify the user using the verification code
        try {
            $user->verify($code);
        } catch (InvalidVerificationCodeException $e) {
            return response()->error('Invalid verification code.');
        }

        // Activate user
        $user->activate();

        // If authenticate is set, generate and return a token
        if ($authenticate) {
            $user->updateLastLogin();
            $token = $user->createToken($user->uuid);

            return response()->json([
                'status'      => 'ok',
                'verified_at' => $user->getDateVerified(),
                'token'       => $token->plainTextToken,
            ]);
        }

        // Return success response without token
        return response()->json([
            'status'      => 'ok',
            'verified_at' => $user->getDateVerified(),
            'token'       => null,
        ]);
    }

    /**
     * Allow user to verify SMS code.
     *
     * @param \\Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response $response
     */
    public function verifySmsCode(Request $request)
    {
        // Users phone number
        $phone = $request->input('phone');

        // Users verfiy code entered
        $verifyCode    = $request->input('code');
        $verifyCodeKey =  Str::slug($phone . '_verify_code', '_');

        // Generate hto
        $storedVerifyCode = Redis::get($verifyCodeKey);

        // Verify
        if ($verifyCode === $storedVerifyCode) {
            // Remove from redis
            Redis::del($verifyCodeKey);

            // 200 OK
            return response()->json([
                'status'  => 'OK',
                'message' => 'Code verified',
            ]);
        }

        // 400 ERROR
        return response()->error('Invalid verification code');
    }

    /**
     * Creates a new company and user account.
     *
     * @param \Fleetbase\Http\Requests\SigUpRequest $request
     *
     * @return \Illuminate\Http\Response
     */
    public function signUp(SignUpRequest $request)
    {
        $userDetails    = $request->input('user');
        $companyDetails = $request->input('company');

        $newUser = Auth::register($userDetails, $companyDetails);
        $token   = $newUser->createToken($newUser->uuid);

        return response()->json(['token' => $token->plainTextToken]);
    }

    /**
     * Initializes a password reset using a verification code.
     *
     * @return \Illuminate\Http\Response
     */
    public function createPasswordReset(UserForgotPasswordRequest $request)
    {
        $user = User::where('email', $request->input('email'))->first();

        // create verification code
        $verificationCode = VerificationCode::create([
            'subject_uuid' => $user->uuid,
            'subject_type' => Utils::getModelClassName($user),
            'for'          => 'password_reset',
            'expires_at'   => Carbon::now()->addMinutes(15),
            'status'       => 'active',
        ]);

        // notify user of password reset
        $user->notify(new UserForgotPassword($verificationCode));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Reset password.
     *
     * @return \Illuminate\Http\Response
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $verificationCode = VerificationCode::where('code', $request->input('code'))->with(['subject'])->first();
        $link             = $request->input('link');
        $password         = $request->input('password');
        // If link isn't valid
        if ($verificationCode->uuid !== $link) {
            return response()->error('Invalid password reset request!');
        }

        // if no subject error
        if (!isset($verificationCode->subject)) {
            return response()->error('Invalid password reset request!');
        }

        // reset users password
        $verificationCode->subject->changePassword($password);

        // verify code by deleting so its unusable
        $verificationCode->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Simple check if verificationc code is still valid.
     *
     * @return \Illuminate\Http\Response
     */
    public function validateVerificationCode(Request $request)
    {
        $id    = $request->input('id');
        $valid = VerificationCode::where('uuid', $id)->exists();

        return response()->json(['is_valid' => $valid, 'id' => $id]);
    }

    /**
     * Takes a request username/ or email and password and attempts to authenticate user
     * will return the user model if the authentication was successful, else will 400.
     *
     * @return \Illuminate\Http\Response
     */
    public function getUserOrganizations(Request $request)
    {
        $user     = $request->user();
        $cacheKey = "user_organizations_{$user->uuid}";

        // Cache for 30 minutes
        $companies = Cache::remember($cacheKey, 60 * 30, function () use ($user) {
            return Company::select([
                'companies.uuid',
                'companies.name',
                'companies.phone',
                'companies.options',
                'companies.currency',
                'companies.timezone',
                'companies.status',
                'companies.type',
                'companies.owner_uuid',
                'companies.created_at',
                'companies.updated_at',
            ])
                ->join('company_users', 'companies.uuid', '=', 'company_users.company_uuid')
                ->where('company_users.user_uuid', $user->uuid)
                ->whereNull('company_users.deleted_at')
                ->whereNotNull('companies.owner_uuid')
                ->with([
                    'owner:uuid,company_uuid,name,email,updated_at',
                    'owner.companyUser:uuid,user_uuid,company_uuid,updated_at',
                ])
                ->distinct()
                ->get();
        });

        /**
         * Generate a full ETag representing:
         * - all org UUIDs
         * - all org updated_at timestamps
         * - count of organizations
         */
        $etagPayload = $companies->map(function ($company) {
            $ownerTimestamp = $company->owner?->updated_at?->timestamp ?? 0;

            return $company->uuid . ':' . $company->updated_at->timestamp . ':' . $ownerTimestamp;
        })->join('|');

        // Add count to ETag (if orgs added/removed)
        $etagPayload .= '|count:' . $companies->count();
        $etag = sha1($etagPayload);

        return Organization::collection($companies)
            ->response()
            ->setEtag($etag)
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    /**
     * Clear user organizations cache (call when org changes).
     *
     * @return void
     */
    public static function clearUserOrganizationsCache(string $userUuid)
    {
        Cache::forget("user_organizations_{$userUuid}");
    }

    /**
     * Allows a user to simply switch their organization.
     *
     * @return \Illuminate\Http\Response
     */
    public function switchOrganization(SwitchOrganizationRequest $request)
    {
        $nextOrganization = $request->input('next');
        $user             = $request->user();

        if ($nextOrganization === $user->company_uuid) {
            return response()->json(
                [
                    'errors' => ['User is already on this organizations session'],
                ]
            );
        }

        if (!CompanyUser::where(['user_uuid' => $user->uuid, 'company_uuid' => $nextOrganization])->exists()) {
            return response()->json(
                [
                    'errors' => ['You do not belong to this organization'],
                ]
            );
        }

        $user->assignCompanyFromId($nextOrganization);
        Auth::setSession($user);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Allows a user to join an organization.
     *
     * @return \Illuminate\Http\Response
     */
    public function joinOrganization(JoinOrganizationRequest $request)
    {
        try {
            $company = Company::where('public_id', $request->input('next'))->first();
            $user    = Auth::getUserFromSession($request);

            // Make sure user has been invited to join organizations
            $isAlreadyInvited = Invite::isAlreadySentToJoinCompany($user, $company);
            if (!$isAlreadyInvited) {
                return response()->error('User has not been invited to join this organization.');
            }

            // Make sure user isn't already a member of this organization
            if ($company->uuid === $user->company_uuid) {
                return response()->error('User is already a member of this organization.');
            }

            $company->assignUser($user);
            Auth::setSession($user);

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            return response()->error(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Unable to join organization.');
        }
    }

    /**
     * Allows user to create a new organization.
     *
     * @return \Illuminate\Http\Response
     */
    public function createOrganization(Request $request)
    {
        $user    = Auth::getUserFromSession($request);
        $input   = array_merge($request->only(['name', 'description', 'phone', 'email', 'currency', 'country', 'timezone']), ['owner_uuid' => $user->uuid]);

        try {
            $company = Company::create($input);

            // Set company owner
            $company->setOwner($user)->save();

            // Assign user to organization
            $user->assignCompany($company, 'Administrator');
            $user->assignSingleRole('Administrator');

            // Company onboarding is not necessary - set correct flags
            $company->update([
                'onboarding_completed_at'      => now(),
                'onboarding_completed_by_uuid' => $user->uuid,
            ]);

            // Fire event that user created a new organization
            event(new UserCreatedNewCompany($user, $company));
        } catch (\Throwable $e) {
            return response()->error($e->getMessage());
        }

        Auth::setSession($user);

        return new Organization($company);
    }

    /**
     * Returns all authorization services which provide schemas.
     *
     * @return \Illuminate\Http\Response
     */
    public function services()
    {
        $schemas  = Utils::getAuthSchemas();
        $services = [];

        foreach ($schemas as $schema) {
            $services[] = $schema->name;
        }

        return response()->json(array_unique($services));
    }

    /**
     * Change a user password.
     *
     * @return \Illuminate\Http\Response
     */
    public function changeUserPassword(ChangePasswordRequest $request)
    {
        $user = Auth::getUserFromSession($request);
        if (!$user) {
            return response()->error('Not authorized to change user password.', 401);
        }

        $canChangePassword = $user->isAdmin() || $user->hasRole('Administrator') || $user->hasPermissionTo('iam change-password-for user');
        if (!$canChangePassword) {
            return response()->error('Not authorized to change user password.', 401);
        }

        // Get request input
        $userId          = $request->input('user');
        $password        = $request->input('password');
        $confirmPassword = $request->input('password_confirmation');
        $sendCredentials = $request->boolean('send_credentials');

        if (!$userId) {
            return response()->error('No user specified to change password for.');
        }

        if ($password !== $confirmPassword) {
            return response()->error('Passwords do not match.');
        }

        $targetUser = User::where('uuid', $userId)->whereHas('anyCompanyUser', function ($query) {
            $query->where('company_uuid', session('company'));
        })->first();
        if (!$targetUser) {
            return response()->error('User not found to change password for.');
        }

        // Change password
        $targetUser->changePassword($password);

        // Send credentials to customer if opted
        if ($sendCredentials) {
            Mail::to($targetUser)->send(new UserCredentialsMail($password, $targetUser));
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Allows system admin to impersonate a user.
     *
     * @return \Illuminate\Http\Response
     */
    public function impersonate(AdminRequest $request)
    {
        $currentUser = Auth::getUserFromSession($request);
        if ($currentUser->isNotAdmin()) {
            return response()->error('Not authorized to impersonate users.');
        }

        $targetUserId = $request->input('user');
        if (!$targetUserId) {
            return response()->error('Not target user selected to impersonate.');
        }

        $targetUser = User::where('uuid', $targetUserId)->first();
        if (!$targetUser) {
            return response()->error('The selected user to impersonate was not found.');
        }

        try {
            Auth::setSession($targetUser);
            session()->put('impersonator', $currentUser->uuid);
            $token = $targetUser->createToken($targetUser->uuid);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return response()->json(['status' => 'ok', 'token' => $token->plainTextToken]);
    }

    /**
     * Ends the impersonation session.
     *
     * @return \Illuminate\Http\Response
     */
    public function endImpersonation()
    {
        $impersonatorId = session()->get('impersonator');
        if (!$impersonatorId) {
            return response()->error('Not impersonator session found.');
        }

        $impersonator = User::where('uuid', $impersonatorId)->first();
        if (!$impersonator) {
            return response()->error('The impersonator user was not found.');
        }

        if ($impersonator->isNotAdmin()) {
            return response()->error('The impersonator does not have permissions. Logout.');
        }

        try {
            Auth::setSession($impersonator);
            session()->remove('impersonator');
            $token = $impersonator->createToken($impersonator->uuid);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        return response()->json(['status' => 'ok', 'token' => $token->plainTextToken]);
    }

    // -----------------------------------------------------------------------
    // OAuth Console login (issue #453)
    // -----------------------------------------------------------------------
    //
    // Four POST endpoints — one per supported identity provider. The client
    // (Console) obtains a provider-issued token via the provider's JS SDK
    // and POSTs it here; we verify with the corresponding Verifier class
    // and issue a Sanctum personal-access token matching the native login
    // response shape (`{token, type}`).
    //
    // Account-linking policy: if a verified email or provider user-id
    // matches an existing User, we link the OAuth identity to that user
    // (stamping the `<provider>_user_id` column when previously null). If
    // no match exists, we create a new User with `email_verified_at = now()`
    // (the IdP already attested the email) and an empty `company_uuid` —
    // the Console UI routes new users into join-or-create-org from there.
    //
    // 2FA: when 2FA is enabled on the user record, OAuth login still goes
    // through the 2FA challenge — consistent with password login (§AC of
    // the parent issue does not exempt OAuth from 2FA).

    /**
     * Authenticate a Console user via a Google ID token.
     *
     * Request body:
     *   - idToken  (string, required): the Google ID token issued client-side
     *   - clientId (string, required): the Google OAuth client_id the token
     *                                  was issued for. The server verifies
     *                                  the token's `aud` claim against it.
     */
    public function loginWithGoogle(Request $request)
    {
        $idToken  = $request->input('idToken');
        $clientId = $request->input('clientId');
        if (!$idToken || !$clientId) {
            return response()->error('Missing required Google authentication parameters.', 400);
        }

        $payload = GoogleVerifier::verifyIdToken($idToken, $clientId);
        if (!$payload) {
            return response()->error('Google Sign-In authentication is not valid.', 400);
        }

        return $this->oauthRespond(
            providerColumn: 'google_user_id',
            providerUserId: (string) data_get($payload, 'sub'),
            email:           data_get($payload, 'email'),
            name:            data_get($payload, 'name'),
        );
    }

    /**
     * Authenticate a Console user via a Facebook access token.
     *
     * Request body:
     *   - accessToken (string, required): the Facebook access token from the
     *                                     client-side JS SDK
     *   - appId       (string, optional): the client's Facebook app_id;
     *                                     when present, must match the
     *                                     server's configured app_id
     */
    public function loginWithFacebook(Request $request)
    {
        $accessToken = $request->input('accessToken');
        $appId       = $request->input('appId');
        if (!$accessToken) {
            return response()->error('Missing required Facebook authentication parameters.', 400);
        }

        $payload = FacebookVerifier::verifyAccessToken($accessToken, $appId);
        if (!$payload) {
            return response()->error('Facebook Sign-In authentication is not valid.', 400);
        }

        return $this->oauthRespond(
            providerColumn: 'facebook_user_id',
            providerUserId: (string) $payload['user_id'],
            email:           $payload['email'] ?? null,
            name:            $payload['name'] ?? null,
        );
    }

    /**
     * Authenticate a Console user via a Microsoft / Office365 ID token.
     *
     * Request body:
     *   - idToken (string, required): the Microsoft ID token from MSAL.js
     *
     * Audience + issuer are validated server-side against
     * `services.microsoft.client_id` and `services.microsoft.tenant`.
     */
    public function loginWithOffice365(Request $request)
    {
        $idToken = $request->input('idToken');
        if (!$idToken) {
            return response()->error('Missing required Office365 authentication parameters.', 400);
        }

        $payload = Office365Verifier::verifyIdToken($idToken);
        if (!$payload) {
            return response()->error('Office365 Sign-In authentication is not valid.', 400);
        }

        return $this->oauthRespond(
            providerColumn: 'microsoft_user_id',
            providerUserId: (string) $payload['user_id'],
            email:           $payload['email'] ?? null,
            name:            $payload['name'] ?? null,
        );
    }

    /**
     * Authenticate a Console user via Apple Sign-In.
     *
     * Request body:
     *   - identityToken (string, required): the Apple identity JWT
     *   - appleUserId   (string, required): the stable `sub` Apple assigns
     *   - email         (string, optional): provided by Apple on first login
     *                                       only — pass through from client
     *   - name          (string, optional): provided by Apple on first login
     *                                       only
     */
    public function loginWithApple(Request $request)
    {
        $identityToken = $request->input('identityToken');
        $appleUserId   = $request->input('appleUserId');
        $email         = $request->input('email');
        $name          = $request->input('name');

        if (!$identityToken || !$appleUserId) {
            return response()->error('Missing required Apple authentication parameters.', 400);
        }

        // AppleVerifier::verifyAppleJwt lets parse / signature failures bubble
        // up as exceptions (unlike Google/Facebook/Office365 verifiers, which
        // return null on any failure). Wrap so malformed input becomes the
        // same 400 the other three providers return instead of a 500.
        try {
            $appleVerified = AppleVerifier::verifyAppleJwt($identityToken);
        } catch (\Throwable $e) {
            logger()->info('Apple Sign-In verification raised an exception: ' . $e->getMessage());
            $appleVerified = false;
        }
        if (!$appleVerified) {
            return response()->error('Apple Sign-In authentication is not valid.', 400);
        }

        return $this->oauthRespond(
            providerColumn: 'apple_user_id',
            providerUserId: (string) $appleUserId,
            email:           $email,
            name:            $name,
        );
    }

    /**
     * Shared finalisation for all four OAuth providers.
     *
     * Looks up an existing user by verified email OR provider user-id;
     * links the identity onto the existing user if found; otherwise creates
     * a fresh user with the IdP-attested email already verified. Then runs
     * the same 2FA + verification gate password login uses and issues a
     * Sanctum token.
     */
    protected function oauthRespond(
        string $providerColumn,
        string $providerUserId,
        ?string $email,
        ?string $name
    ) {
        // Normalise email to lowercase before any lookup — User::create
        // and Auth::register both do this on the password path, and we
        // must match so case-variant duplicates can't sneak in.
        if ($email) {
            $email = strtolower($email);
        }

        $user = User::where(function ($query) use ($email, $providerColumn, $providerUserId) {
            if ($email) {
                $query->where('email', $email);
                $query->orWhere($providerColumn, $providerUserId);
            } else {
                $query->where($providerColumn, $providerUserId);
            }
        })->first();

        if (!$user) {
            // New user via OAuth — IdP has attested the email so we stamp
            // email_verified_at. No password is set; the account will only
            // be usable via the same OAuth provider until the user opts in
            // to set one. company_uuid is left null; the Console join /
            // create-org flow picks them up.
            $attributes = [
                'email'             => $email,
                'name'              => $name,
                $providerColumn     => $providerUserId,
                'email_verified_at' => $email ? now() : null,
            ];
            $user = User::create($attributes);
        } elseif (!$user->{$providerColumn}) {
            // Existing user — link the OAuth identity if not already stamped.
            $user->{$providerColumn} = $providerUserId;
            $user->save();
        }

        // 2FA is honoured on OAuth login too — consistent with password login.
        if (TwoFactorAuth::isEnabled($user)) {
            $twoFaSession = TwoFactorAuth::start($user);

            return response()->json([
                'twoFaSession' => $twoFaSession,
                'isEnabled'    => true,
            ]);
        }

        // Email verification is automatic for OAuth (IdP attested), but a
        // user record could theoretically pre-exist as unverified (created
        // by an admin invitation that didn't complete). Match the native
        // login policy: admins bypass, everyone else needs a verified email.
        if ($user->isNotVerified() && $user->isNotAdmin()) {
            return response()->error('User is not verified.', 400, ['code' => 'not_verified']);
        }

        $user->updateLastLogin();
        $token = $user->createToken($user->uuid);

        return response()->json([
            'token' => $token->plainTextToken,
            'type'  => $user->getType(),
        ]);
    }
}
