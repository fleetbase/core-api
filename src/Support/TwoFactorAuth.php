<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Class TwoFactorAuth.
 */
class TwoFactorAuth
{
    /**
     * Lifetime of a 2FA session in seconds.
     */
    public const SESSION_TTL = 600;

    /**
     * Failed code attempts allowed per 2FA session before it is invalidated.
     */
    public const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * The 2FA method that uses codes from an authenticator app (TOTP, RFC 6238).
     */
    public const METHOD_AUTHENTICATOR_APP = 'authenticator_app';

    /**
     * Seconds a user has to confirm a new authenticator app with its first code.
     */
    public const AUTHENTICATOR_ENROLLMENT_TTL = 900;

    /**
     * Number of one-time recovery codes issued with an authenticator app.
     */
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Authenticator codes accepted either side of the current 30 second step, to allow for clock drift.
     */
    public const AUTHENTICATOR_WINDOW = 1;

    /**
     * Marks a client token for an authenticator app challenge, instead of a sent code.
     */
    private const AUTHENTICATOR_CLIENT_TOKEN = 'authenticator';

    /**
     * Save Two-Factor Authentication settings for System wide usage.
     *
     * @param array $twoFaSettings an array containing Two-Factor Authentication settings
     *
     * @return Setting|null the saved Two-Factor Authentication settings, or null on failure
     *
     * @throws \Exception if invalid Two-Factor Authentication settings data is provided
     */
    public static function configureTwoFaSettings(array $twoFaSettings = []): ?Setting
    {
        return Setting::configureSystem('2fa', $twoFaSettings);
    }

    /**
     * Get system wide Two-Factor Authentication settings.
     *
     * @return Setting|null the Two-Factor Authentication settings, or null if not found
     */
    public static function getTwoFaConfiguration(): ?Setting
    {
        $twoFaSettings = Setting::getByKey('system.2fa');

        if (!$twoFaSettings) {
            $twoFaSettings = static::configureTwoFaSettings(['enabled' => false, 'method' => 'email', 'enforced' => false]);
        }

        return $twoFaSettings;
    }

    /**
     * Save Two-Factor Authentication settings for a specific subject (e.g., User, Company).
     *
     * @param Model $subject       the subject model for which to save the settings
     * @param array $twoFaSettings an array containing Two-Factor Authentication settings
     *
     * @return Setting the saved Two-Factor Authentication settings
     *
     * @throws \Exception if the subject is not an instance of Model
     */
    private static function saveTwoFaSettingsForSubject(Model $subject, array $twoFaSettings = []): Setting
    {
        $type = Str::singular(Str::snake($subject->getTable(), '-')); // `user` - `company`
        $key  = $type . '.' . $subject->getKey() . '.2fa';

        return Setting::configure($key, $twoFaSettings);
    }

    /**
     * Save Two-Factor Authentication settings for a user.
     *
     * @param User  $user          the user for which to save the settings
     * @param array $twoFaSettings an array containing Two-Factor Authentication settings
     *
     * @return Setting the saved Two-Factor Authentication settings
     */
    public static function saveTwoFaSettingsForUser(User $user, array $twoFaSettings = []): Setting
    {
        return static::saveTwoFaSettingsForSubject($user, $twoFaSettings);
    }

    /**
     * Save Two-Factor Authentication settings for a company.
     *
     * @param Company $company       the company for which to save the settings
     * @param array   $twoFaSettings an array containing Two-Factor Authentication settings
     *
     * @return Setting the saved Two-Factor Authentication settings
     */
    public static function saveTwoFaSettingsForCompany(Company $company, array $twoFaSettings = []): Setting
    {
        return static::saveTwoFaSettingsForSubject($company, $twoFaSettings);
    }

    /**
     * Get Two-Factor Authentication settings for a specific subject (e.g., User, Company).
     *
     * @param Model $subject the subject model for which to retrieve the settings
     *
     * @return Setting the Two-Factor Authentication settings for the subject
     *
     * @throws \Exception if the subject is not an instance of Model
     */
    private static function getTwoFaSettingsForSubject(Model $subject): Setting
    {
        $type = Str::singular(Str::snake($subject->getTable(), '-')); // `user` - `company`
        $key  = $type . '.' . $subject->getKey() . '.2fa';

        // Get the settings
        $twoFaSettings = Setting::getByKey($key);

        if (!$twoFaSettings) {
            $twoFaSettings = static::saveTwoFaSettingsForSubject($subject, ['enabled' => false, 'method' => 'email']);
        }

        return $twoFaSettings;
    }

    /**
     * Get Two-Factor Authentication settings for a user.
     *
     * @param User $user the user for which to retrieve the settings
     *
     * @return Setting the Two-Factor Authentication settings for the user
     */
    public static function getTwoFaSettingsForUser(User $user): Setting
    {
        return static::getTwoFaSettingsForSubject($user);
    }

    /**
     * Get Two-Factor Authentication settings for a company.
     *
     * @param Company $company the company for which to retrieve the settings
     *
     * @return Setting the Two-Factor Authentication settings for the company
     */
    public static function getTwoFaSettingsForCompany(Company $company): Setting
    {
        return static::getTwoFaSettingsForSubject($company);
    }

    /**
     * Get a client session token from a Two-Factor Authentication session.
     *
     * @param string      $token       the Two-Factor Authentication token
     * @param string      $identity    the user identity
     * @param string|null $clientToken the optional client session token
     *
     * @return string the client session token
     *
     * @throws \Exception if Two-Factor Authentication is not enabled or the session is invalid
     */
    public static function getClientSessionTokenFromTwoFaSession(string $token, string $identity, ?string $clientToken = null): string
    {
        // Get user from identity
        $user = static::getUserFromIdentity($identity);
        if (!$user) {
            throw new \Exception('No user found for the identity provided.');
        }

        // Check if enabled 2FA
        if (!self::isEnabled($user)) {
            throw new \Exception('2FA Authentication is not enabled.');
        }

        // An authenticator app challenge stays valid for as long as the 2FA session does
        if ($clientToken && static::isAuthenticatorClientToken($clientToken)) {
            if (static::getUserFromAuthenticatorClientToken($clientToken)?->uuid === $user->uuid
                && static::isTwoFaSessionKeyValid(static::decryptSessionKey($token, $user->uuid), $user)) {
                return $clientToken;
            }

            static::forgetTwoFaSession($token, $identity);
            throw new \Exception('2FA Verification session has expired.');
        }

        // If a client session token is provided validate by fetching the verification code
        // If a verification code exists then we just return the current valid client session
        if ($clientToken) {
            $verificationCode = static::getVerificationCodeFromClientToken($clientToken);

            // If verification code has expired throw exception
            if ($verificationCode && $verificationCode->hasExpired()) {
                // @codeCoverageIgnoreStart
                // ExpiryScope filters expired verification codes before this guard is reachable.
                static::forgetTwoFaSession($token, $identity);
                throw new \Exception('2FA Verification code has expired.');
                // @codeCoverageIgnoreEnd
            }

            if ($verificationCode) {
                return $clientToken;
            } else {
                static::forgetTwoFaSession($token, $identity);
                throw new \Exception('2FA Verification code is invalid or has expired.');
            }
        }

        // Decrypt two fa session token
        $twoFaSessionKey = static::decryptSessionKey($token, $user->uuid);

        // Validate session key that it is valid and exists
        if (static::isTwoFaSessionKeyValid($twoFaSessionKey, $user)) {
            // Authenticator app users type the code from their app, so nothing is sent
            if (static::usesAuthenticatorApp($user)) {
                return static::createAuthenticatorClientToken($user);
            }

            // Send the verification code then create a client session for the verification code and user
            $verificationCode = static::sendVerificationCode($user);
            $clientToken      = static::createClientSessionToken($verificationCode);

            return $clientToken;
        }

        throw new \Exception('2FA Authentication session is invalid');
    }

    /**
     * Get how the user answers a 2FA challenge: `authenticator_app` when the client token
     * is for an authenticator app, otherwise the method the code was sent with.
     *
     * @param string $identity    the user identity
     * @param string $clientToken the client session token
     */
    public static function getChallengeMethod(string $identity, string $clientToken): string
    {
        if (static::isAuthenticatorClientToken($clientToken)) {
            return static::METHOD_AUTHENTICATOR_APP;
        }

        $user   = static::getUserFromIdentity($identity);
        $method = $user ? (string) static::getTwoFaSettingsForUser($user)->getValue('method', 'email') : 'email';

        // Authenticator app users only get a sent code as a fallback, see sendVerificationCode()
        return $method === static::METHOD_AUTHENTICATOR_APP && $user ? static::fallbackMethod($user) : $method;
    }

    /**
     * Validate a Two-Factor Authentication session token.
     *
     * @param string      $token       the Two-Factor Authentication token
     * @param string      $identity    the user identity
     * @param string|null $clientToken the optional client session token
     *
     * @return bool true if the session token is valid, false otherwise
     */
    public static function validateSessionToken(string $token, string $identity, ?string $clientToken = null): bool
    {
        // Get user from identity
        $user = static::getUserFromIdentity($identity);
        if (!$user) {
            return false;
        }

        // Check if enabled 2FA
        if (!self::isEnabled($user)) {
            return false;
        }

        if ($clientToken && static::isAuthenticatorClientToken($clientToken)) {
            return static::getUserFromAuthenticatorClientToken($clientToken)?->uuid === $user->uuid
                && static::isTwoFaSessionKeyValid(static::decryptSessionKey($token, $user->uuid), $user);
        }

        // If a client session token is provided validate by fetching the verification code
        // If a verification code exists then we just return the current valid client session
        if ($clientToken) {
            $verificationCode = static::getVerificationCodeFromClientToken($clientToken);

            // If verification code has expired throw exception
            if ($verificationCode && $verificationCode->hasExpired()) {
                // @codeCoverageIgnoreStart
                // ExpiryScope filters expired verification codes before this guard is reachable.
                static::forgetTwoFaSession($token, $identity);

                return false;
                // @codeCoverageIgnoreEnd
            }

            if ($verificationCode) {
                return $clientToken;
            } else {
                static::forgetTwoFaSession($token, $identity);

                return false;
            }
        }

        // Decrypt two fa session token
        $twoFaSessionKey = static::decryptSessionKey($token, $user->uuid);

        // Validate session key that it is valid and exists
        if (static::isTwoFaSessionKeyValid($twoFaSessionKey, $user)) {
            return true;
        }

        return false;
    }

    /**
     * Send a Two-Factor Authentication verification code to the user.
     *
     * @param User $user         the user to send the verification code to
     * @param int  $expiresAfter the expiration time for the verification code in seconds
     *
     * @return VerificationCode the generated verification code
     *
     * @throws \Exception if no phone number or email is available, or an invalid method is selected in settings
     */
    public static function sendVerificationCode(User $user, int $expiresAfter = 61): VerificationCode
    {
        $twoFaSettings = static::getTwoFaSettingsForUser($user);
        $method        = $twoFaSettings->getValue('method', 'email');
        $expiresAfter  = Carbon::now()->addSeconds($expiresAfter);

        // A code can still be sent to authenticator app users, as a fallback when they
        // cannot use their app.
        if ($method === static::METHOD_AUTHENTICATOR_APP) {
            $method = static::fallbackMethod($user);
        }

        // Create SMS and Email message callback
        $messageCallback = function ($verificationCode) {
            return $verificationCode->code . ' is your ' . config('app.name') . ' 2FA Code';
        };

        if ($method === 'sms') {
            // if user has no phone number throw error
            if (!$user->phone) {
                throw new \Exception('No phone number to send 2FA code to.');
            }

            // create verification code
            return VerificationCode::generateSmsVerificationFor($user, '2fa', [
                'messageCallback' => $messageCallback,
                'expiresAfter'    => $expiresAfter,
            ]);
        }

        if ($method === 'email') {
            // if user has no phone number throw error
            if (!$user->email) {
                throw new \Exception('No email to send 2FA code to.');
            }

            // create verification code
            return VerificationCode::generateEmailVerificationFor($user, '2fa', [
                'subject' => $messageCallback,
                'content' => function ($verificationCode) {
                    return 'Your two-factor authentication code is: ' . $verificationCode->code;
                },
                'expiresAfter' => $expiresAfter,
            ]);
        }

        throw new \Exception('Invalid 2FA method selected in settings.');
    }

    /**
     * Create a Two-Factor Authentication session if enabled.
     *
     * @deprecated a 2FA session must only be started once the user's first factor (password or
     *             OAuth provider) has been verified, otherwise the code alone is enough to sign in.
     *             Use start() with the authenticated user instead.
     *
     * @param string $identity the user identity
     *
     * @return string|null the Two-Factor Authentication session key, or null if not enabled
     */
    public static function createTwoFaSessionIfEnabled(string $identity): ?string
    {
        $user = static::getUserFromIdentity($identity);
        if (!$user) {
            return null;
        }

        $isTwoFaEnabled = self::isEnabled($user);

        if ($isTwoFaEnabled) {
            return self::start($identity);
        }

        return null;
    }

    /**
     * Check if Two-Factor Authentication is enabled.
     *
     * @return bool true if Two-Factor Authentication is enabled, false otherwise
     */
    public static function isEnabled(User $user): bool
    {
        $twoFaSettings = static::getTwoFaSettingsForUser($user);
        // getTwoFaSettingsForUser() is non-nullable and creates default settings when missing.
        // @codeCoverageIgnoreStart
        if (!$twoFaSettings) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return $twoFaSettings->getBoolean('enabled');
    }

    /**
     * True if 2FA should be enforced for a user.
     */
    public static function shouldEnforce(User $user): bool
    {
        $systemEnforced  = static::isSystemEnforced();
        $companyEnforced = static::isCompanyEnforced($user->company);
        $userEnabled     = static::isEnabled($user);

        // A user who has already enabled 2FA is never re-prompted to enroll; otherwise we
        // enforce enrollment when the system or the user's company mandates it.
        return $userEnabled ? false : ($systemEnforced || $companyEnforced);
    }

    /**
     * Check if Two-Factor Authentication is enforced for company.
     *
     * @return bool true if Two-Factor Authentication is enforced, false otherwise
     */
    public static function isCompanyEnforced(Company $company): bool
    {
        $twoFaSettings = static::getTwoFaSettingsForCompany($company);

        if ($twoFaSettings) {
            return $twoFaSettings->getBoolean('enforced');
        }

        // getTwoFaSettingsForCompany() is non-nullable and creates default settings when missing.
        // @codeCoverageIgnoreStart
        return false;
        // @codeCoverageIgnoreEnd
    }

    /**
     * True if 2FA is enforced system wide.
     */
    public static function isSystemEnforced(): bool
    {
        $twoFaSettings = static::getTwoFaConfiguration();

        if (!$twoFaSettings) {
            return false;
        }

        return $twoFaSettings->getBoolean('enforced');
    }

    /**
     * Start a Two-Factor Authentication session.
     *
     * @param string|User $identity    the user identity or resolved user
     * @param int         $tokenLength the length of the generated token
     *
     * @return string|null the Two-Factor Authentication session key, or null on failure
     */
    public static function start(string|User $identity, int $tokenLength = 40): ?string
    {
        $user = $identity instanceof User ? $identity : static::getUserFromIdentity($identity);

        if ($user) {
            $token           = Str::random($tokenLength);
            $twoFaSessionKey = static::createTwoFaSessionKey($user, $token);

            return static::encryptSessionKey($twoFaSessionKey, $user->uuid);
        }

        return null;
    }

    /**
     * Verify a Two-Factor Authentication code and return a user token.
     *
     * @param string $code        the user-provided verification code
     * @param string $token       the Two-Factor Authentication token
     * @param string $clientToken the client session token
     *
     * @return string the user token
     *
     * @throws \Exception if verification code is invalid or expired, or session is invalid
     */
    public static function verifyCode(string $code, string $token, string $clientToken): string
    {
        if (static::isAuthenticatorClientToken($clientToken)) {
            return static::verifyAuthenticatorChallenge($code, $token, $clientToken);
        }

        // Get verification code from the client token
        $verificationCode = static::getVerificationCodeFromClientToken($clientToken);

        // If no verification code return null
        if (!$verificationCode) {
            throw new \Exception('Verification code is invalid.');
        }

        // If we have verification code then get user from it
        if ($verificationCode) {
            // Get user from verification code
            $user = static::getUserFromVerificationCode($verificationCode);

            // If no user found in the verification code
            if (!$user) {
                throw new \Exception('User not found for verification code.');
            }

            // Get the user identity
            $identity = $user->getIdentity();

            // Next we will validate the session token
            if (static::validateSessionToken($token, $identity, $clientToken)) {
                // Get the two factor session key
                $twoFaSessionKey = static::decryptSessionKey($token, $user->uuid);

                // If session key is valid
                if (static::isTwoFaSessionKeyValid($twoFaSessionKey, $user)) {
                    // Make sure verification code has not expired
                    if ($verificationCode->hasExpired()) {
                        // @codeCoverageIgnoreStart
                        // ExpiryScope filters expired verification codes before this guard is reachable.
                        throw new \Exception('Verification code has expired.');
                        // @codeCoverageIgnoreEnd
                    }

                    // Check if verification code matches user provided code
                    $verificationCodeMatches = hash_equals((string) $verificationCode->code, $code);
                    if ($verificationCodeMatches) {
                        // Kill the two fa session
                        Redis::del($twoFaSessionKey, static::attemptsKey($twoFaSessionKey));
                        static::logActivity($user, 'two_factor_verified', 'Two-factor sign-in verified', ['method' => 'code']);

                        // Authenticate the user
                        $token = $user->createToken($user->uuid);

                        return $token->plainTextToken;
                    }

                    static::recordFailedAttempt($twoFaSessionKey, $user);

                    throw new \Exception('Verification code does not match.');
                }
            }
        }

        throw new \Exception('Verification code is invalid.');
    }

    /**
     * Resend a Two-Factor Authentication verification code.
     *
     * @param string $identity the user identity
     * @param string $token    the Two-Factor Authentication token
     *
     * @return string the newly generated client session token
     *
     * @throws \Exception if no user found or the Two-Factor Authentication session is invalid
     */
    public static function resendCode(string $identity, string $token): string
    {
        $user = static::getUserFromIdentity($identity);
        if (!$user) {
            throw new \Exception('No user found using the provided identity');
        }

        // Make sure two factor session is valid
        if (!static::validateSessionToken($token, $identity)) {
            throw new \Exception('2FA session is invalid.');
        }

        // Send new verification code for user
        $verificationCode = static::sendVerificationCode($user);

        // Return with newly generated client session token for the new verification code
        return static::createClientSessionToken($verificationCode);
    }

    /**
     * Create a client session token for a verification code.
     *
     * @param VerificationCode $verificationCode the verification code
     * @param int              $expiresAfter     the expiration time for the client session token in seconds
     *
     * @return string the client session token
     */
    public static function createClientSessionToken(VerificationCode $verificationCode, int $expiresAfter = 61): string
    {
        $expiresAfter = Carbon::now()->addSeconds($expiresAfter);
        $clientToken  = base64_encode($expiresAfter . '|' . $verificationCode->uuid . '|' . Str::random());

        return $clientToken;
    }

    /**
     * Check if a Two-Factor Authentication session key is valid.
     *
     * @param string $twoFaSessionKey the Two-Factor Authentication session key
     * @param User   $user            the user the session key was created for
     *
     * @return bool true if the session key is valid, false otherwise
     */
    public static function isTwoFaSessionKeyValid(?string $twoFaSessionKey, User $user): bool
    {
        if (!$twoFaSessionKey) {
            return false;
        }

        $exists = Redis::exists($twoFaSessionKey);

        if ($exists) {
            $parts  = explode(':', $twoFaSessionKey);
            $userId = Arr::get($parts, 1);

            return Str::isUuid($userId) && $userId === $user->uuid;
        }

        return false;
    }

    /**
     * Forget the Two-Factor Authentication session based on the provided token and identity.
     *
     * @param string $token    the token associated with the Two-Factor Authentication session
     * @param string $identity The identity (e.g., username) of the user.
     *
     * @return bool returns true if the Two-Factor Authentication session was successfully forgotten,
     *              false otherwise
     *
     * @throws \Exception thrown when no user is found for the provided identity
     */
    public static function forgetTwoFaSession(string $token, string $identity): bool
    {
        $user = static::getUserFromIdentity($identity);
        if (!$user) {
            throw new \Exception('No user found for the identity provided.');
        }

        // Get session key and destroy it
        $twoFaSessionKey = static::decryptSessionKey($token, $user->uuid);

        return Redis::del($twoFaSessionKey);
    }

    /**
     * Whether the user signs in with an authenticator app they have confirmed.
     */
    public static function usesAuthenticatorApp(User $user): bool
    {
        return static::hasAuthenticatorApp($user)
            && static::getTwoFaSettingsForUser($user)->getValue('method') === static::METHOD_AUTHENTICATOR_APP;
    }

    /**
     * Whether the user has confirmed an authenticator app.
     */
    public static function hasAuthenticatorApp(User $user): bool
    {
        return !empty(static::getAuthenticatorRecord($user)['confirmed_at']);
    }

    /**
     * Describe the user's authenticator app, without any secrets.
     *
     * @return array{enabled: bool, confirmed_at: string|null, recovery_codes_remaining: int}
     */
    public static function getAuthenticatorStatus(User $user): array
    {
        $record = static::getAuthenticatorRecord($user);

        return [
            'enabled'                  => !empty($record['confirmed_at']),
            'confirmed_at'             => $record['confirmed_at'] ?? null,
            'recovery_codes_remaining' => count($record['recovery_codes'] ?? []),
        ];
    }

    /**
     * Start setting up an authenticator app. The new secret is kept aside until the user
     * confirms it with a code, so an app that is already set up keeps working until then.
     *
     * @return array{secret: string, otpauth_url: string, qr_code: string, expires_at: string}
     */
    public static function beginAuthenticatorEnrollment(User $user): array
    {
        $google2fa = static::google2fa();
        $secret    = $google2fa->generateSecretKey(32);
        $expiresAt = Carbon::now()->addSeconds(static::AUTHENTICATOR_ENROLLMENT_TTL);
        $issuer    = (string) config('app.name', 'Fleetbase');
        $account   = $user->email ?: ($user->phone ?: ($user->username ?: $user->uuid));
        $url       = $google2fa->getQRCodeUrl($issuer, $account, $secret);

        static::saveAuthenticatorRecord($user, array_merge(static::getAuthenticatorRecord($user), [
            'pending_secret'     => Crypt::encryptString($secret),
            'pending_expires_at' => $expiresAt->toIso8601String(),
        ]));

        return [
            'secret'      => $secret,
            'otpauth_url' => $url,
            'qr_code'     => Barcode::qrCodeDataUri($url),
            'expires_at'  => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Confirm a new authenticator app with a code from it, make it the user's 2FA method,
     * and issue new recovery codes.
     *
     * @return array<int, string> the recovery codes, which are only ever shown here
     *
     * @throws \Exception if there is no pending setup, it expired, or the code is wrong
     */
    public static function confirmAuthenticatorEnrollment(User $user, string $code): array
    {
        $record    = static::getAuthenticatorRecord($user);
        $expiresAt = $record['pending_expires_at'] ?? null;

        if (empty($record['pending_secret']) || !$expiresAt || Carbon::now()->gte(Carbon::parse($expiresAt))) {
            throw new \Exception('Authenticator setup has expired. Start again.');
        }

        // verifyKeyNewer() returns the matched time step only when given a previous one
        $secret   = Crypt::decryptString($record['pending_secret']);
        $timestep = static::google2fa()->verifyKeyNewer($secret, static::normalizeOtp($code), 0, static::AUTHENTICATOR_WINDOW);
        if ($timestep === false) {
            throw new \Exception('The code from your authenticator app is not correct.');
        }

        [$recoveryCodes, $hashes] = static::generateRecoveryCodes();

        static::saveAuthenticatorRecord($user, [
            'secret'         => Crypt::encryptString($secret),
            'confirmed_at'   => Carbon::now()->toIso8601String(),
            'last_timestep'  => $timestep,
            'recovery_codes' => $hashes,
        ]);
        static::saveTwoFaSettingsForUser($user, array_merge(static::getTwoFaSettingsForUser($user)->value ?? [], [
            'enabled' => true,
            'method'  => static::METHOD_AUTHENTICATOR_APP,
        ]));
        static::logActivity($user, 'authenticator_enabled', 'Authenticator app enabled');

        return $recoveryCodes;
    }

    /**
     * Remove the user's authenticator app. If it was their 2FA method, 2FA is turned off
     * so they are not locked out; they can turn it on again with another method.
     */
    public static function disableAuthenticatorApp(User $user): void
    {
        Setting::where('key', static::authenticatorKey($user))->delete();

        $settings = static::getTwoFaSettingsForUser($user)->value ?? [];
        if (($settings['method'] ?? null) === static::METHOD_AUTHENTICATOR_APP) {
            static::saveTwoFaSettingsForUser($user, array_merge($settings, ['enabled' => false, 'method' => 'email']));
        }

        static::logActivity($user, 'authenticator_disabled', 'Authenticator app disabled');
    }

    /**
     * Replace the user's recovery codes.
     *
     * @return array<int, string> the new recovery codes, which are only ever shown here
     *
     * @throws \Exception if the user has no authenticator app
     */
    public static function regenerateRecoveryCodes(User $user): array
    {
        $record = static::getAuthenticatorRecord($user);
        if (empty($record['confirmed_at'])) {
            throw new \Exception('Set up an authenticator app first.');
        }

        [$recoveryCodes, $hashes] = static::generateRecoveryCodes();
        $record['recovery_codes'] = $hashes;
        static::saveAuthenticatorRecord($user, $record);
        static::logActivity($user, 'recovery_codes_regenerated', 'Two-factor recovery codes regenerated');

        return $recoveryCodes;
    }

    /**
     * Check a code from the user's authenticator app, or one of their recovery codes.
     * Each authenticator code and each recovery code can only be used once.
     *
     * @return string|null `authenticator_app` or `recovery_code` for the kind of code that matched, or null
     */
    public static function verifyAuthenticatorCode(User $user, string $code): ?string
    {
        $record = static::getAuthenticatorRecord($user);
        if (empty($record['secret'])) {
            return null;
        }

        $otp = static::normalizeOtp($code);
        if (preg_match('/^\d{6}$/', $otp)) {
            $timestep = static::google2fa()->verifyKeyNewer(
                Crypt::decryptString($record['secret']),
                $otp,
                (int) ($record['last_timestep'] ?? 0),
                static::AUTHENTICATOR_WINDOW
            );

            if ($timestep === false) {
                return null;
            }

            $record['last_timestep'] = $timestep;
            static::saveAuthenticatorRecord($user, $record);

            return static::METHOD_AUTHENTICATOR_APP;
        }

        $hash  = static::hashRecoveryCode($code);
        $codes = $record['recovery_codes'] ?? [];
        foreach ($codes as $index => $storedHash) {
            if (hash_equals((string) $storedHash, $hash)) {
                unset($codes[$index]);
                $record['recovery_codes'] = array_values($codes);
                static::saveAuthenticatorRecord($user, $record);
                static::logActivity($user, 'recovery_code_used', 'Two-factor recovery code used', ['remaining' => count($codes)]);

                return 'recovery_code';
            }
        }

        return null;
    }

    /**
     * Verify an authenticator app challenge and return a user token.
     *
     * @throws \Exception if the challenge is invalid or the code is wrong
     */
    private static function verifyAuthenticatorChallenge(string $code, string $token, string $clientToken): string
    {
        $user = static::getUserFromAuthenticatorClientToken($clientToken);
        if (!$user) {
            throw new \Exception('Verification code is invalid.');
        }

        $twoFaSessionKey = static::decryptSessionKey($token, $user->uuid);
        if (!static::isTwoFaSessionKeyValid($twoFaSessionKey, $user)) {
            throw new \Exception('Verification code is invalid.');
        }

        $matched = static::verifyAuthenticatorCode($user, $code);
        if (!$matched) {
            static::recordFailedAttempt($twoFaSessionKey, $user);

            throw new \Exception('Verification code does not match.');
        }

        Redis::del($twoFaSessionKey, static::attemptsKey($twoFaSessionKey), static::authenticatorClientKey($clientToken));
        static::logActivity($user, 'two_factor_verified', 'Two-factor sign-in verified', ['method' => $matched]);

        return $user->createToken($user->uuid)->plainTextToken;
    }

    /**
     * Count a failed code against the 2FA session, which outlives any single code, so
     * resending codes does not reset the budget. Ends the session after too many failures.
     *
     * @throws \Exception when the session has been ended
     */
    private static function recordFailedAttempt(string $twoFaSessionKey, User $user): void
    {
        $attemptsKey = static::attemptsKey($twoFaSessionKey);
        $attempts    = (int) Redis::incr($attemptsKey);
        Redis::expire($attemptsKey, static::SESSION_TTL);

        if ($attempts >= static::MAX_VERIFY_ATTEMPTS) {
            Redis::del($twoFaSessionKey, $attemptsKey);
            static::logActivity($user, 'two_factor_locked', 'Two-factor sign-in stopped after too many wrong codes', ['attempts' => $attempts]);

            throw new \Exception('Too many failed verification attempts. Please sign in again.');
        }
    }

    /**
     * The method used to send a code to an authenticator app user who cannot use their app.
     */
    private static function fallbackMethod(User $user): string
    {
        return $user->email ? 'email' : 'sms';
    }

    /**
     * Create a client token for an authenticator app challenge. It points at the user
     * server side, so the token itself does not reveal who it is for.
     */
    private static function createAuthenticatorClientToken(User $user): string
    {
        $reference   = Str::random(40);
        $clientToken = base64_encode(Carbon::now()->addSeconds(static::SESSION_TTL) . '|' . static::AUTHENTICATOR_CLIENT_TOKEN . '|' . $reference);

        Redis::set(static::authenticatorClientKey($clientToken), $user->uuid, 'EX', static::SESSION_TTL);

        return $clientToken;
    }

    private static function isAuthenticatorClientToken(string $clientToken): bool
    {
        return (static::decodeClientToken($clientToken)[1] ?? null) === static::AUTHENTICATOR_CLIENT_TOKEN;
    }

    private static function getUserFromAuthenticatorClientToken(string $clientToken): ?User
    {
        $userUuid = Redis::get(static::authenticatorClientKey($clientToken));

        return $userUuid ? User::where('uuid', $userUuid)->first() : null;
    }

    private static function authenticatorClientKey(string $clientToken): string
    {
        return 'two_fa_client:' . (static::decodeClientToken($clientToken)[2] ?? '');
    }

    /**
     * Get the user's stored authenticator app record: the encrypted secret, when it was
     * confirmed, the last used time step, and hashed recovery codes.
     */
    private static function getAuthenticatorRecord(User $user): array
    {
        $record = Setting::lookup(static::authenticatorKey($user), []);

        return is_array($record) ? $record : [];
    }

    private static function saveAuthenticatorRecord(User $user, array $record): void
    {
        Setting::configure(static::authenticatorKey($user), $record);
    }

    private static function authenticatorKey(User $user): string
    {
        return 'user.' . $user->uuid . '.2fa_authenticator';
    }

    /**
     * Generate recovery codes like `k7d2m-9xq4p`, and the hashes that are stored.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function generateRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes    = [];

        for ($i = 0; $i < static::RECOVERY_CODE_COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }

        return [$codes, array_map([static::class, 'hashRecoveryCode'], $codes)];
    }

    /**
     * Recovery codes are random and single use, so a keyed SHA-256 hash is enough to store them.
     */
    private static function hashRecoveryCode(string $code): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code));

        return hash_hmac('sha256', $normalized, (string) config('app.key', ''));
    }

    private static function normalizeOtp(string $code): string
    {
        return (string) preg_replace('/\s+/', '', $code);
    }

    private static function google2fa(): Google2FA
    {
        return new Google2FA();
    }

    /**
     * Record a 2FA event in the `auth` activity log. Codes and secrets are never logged.
     */
    private static function logActivity(User $user, string $event, string $description, array $properties = []): void
    {
        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties($properties)
            ->event($event)
            ->log($description);
    }

    /**
     * Create a Two-Factor Authentication session key.
     *
     * @param User   $user         the user for whom the session key is created
     * @param string $token        the Two-Factor Authentication token
     * @param bool   $storeInCache whether to store the key in the cache
     * @param int    $expiresAfter the expiration time for the session key in seconds
     *
     * @return string the Two-Factor Authentication session key
     */
    private static function createTwoFaSessionKey(User $user, string $token, bool $storeInCache = true, int $expiresAfter = self::SESSION_TTL): string
    {
        $twoFaSessionKey = 'two_fa_session:' . $user->uuid . ':' . $token;

        if ($storeInCache) {
            // EX takes a TTL in seconds, not an absolute timestamp.
            Redis::set($twoFaSessionKey, $user->uuid, 'EX', $expiresAfter);
        }

        return $twoFaSessionKey;
    }

    /**
     * Redis key holding the failed verification attempts for a 2FA session.
     */
    private static function attemptsKey(string $twoFaSessionKey): string
    {
        return $twoFaSessionKey . ':attempts';
    }

    /**
     * Get a user based on the provided identity (email or phone).
     *
     * @param string $identity the user identity (email or phone)
     *
     * @return User|null the user, or null if not found
     */
    private static function getUserFromIdentity(string $identity): ?User
    {
        return User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity);
        })->first();
    }

    /**
     * Get a user based on the provided verification code.
     *
     * @param VerificationCode|null $verificationCode the verification code
     *
     * @return User|null the user, or null if not found
     */
    private static function getUserFromVerificationCode(?VerificationCode $verificationCode = null): ?User
    {
        if ($verificationCode instanceof VerificationCode) {
            $subject = $verificationCode->subject;

            if ($subject instanceof User) {
                return $subject;
            }
        }

        return null;
    }

    /**
     * Decode a client session token.
     *
     * @param string $clientToken the client session token
     *
     * @return array the decoded client session token parts
     */
    private static function decodeClientToken(string $clientToken): array
    {
        $clientTokenDecoded = base64_decode($clientToken);
        $clientTokenParts   = explode('|', $clientTokenDecoded);

        return $clientTokenParts;
    }

    /**
     * Get a verification code based on the provided client session token.
     *
     * @param string $clientToken the client session token
     *
     * @return VerificationCode|null the verification code, or null if not found
     */
    private static function getVerificationCodeFromClientToken(string $clientToken): ?VerificationCode
    {
        $clientTokenParts   = static::decodeClientToken($clientToken);
        $verificationCodeId = $clientTokenParts[1];

        if ($verificationCodeId) {
            $verificationCode = VerificationCode::where('uuid', $verificationCodeId)->first();

            if ($verificationCode) {
                return $verificationCode;
            }
        }

        return null;
    }

    /**
     * Encrypts the session key using AES-256-CBC encryption with an initialization vector (IV).
     *
     * @param mixed  $data the data to be encrypted
     * @param string $key  the encryption key
     *
     * @return string the base64-encoded result of encrypting the data
     */
    private static function encryptSessionKey($data, string $key): ?string
    {
        // Encrypt the data
        $ivLength  = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false) {
            // @codeCoverageIgnoreStart
            // Valid aes-256-cbc cipher support is required by PHP/OpenSSL in this runtime.
            return null;
            // @codeCoverageIgnoreEnd
        }
        $iv        = openssl_random_pseudo_bytes($ivLength);
        if ($iv === false) {
            // @codeCoverageIgnoreStart
            // OpenSSL random byte generation cannot be deterministically forced here.
            return null;
            // @codeCoverageIgnoreEnd
        }
        $encrypted = openssl_encrypt(gzcompress($data), 'aes-256-cbc', $key, 0, $iv);
        if ($encrypted === false) {
            // @codeCoverageIgnoreStart
            // Valid cipher/key inputs are generated internally before this guard.
            return null;
            // @codeCoverageIgnoreEnd
        }

        // Combine IV and encrypted data
        $result = $iv . $encrypted;

        return base64_encode($result);
    }

    /**
     * Decrypts the encrypted session key using AES-256-CBC decryption with an initialization vector (IV).
     *
     * @param string $encrypted the base64-encoded encrypted data
     * @param string $key       the decryption key
     *
     * @return mixed the decrypted and decompressed original data
     */
    private static function decryptSessionKey(string $encrypted, string $key): ?string
    {
        // Decode from base64
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            return null;
        }

        // Extract IV and encrypted data
        $ivLength      = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false || strlen($data) <= $ivLength) {
            return null;
        }
        $iv            = substr($data, 0, $ivLength);
        $encryptedData = substr($data, $ivLength);

        // Decrypt and decompress
        $decrypted = openssl_decrypt($encryptedData, 'aes-256-cbc', $key, 0, $iv);
        if ($decrypted === false) {
            return null;
        }

        $decompressed = gzuncompress($decrypted);
        if ($decompressed === false) {
            return null;
        }

        return $decompressed;
    }
}
