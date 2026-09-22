<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Attributes\SkipAuthorizationCheck;
use Fleetbase\Events\UserRemovedFromCompany;
use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Exports\UserExport;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\CreateUserRequest;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\AcceptCompanyInvite;
use Fleetbase\Http\Requests\Internal\ChangeCurrentUserEmailRequest;
use Fleetbase\Http\Requests\Internal\ChangeUserEmailRequest;
use Fleetbase\Http\Requests\Internal\InviteUserRequest;
use Fleetbase\Http\Requests\Internal\ResendUserInvite;
use Fleetbase\Http\Requests\Internal\UpdatePasswordRequest;
use Fleetbase\Http\Requests\Internal\ValidatePasswordRequest;
use Fleetbase\Http\Requests\UpdateUserRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Invite;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\UserAcceptedCompanyInvite;
use Fleetbase\Notifications\UserContactVerificationRequested;
use Fleetbase\Notifications\UserEmailChange;
use Fleetbase\Notifications\UserInvited;
use Fleetbase\Services\SmsService;
use Fleetbase\Services\UserCacheService;
use Fleetbase\Support\Auth;
use Fleetbase\Support\NotificationRegistry;
use Fleetbase\Support\TwoFactorAuth;
use Fleetbase\Support\Utils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'user';

    /**
     * The service which this controller belongs to.
     *
     * @var string
     */
    public $service = 'iam';

    /**
     * Create user request.
     *
     * @var CreateUserRequest
     */
    public $createRequest = CreateUserRequest::class;

    /**
     * Update user request.
     *
     * Enforces that email and phone cannot be set to an empty string
     * and that uniqueness constraints are respected on update.
     *
     * @var UpdateUserRequest
     */
    public $updateRequest = UpdateUserRequest::class;

    /**
     * Query users always against the production database.
     *
     * Users are authoritative in production. The sandbox database contains
     * only a mirrored copy. Temporarily restoring the production connection
     * for this query ensures the IAM list is correct regardless of whether
     * the console is in sandbox mode, without affecting any other sandbox
     * queries in the same request lifecycle.
     *
     * @return \Illuminate\Http\Response
     */
    public function queryRecord(Request $request)
    {
        $isSandbox = config('fleetbase.connection.db') === 'sandbox';

        if ($isSandbox) {
            config([
                'database.default'        => env('DB_CONNECTION', 'mysql'),
                'fleetbase.connection.db' => env('DB_CONNECTION', 'mysql'),
            ]);
        }

        $response = parent::queryRecord($request);

        if ($isSandbox) {
            config([
                'database.default'        => 'sandbox',
                'fleetbase.connection.db' => 'sandbox',
            ]);
        }

        return $response;
    }

    /**
     * Scope generic user list requests to users joined to the current company.
     */
    public function onQueryRecord($query, Request $request): void
    {
        // Eager-load what the `role` / `roles` / `policies` / `permissions` accessors
        // read. CompanyUserRelation matches each user's own company membership,
        // so authorization relations are fetched in batches across the page.
        $query->with(['companyUser.roles', 'companyUser.policies', 'companyUser.permissions']);

        if ($this->canAccessUsersAcrossCompanies($request)) {
            return;
        }

        $companyUuid = session('company');
        if (!$companyUuid) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('companyUsers', function ($companyUserQuery) use ($companyUuid) {
            $companyUserQuery->where('company_uuid', $companyUuid);
        });
    }

    /**
     * Find a user visible to the current session company.
     *
     * @return \Illuminate\Http\Response|array
     */
    public function findRecord(Request $request, $id)
    {
        $record = $this->resolveVisibleUser($id, $request);

        if ($record) {
            return [$this->resourceSingularlName => new $this->resource($record)];
        }

        return response()->error('User not found', 404);
    }

    /**
     * Creates a record with request payload.
     *
     * If the supplied email address already belongs to an existing user the
     * request is treated as a cross-organisation invitation rather than a
     * duplicate-creation attempt. The existing user is invited to join the
     * current company and the response includes `invited: true` so the
     * frontend can display the appropriate success message.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        // A role is always chosen explicitly: access is never granted by default.
        $role = $this->resolveRequestedRole($request);
        if ($role instanceof \Illuminate\Http\JsonResponse) {
            return $role;
        }

        // A driver/customer/contact account in this organisation with the same
        // email or phone is promoted to a team member instead of duplicated.
        $managedUser = $this->findPromotableAccount($request->input('user.email'), $request->input('user.phone'));
        if ($managedUser) {
            return $this->promoteManagedAccount($managedUser, $request, $role);
        }

        $this->validateRequest($request);

        // Detect whether the email already belongs to an existing user.
        // If so, redirect to the cross-organisation invite flow instead of
        // attempting to create a duplicate user record.
        $email        = strtolower((string) $request->input('user.email', ''));
        $existingUser = $email ? User::where('email', $email)->whereNull('deleted_at')->first() : null;

        if ($existingUser) {
            // Guard: the user is already a member of the current organisation.
            $alreadyMember = $existingUser->companyUsers()
                ->where('company_uuid', session('company'))
                ->exists();

            if ($alreadyMember) {
                return response()->error('This user is already a member of your organisation.');
            }

            return $this->inviteExistingUser($existingUser, $request, $role);
        }

        try {
            $record = $this->model->createRecordFromRequest($request, function (&$request, &$input) {
                // Get user properties
                $name        = $request->input('user.name');
                $timezone    = $request->or(['timezone', 'user.timezone'], date_default_timezone_get());
                $username    = Str::slug($name . '_' . Str::random(4), '_');

                // Prepare user attributes
                $input = User::applyUserInfoFromRequest($request, array_merge($input, [
                    'company_uuid' => session('company'),
                    'name'         => $name,
                    'username'     => $username,
                    'ip_address'   => $request->ip(),
                    'timezone'     => $timezone,
                ]));
            }, function (&$request, &$user) use ($role) {
                // Make sure to assign to current company
                $company = Auth::getCompany();

                // Set user type
                $user->setUserType('user');

                // Assign to company with the chosen role
                $user->assignCompany($company, $role->id);

                // Sync Permissions
                if ($request->isArray('user.permissions')) {
                    $permissions = Permission::whereIn('id', $request->array('user.permissions'))->get();
                    $user->syncPermissions($permissions);
                }

                // Sync Policies
                if ($request->isArray('user.policies')) {
                    $policies = $this->getAssignablePolicies($request->array('user.policies'));
                    $user->syncPolicies($policies);
                }
            });

            return ['user' => new $this->resource($record)];
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     * Updates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateRecord(Request $request, string $id)
    {
        $record = $this->resolveVisibleUser($id, $request);

        if (!$record) {
            return response()->error('User not found.', 404);
        }

        if (!$this->stripUnchangedIdentityFields($request, $record)) {
            return response()->error('Login identity fields cannot be updated from this endpoint.', 422);
        }

        // Run the UpdateUserRequest validation rules before delegating to the
        // model trait. This prevents email/phone being set to an empty string
        // and enforces uniqueness constraints on partial (PATCH) updates.
        $this->validateRequest($request);

        try {
            $input = $this->model->getApiPayloadFromRequest($request);
            $input = $this->model->fillSessionAttributes($input, [], ['updated_by_uuid']);

            if ($this->model->isColumn('slug')) {
                unset($input['slug']);
            }

            foreach (array_keys($input) as $key) {
                if ($this->model->isInvalidUpdateParam($key)) {
                    throw new \Exception('Invalid param "' . $key . '" in update request!');
                }
            }

            $roleToAssign = null;
            if ($request->filled('user.role')) {
                $roleToAssign = $this->resolveAssignableRole($request->input('user.role'));
                if (!$roleToAssign) {
                    return response()->error('The selected role is not available for this organisation.', 404);
                }

                if ($denied = $this->denyRoleGrant($roleToAssign, $request)) {
                    return $denied;
                }
            }

            $record->update(Arr::except($input, ['uuid', 'public_id', 'deleted_at', 'updated_at', 'created_at']));

            // Assign role if set
            if ($roleToAssign) {
                $record->assignSingleRole($roleToAssign);
            }

            // Sync Permissions
            if ($request->isArray('user.permissions')) {
                $permissions = Permission::whereIn('id', $request->array('user.permissions'))->get();
                $record->syncPermissions($permissions);
            }

            // Sync Policies
            if ($request->isArray('user.policies')) {
                $policies = $this->getAssignablePolicies($request->array('user.policies'));
                $record->syncPolicies($policies);
            }

            $record = $record->refresh();

            return ['user' => new $this->resource($record)];
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     * Disable the generic user delete endpoint for org-scoped callers.
     *
     * User removal from an organization must use the dedicated
     * remove-from-company action so multi-organization users are not globally
     * deleted by accident.
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteRecord($id, Request $request)
    {
        if (!$this->resolveVisibleUser($id, $request)) {
            return response()->error('User not found', 404);
        }

        return response()->error('Use the remove-from-company endpoint to remove users from an organization.', 403);
    }

    private function resolveVisibleUser(string $id, Request $request): ?User
    {
        $query = User::where(function ($query) use ($id) {
            $query->where('uuid', $id)
                ->orWhere('public_id', $id);
        });

        if (!$this->canAccessUsersAcrossCompanies($request)) {
            $companyUuid = session('company');
            if (!$companyUuid) {
                return null;
            }

            $query->whereHas('companyUsers', function ($companyUserQuery) use ($companyUuid) {
                $companyUserQuery->where('company_uuid', $companyUuid);
            });
        }

        $query = $this->model->withCounts($request, $query);
        $query = $this->model->withRelationships($request, $query);
        $query = $this->model->applyDirectivesToQuery($request, $query);

        return $query->first();
    }

    private function canAccessUsersAcrossCompanies(Request $request): bool
    {
        $user = Auth::getUserFromSession($request) ?? $request->user();

        return $user instanceof User && $user->isAdmin();
    }

    private function stripUnchangedIdentityFields(Request $request, User $user): bool
    {
        $payload = $this->model->getApiPayloadFromRequest($request);

        $identityFields = [
            'email',
            'email_verified_at',
            'password',
            'password_confirmation',
            'remember_token',
            'secret',
            'type',
            'apple_user_id',
            'facebook_user_id',
            'google_user_id',
        ];

        foreach ($identityFields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if (!$this->identityValueMatches($field, $payload[$field], $user->getAttribute($field))) {
                return false;
            }

            unset($payload[$field]);
        }

        if ($request->has($this->model->getSingularName())) {
            $request->merge([$this->model->getSingularName() => $payload]);
        } elseif ($request->has(Str::camel($this->model->getSingularName()))) {
            $request->merge([Str::camel($this->model->getSingularName()) => $payload]);
        } else {
            $request->replace($payload);
        }

        return true;
    }

    private function identityValueMatches(string $field, mixed $incoming, mixed $current): bool
    {
        if ($field === 'email') {
            return strtolower((string) $incoming) === strtolower((string) $current);
        }

        if (str_ends_with($field, '_at')) {
            if ($incoming === null && $current === null) {
                return true;
            }

            if (!$incoming || !$current) {
                return false;
            }

            try {
                return Carbon::parse($incoming)->equalTo(Carbon::parse($current));
            } catch (\Exception $e) {
                return false;
            }
        }

        return (string) $incoming === (string) $current;
    }

    /**
     * Resolve a role assignable by the active company.
     */
    /**
     * Resolve the role a new or invited user joins with. A role is required and
     * must be assignable by the current user.
     */
    private function resolveRequestedRole(Request $request): Role|\Illuminate\Http\JsonResponse
    {
        $roleIdentifier = $request->input('user.role_uuid') ?? $request->input('user.role');
        if (is_array($roleIdentifier)) {
            $roleIdentifier = data_get($roleIdentifier, 'id') ?? data_get($roleIdentifier, 'uuid');
        }

        if (!$roleIdentifier) {
            return response()->error('Select a role for this user.', 422);
        }

        $role = $this->resolveAssignableRole($roleIdentifier);
        if (!$role) {
            return response()->error('The selected role is not available for this organisation.', 404);
        }

        return $this->denyRoleGrant($role, $request) ?? $role;
    }

    /**
     * Only administrators may grant the Administrator role.
     */
    private function denyRoleGrant(Role $role, Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (Auth::canGrantRole($role, Auth::getUserFromSession($request))) {
            return null;
        }

        return response()->error('Only administrators can grant the Administrator role.', 403);
    }

    private function resolveAssignableRole(string|Role|null $role, ?string $companyUuid = null): ?Role
    {
        $companyUuid ??= session('company');

        if (!$role) {
            return null;
        }

        if ($role instanceof Role) {
            return $this->roleBelongsToCompany($role, $companyUuid) ? $role : null;
        }

        $query = Role::where(function (Builder $query) use ($role) {
            $query->where('id', $role)->orWhere('name', $role);
        });

        $query->where(function (Builder $query) use ($companyUuid) {
            $query->where('company_uuid', $companyUuid)
                ->orWhereNull('company_uuid');
        });

        return $query->first();
    }

    /**
     * Resolve policies assignable by the active company.
     */
    private function getAssignablePolicies(array $ids)
    {
        return Policy::whereIn('id', $ids)
            ->where(function (Builder $query) {
                $query->where('company_uuid', session('company'))
                    ->orWhereNull('company_uuid');
            })
            ->get();
    }

    private function roleBelongsToCompany(Role $role, ?string $companyUuid): bool
    {
        return empty($role->company_uuid) || $role->company_uuid === $companyUuid;
    }

    /**
     * Request an email change for a user in the current organisation.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function changeEmail(ChangeUserEmailRequest $request, string $id)
    {
        $actor = Auth::getUserFromSession($request);
        if (!$actor) {
            return response()->error('Not authorized to change user email.', 401);
        }

        $canChangeEmail = $actor->isAdmin() || $actor->hasRole('Administrator') || $actor->hasPermissionTo('iam change-email-for user');
        if (!$canChangeEmail) {
            return response()->error('Not authorized to change user email.', 401);
        }

        $targetUser = User::where('uuid', $id)
            ->whereHas('anyCompanyUser', function ($query) {
                $query->where('company_uuid', session('company'));
            })
            ->first();

        if (!$targetUser) {
            return response()->error('User not found to change email for.', 404);
        }

        $newEmail = strtolower((string) $request->input('email'));
        $oldEmail = strtolower((string) $targetUser->email);

        if ($newEmail === $oldEmail) {
            return response()->error('The new email address must be different from the current email address.');
        }

        $this->sendEmailChangeVerification($targetUser, $actor, $newEmail, $oldEmail);

        return response()->json([
            'status' => 'pending',
        ]);
    }

    /**
     * Request an email change for the current user.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function changeCurrentUserEmail(ChangeCurrentUserEmailRequest $request)
    {
        $user = Auth::getUserFromSession($request);
        if (!$user) {
            return response()->error('No user session found', 401);
        }

        $newEmail = strtolower((string) $request->input('email'));
        $oldEmail = strtolower((string) $user->email);

        if ($newEmail === $oldEmail) {
            return response()->error('The new email address must be different from the current email address.');
        }

        $this->sendEmailChangeVerification($user, $user, $newEmail, $oldEmail);

        return response()->json([
            'status' => 'pending',
        ]);
    }

    /**
     * Send an email change verification link to a pending new email address.
     */
    protected function sendEmailChangeVerification(User $targetUser, User $actor, string $newEmail, string $oldEmail): VerificationCode
    {
        VerificationCode::where('subject_uuid', $targetUser->uuid)
            ->where('for', 'email_change')
            ->where('status', 'active')
            ->delete();

        $verificationCode = VerificationCode::create([
            'subject_uuid' => $targetUser->uuid,
            'subject_type' => Utils::getModelClassName($targetUser),
            'for'          => 'email_change',
            'expires_at'   => Carbon::now()->addHour(),
            'meta'         => [
                'old_email'         => $oldEmail,
                'new_email'         => $newEmail,
                'requested_by_uuid' => $actor->uuid,
            ],
            'status'       => 'active',
        ]);

        (new AnonymousNotifiable())
            ->route('mail', $newEmail)
            ->notify(new UserEmailChange($verificationCode));

        return $verificationCode;
    }

    /**
     * Responds with the currently authenticated user.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function current(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->error('No user session found', 401);
        }

        // Check if caching is enabled
        if (!UserCacheService::isEnabled()) {
            return response()->json([
                'user' => new $this->resource($user),
            ]);
        }

        // Generate ETag for cache validation
        $etag = UserCacheService::generateETag($user);

        // Try to get from server cache
        $companyId  = session('company');
        $cachedData = UserCacheService::get($user, $companyId);

        if ($cachedData) {
            // Return cached data with cache headers
            return response()->json(['user' => $cachedData])
                ->setEtag($etag)
                ->setLastModified($user->updated_at)
                ->header('Cache-Control', 'private, no-cache, must-revalidate')
                ->header('X-Cache-Hit', 'true');
        }

        // Cache miss - load fresh data with eager loading
        // Note: role, policies, permissions are accessors that use companyUser relationship
        $user->loadCompanyUser();

        // Transform to resource
        $userData  = new $this->resource($user);
        $userArray = $userData->toArray($request);

        // Store in cache
        UserCacheService::put($user, $companyId, $userArray);

        // Return with cache headers
        return response()->json(['user' => $userArray])
            ->setEtag($etag)
            ->setLastModified($user->updated_at)
            ->header('Cache-Control', 'private, no-cache, must-revalidate')
            ->header('X-Cache-Hit', 'false');
    }

    /**
     * Get the current user's two factor authentication settings.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function getTwoFactorSettings(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->error('No user session found', 401);
        }

        $twoFaSettings = TwoFactorAuth::getTwoFaSettingsForUser($user);

        return response()->json($twoFaSettings->value);
    }

    /**
     * Save the current user's two factor authentication settings.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function saveTwoFactorSettings(Request $request)
    {
        $twoFaSettings = $request->array('twoFaSettings');
        $user          = $request->user();

        if (!$user) {
            return response()->error('No user session found', 401);
        }

        $twoFaSettings = TwoFactorAuth::saveTwoFaSettingsForUser($user, $twoFaSettings);

        return response()->json($twoFaSettings->value);
    }

    /**
     * Invite a user (new or existing) to join the current organisation.
     *
     * - If the email belongs to an existing user in another organisation, a
     *   cross-organisation invitation is issued without creating a new user.
     * - If the email is brand-new, a pending user record is created and the
     *   invitation email is sent so they can set a password on acceptance.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function inviteUser(InviteUserRequest $request)
    {
        $data    = $request->input('user');
        $email   = strtolower($data['email']);
        $company = Auth::getCompany();

        if (!$company) {
            return response()->error('Unable to determine the current organisation.');
        }

        // A role is always chosen explicitly: access is never granted by default.
        $role = $this->resolveRequestedRole($request);
        if ($role instanceof \Illuminate\Http\JsonResponse) {
            return $role;
        }

        // A driver/customer/contact account in this organisation with the same
        // email is promoted to a team member instead of invited.
        $managedUser = $this->findPromotableAccount($email, data_get($data, 'phone'));
        if ($managedUser) {
            return $this->promoteManagedAccount($managedUser, $request, $role);
        }

        // Check if user already exists in the system.
        $user = User::where('email', $email)->whereNull('deleted_at')->first();

        if ($user) {
            // Guard: already a member of this organisation.
            $alreadyMember = $user->companyUsers()
                ->where('company_uuid', $company->uuid)
                ->exists();

            if ($alreadyMember) {
                return response()->error('This user is already a member of your organisation.');
            }

            // Existing user from another org — issue a cross-org invite.
            return $this->inviteExistingUser($user, $request, $role);
        }

        // Brand-new user — create a pending record; assignCompany() below issues the join invite + notification.
        $data['company_uuid'] = $company->uuid;
        $data['status']       = 'pending';
        $data['type']         = 'user';
        $data['created_at']   = Carbon::now();

        $user = User::create($data);

        // Set user type
        $user->setUserType('user');

        // Assign to company with the chosen role
        $user->assignCompany($company, $role->id);

        return response()->json(['user' => new $this->resource($user)]);
    }

    /**
     * Find a profile-managed account (driver, customer or contact) in the
     * current organisation matching the given email or phone.
     */
    private function findPromotableAccount(?string $email, ?string $phone): ?User
    {
        $email = $email ? strtolower(trim($email)) : null;
        $phone = $phone ? trim($phone) : null;
        if (!$email && !$phone) {
            return null;
        }

        return User::managed()
            ->where(function ($query) use ($email, $phone) {
                if ($email) {
                    $query->orWhere('email', $email);
                }
                if ($phone) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->whereHas('companyUsers', function ($query) {
                $query->where('company_uuid', session('company'));
            })
            ->first();
    }

    /**
     * Promote a profile-managed account to a team member of the current
     * organisation. Its driver/customer profiles stay linked, so the person
     * keeps a single account for the console and their app. A join invite is
     * sent so they can set a console password.
     */
    private function promoteManagedAccount(User $user, Request $request, Role $role): \Illuminate\Http\JsonResponse
    {
        $company = Auth::getCompany();
        if (!$company) {
            return response()->error('Unable to determine the current organisation.');
        }

        $previousType = $user->getType();
        $user->meta   = array_merge((array) ($user->meta ?? []), ['promoted_from' => $previousType]);
        $user->setUserType('user');
        $user->assignSingleRole($role);

        if ($request->isArray('user.permissions')) {
            $user->syncPermissions(Permission::whereIn('id', $request->array('user.permissions'))->get());
        }

        if ($request->isArray('user.policies')) {
            $user->syncPolicies($this->getAssignablePolicies($request->array('user.policies')));
        }

        if ($user->email && !Invite::isAlreadySentToJoinCompany($user, $company)) {
            $invitation = Invite::create([
                'company_uuid'    => $company->uuid,
                'created_by_uuid' => session('user'),
                'subject_uuid'    => $company->uuid,
                'subject_type'    => Utils::getMutationType($company),
                'protocol'        => 'email',
                'recipients'      => [$user->email],
                'reason'          => 'join_company',
                'meta'            => array_filter(['role_uuid' => $role->id, 'promoted_from' => $previousType]),
                'expires_at'      => now()->addHours(48),
            ]);

            $user->notify(new UserInvited($invitation));
        }

        UserCacheService::invalidateUser($user);

        return response()->json([
            'user'          => new $this->resource($user),
            'promoted_from' => $previousType,
        ]);
    }

    /**
     * Issue a join-company invitation to a user who already exists in the
     * system but belongs to a different organisation.
     *
     * This private helper is shared by both `createRecord()` (which detects
     * an existing email during the standard "New User" flow) and `inviteUser()`
     * (the dedicated invite endpoint). Keeping the logic in one place ensures
     * both paths behave identically.
     *
     * @param User    $user    the existing user to invite
     * @param Request $request the originating HTTP request
     * @param Role    $role    the role the user joins with
     */
    private function inviteExistingUser(User $user, Request $request, Role $role): \Illuminate\Http\JsonResponse
    {
        $company = Auth::getCompany();

        if (!$company) {
            return response()->error('Unable to determine the current organisation.');
        }

        // Guard: prevent duplicate invitations using the model helper.
        if (Invite::isAlreadySentToJoinCompany($user, $company)) {
            return response()->error('This user has already been invited to join your organisation.');
        }

        $invitation = Invite::create([
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => session('user'),
            'subject_uuid'    => $company->uuid,
            'subject_type'    => Utils::getMutationType($company),
            'protocol'        => 'email',
            'recipients'      => [$user->email],
            'reason'          => 'join_company',
            'meta'            => ['role_uuid' => $role->id],
            'expires_at'      => now()->addHours(48),
        ]);

        $user->notify(new UserInvited($invitation));

        // Return `invited: true` so the frontend can distinguish between
        // a newly created user and a cross-organisation invite.
        return response()->json([
            'user'    => new $this->resource($user),
            'invited' => true,
        ]);
    }

    /**
     * Resend invitation to pending user.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function resendInvitation(ResendUserInvite $request)
    {
        $user    = User::where('uuid', $request->input('user'))->first();
        $company = Company::where('uuid', session('company'))->first();

        if (!$user || !$company || !$this->canResendInvitationForCompany($user, $company)) {
            return response()->error('Unable to resend invitation.', 404);
        }

        // create invitation
        $invitation = Invite::create([
            'company_uuid'    => session('company'),
            'created_by_uuid' => session('user'),
            'subject_uuid'    => $company->uuid,
            'subject_type'    => Utils::getMutationType($company),
            'protocol'        => 'email',
            'recipients'      => [$user->email],
            'reason'          => 'join_company',
            'expires_at'      => now()->addHours(48),
        ]);

        // notify user
        $user->notify(new UserInvited($invitation));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Accept invitation to join a company/organization.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function acceptCompanyInvite(AcceptCompanyInvite $request)
    {
        $invite = $this->findCompanyInvite($request->input('code'));
        if (!$invite) {
            return response()->error('This invitation has already been accepted or is no longer available.');
        }

        // get invited email
        $email = Arr::first($invite->recipients);
        if (!$email) {
            return response()->error('Unable to locate the user for this invitation.');
        }

        // get user from invite
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->error('Unable to locate the user for this invitation.');
        }

        // get the company who sent the invite
        $company = $invite->subject;
        if (!$company) {
            return response()->error('The organization that invited you no longer exists.');
        }

        // determine if user needs to set password (when status pending)
        $isPending = $needsPassword = $user->status === 'pending';

        // Invites come from IAM, so accepting one makes a profile-managed
        // account (driver, customer, contact) a team member. Its password was
        // generated for the app, so it sets a console password.
        if ($user->isManagedAccount() || $invite->getMeta('promoted_from')) {
            $user->setUserType('user');
            $needsPassword = true;
        }

        // Add user to company only if they are not already a member.
        // This guards against double-acceptance (e.g. clicking the invite
        // link twice) creating a duplicate company_users row.
        $alreadyMember = CompanyUser::where('user_uuid', $user->uuid)
            ->where('company_uuid', $company->uuid)
            ->exists();

        if (!$alreadyMember) {
            // Use Company::addUser() so that role assignment is handled in
            // one place. The user joins with the role stored in the invite; an
            // invite without one (issued before roles were required) joins with
            // no role, so access is never granted by default.
            $role        = $this->resolveAssignableRole($invite->getMeta('role_uuid'), $company->uuid);
            $companyUser = $company->addUser($user, $role?->id);
            $user->setRelation('companyUser', $companyUser);
        } else {
            // User is already a member — ensure the companyUser relation is
            // loaded so that role assignment below can still be applied if
            // the invite carries a role (e.g. re-sent invite with a new role).
            $user->loadCompanyUser();
            $role = $this->resolveAssignableRole($invite->getMeta('role_uuid'), $company->uuid);
            if ($user->companyUser && $role) {
                $user->companyUser->assignSingleRole($role);
            }
        }

        // Delete the invite
        $invite->delete();

        // Switch the user's active company to the one they just joined.
        // This ensures that subsequent calls to /users/me resolve the
        // companyUser relationship (and therefore role/policies) against
        // the correct company rather than the user's previous company.
        $user->company_uuid = $company->uuid;
        $user->save();

        // activate user
        if ($isPending) {
            $user->update(['email_verified_at' => now()]);
            $user->activate();
        }

        // create authentication token for user
        $token = $user->createToken($invite->code);

        // Notify company that user has accepted their invite
        NotificationRegistry::notify(UserAcceptedCompanyInvite::class, $company, $user);

        return response()->json([
            'status'         => 'ok',
            'token'          => $token->plainTextToken,
            'needs_password' => $needsPassword,
        ]);
    }

    protected function findCompanyInvite(string $code): ?Invite
    {
        return Invite::where('code', $code)->with(['subject'])->first();
    }

    private function canResendInvitationForCompany(User $user, Company $company): bool
    {
        $isMember = $user->companyUsers()
            ->where('company_uuid', $company->uuid)
            ->exists();

        if ($isMember) {
            return true;
        }

        return Invite::isAlreadySentToJoinCompany($user, $company);
    }

    /**
     * Deactivates a user.
     *
     * @return \Illuminate\Http\Response
     */
    public function deactivate($id)
    {
        if (!$id) {
            return response()->error('No user to deactivate', 401);
        }

        $currentUser = request()->user();

        // Scope the lookup to the current company to prevent cross-organization IDOR.
        $user = User::where('uuid', $id)
            ->whereHas('companyUsers', function ($query) {
                $query->where('company_uuid', session('company'));
            })
            ->first();

        if (!$user) {
            return response()->error('No user found', 404);
        }

        // Prevent a user from deactivating their own account via this endpoint.
        if ($currentUser && $currentUser->uuid === $user->uuid) {
            return response()->error('You cannot deactivate your own account.', 403);
        }

        // Layered privilege check:
        //
        // Tier 1 — System admins (isAdmin()) can deactivate anyone except other
        //           system admins.  This is the highest privilege tier.
        if ($user->isAdmin()) {
            return response()->error('Insufficient permissions to deactivate this user.', 403);
        }

        // Tier 2 — Users holding the 'Administrator' role can only be deactivated
        //           by a system admin (handled above).  A regular user or another
        //           role-based Administrator cannot deactivate them.
        if ($user->hasRole('Administrator') && $currentUser && !$currentUser->isAdmin()) {
            return response()->error('Insufficient permissions to deactivate this user.', 403);
        }

        // Only deactivate the CompanyUser record for the current organisation.
        // Calling User::deactivate() would set the user's global status to
        // 'inactive', locking them out of every organisation they belong to.
        // Instead we update only the pivot record so the user remains active
        // in any other organisations they are a member of.
        $companyUser = $user->companyUsers()->where('company_uuid', session('company'))->first();

        // @codeCoverageIgnoreStart
        // The preceding whereHas(companyUsers...) lookup already rejects users outside the active organisation.
        if (!$companyUser) {
            return response()->error('User is not a member of this organisation.', 404);
        }
        // @codeCoverageIgnoreEnd

        $companyUser->status = 'inactive';
        $companyUser->save();

        return response()->json([
            'message' => 'User deactivated',
            'status'  => $companyUser->status,
        ]);
    }

    /**
     * Activates/re-activates a user.
     *
     * @return \Illuminate\Http\Response
     */
    public function activate($id)
    {
        if (!$id) {
            return response()->error('No user to activate', 401);
        }

        $currentUser = request()->user();

        // Scope the lookup to the current company to prevent cross-organisation IDOR.
        $user = User::where('uuid', $id)
            ->whereHas('companyUsers', function ($query) {
                $query->where('company_uuid', session('company'));
            })
            ->first();

        if (!$user) {
            return response()->error('No user found', 404);
        }

        // Activate both the User record and the CompanyUser record.
        // Unlike deactivation (which is scoped to the current organisation only),
        // activation must also update users.status because a newly created user
        // starts with a global status of 'inactive' and needs to be unblocked
        // at the user level before they can access any organisation.
        $user->activate();
        $user = $user->refresh();

        return response()->json([
            'message' => 'User activated',
            'status'  => $user->session_status,
        ]);
    }

    /**
     * Verify a user manually.
     *
     * @return \Illuminate\Http\Response
     */
    public function verify($id)
    {
        if (!$id) {
            return response()->error('No user to activate', 401);
        }

        $user = $this->resolveVisibleUser($id, request());

        if (!$user) {
            return response()->error('No user found', 401);
        }

        $channel = $this->verificationChannel(request());
        if ($error = $this->verificationChannelError($user, $channel)) {
            return $error;
        }

        $user->manualVerify($channel);
        $user = $user->refresh();

        return response()->json([
            'message'           => 'User verified',
            'channel'           => $channel,
            'email_verified_at' => $user->email_verified_at,
            'phone_verified_at' => $user->phone_verified_at,
            'status'            => 'ok',
        ]);
    }

    /**
     * Ask a user to verify their email address or phone number. The user gets a
     * link, by email or SMS, that confirms it without signing in.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function sendVerification(Request $request, string $id)
    {
        if (!$this->canVerifyUsers(Auth::getUserFromSession($request))) {
            return response()->error('Not authorized to verify users.', 403);
        }
        $actor = Auth::getUserFromSession($request);

        $user = $this->resolveVisibleUser($id, $request);
        if (!$user) {
            return response()->error('No user found', 404);
        }

        $channel = $this->verificationChannel($request);
        if ($error = $this->verificationChannelError($user, $channel)) {
            return $error;
        }

        $for   = $channel === 'phone' ? 'phone_verification' : 'email_verification';
        $value = $channel === 'phone' ? $user->phone : $user->email;

        // A new request replaces any earlier one an administrator sent
        VerificationCode::where('subject_uuid', $user->uuid)
            ->where('for', $for)
            ->where('status', 'active')
            ->where('meta->source', 'admin_request')
            ->delete();

        $verificationCode = VerificationCode::create([
            'subject_uuid' => $user->uuid,
            'subject_type' => Utils::getModelClassName($user),
            'for'          => $for,
            'expires_at'   => Carbon::now()->addHours(48),
            'meta'         => [
                'source'            => 'admin_request',
                'channel'           => $channel,
                'value'             => $value,
                'requested_by_uuid' => $actor->uuid,
            ],
            'status'       => 'active',
        ]);

        try {
            if ($channel === 'phone') {
                $url    = UserContactVerificationRequested::urlFor($verificationCode);
                $result = app(SmsService::class)->send($value, 'Verify your ' . config('app.name') . ' phone number: ' . $url);
                if (is_array($result) && array_key_exists('success', $result) && !$result['success']) {
                    throw new \RuntimeException((string) ($result['error'] ?? $result['message'] ?? 'The SMS provider refused the message.'));
                }
            } else {
                $user->notify(new UserContactVerificationRequested($verificationCode));
            }
        } catch (\Throwable $e) {
            $verificationCode->delete();

            return response()->error('Unable to send the verification request: ' . $e->getMessage());
        }

        return response()->json(['status' => 'ok', 'channel' => $channel]);
    }

    /**
     * Whether the user may verify other users: admins, holders of the
     * Administrator role, and anyone granted `iam verify user`.
     */
    private function canVerifyUsers(?User $actor): bool
    {
        if (!$actor) {
            return false;
        }

        if ($actor->isAdmin() || $actor->hasRole('Administrator')) {
            return true;
        }

        try {
            return $actor->hasPermissionTo('iam verify user');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * The verification channel requested: `email` (default) or `phone`.
     */
    private function verificationChannel(Request $request): string
    {
        return $request->input('channel') === 'phone' ? 'phone' : 'email';
    }

    /**
     * An error when the user's email/phone can't be verified: it isn't set, or
     * it's already verified.
     */
    private function verificationChannelError(User $user, string $channel): ?\Illuminate\Http\JsonResponse
    {
        $label = $channel === 'phone' ? 'phone number' : 'email address';
        if (empty($channel === 'phone' ? $user->phone : $user->email)) {
            return response()->error('This user has no ' . $label . ' to verify.', 422);
        }

        if (!empty($channel === 'phone' ? $user->phone_verified_at : $user->email_verified_at)) {
            return response()->error('This user\'s ' . $label . ' is already verified.', 422);
        }

        return null;
    }

    /**
     * Removes this user from the current company.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeFromCompany($id)
    {
        if (!$id) {
            return response()->error('No user to remove', 401);
        }

        // get user to remove from company
        $user = $this->resolveVisibleUser($id, request());

        if (!$user) {
            return response()->error('No user found', 401);
        }

        // get the current company user is being removed from
        $company = Company::where('uuid', session('company'))->first();

        if (!$company) {
            return response()->error('Unable to remove user from this company', 401);
        }

        /** @var \Illuminate\Support\Collection */
        $userCompanies = $user->companyUsers()->get();

        // only a member to one company then delete the user
        if ($userCompanies->count() === 1 && $userCompanies->first()?->company_uuid === $company->uuid) {
            $user->delete();
        } else {
            $user->companyUsers()->where('company_uuid', $company->uuid)->delete();

            // trigger event user removed from company
            event(new UserRemovedFromCompany($user, $company));

            // set to other company for next login
            $nextCompany = $userCompanies->filter(function ($userCompany) {
                return $userCompany->company_uuid !== session('company');
            })->first();

            if ($nextCompany) {
                $user->update(['company_uuid' => $nextCompany->company_uuid]);
            } else {
                $user->delete();
            }
        }

        return response()->json([
            'message' => 'User removed',
        ]);
    }

    /**
     * Updates the current users password.
     *
     * @return \Illuminate\Http\Response
     */
    public function setCurrentUserPassword(UpdatePasswordRequest $request)
    {
        $password = $request->input('password');

        $user = $request->user();

        if (!$user) {
            return response()->error('User not authenticated');
        }

        $user->changePassword($password);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Endpoint to quickly search/query.
     *
     * @return \Illuminate\Http\Response
     */
    public function searchRecords(Request $request)
    {
        $query   = $request->input('query');
        $results = User::select(['uuid', 'name'])
            ->search($query)
            ->limit(12)
            ->get();

        return response()->json($results);
    }

    /**
     * Export the users to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('users-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new UserExport($selections), $fileName);
    }

    /**
     * Validate the user's current password.
     *
     * @return \Illuminate\Http\Response
     */
    public function validatePassword(ValidatePasswordRequest $request)
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Change the user's password.
     *
     * @return \Illuminate\Http\Response
     */
    public function changeUserPassword(UpdatePasswordRequest $request)
    {
        $user               = $request->user();
        $newPassword        = $request->input('password');
        $newConfirmPassword = $request->input('password_confirmation');

        if ($newPassword !== $newConfirmPassword) {
            return response()->error('Password is not matching');
        }

        $user->changePassword($newPassword);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Save the user selected locale.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function setUserLocale(Request $request)
    {
        $locale           = $request->input('locale', 'en-us');
        $user             = $request->user();
        $localeSettingKey = 'user.' . $user->uuid . '.locale';

        // Persist to database
        Setting::configure($localeSettingKey, $locale);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Get the user selected locale.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function getUserLocale(Request $request)
    {
        $user             = $request->user();
        $localeSettingKey = 'user.' . $user->uuid . '.locale';

        // Get from database
        $locale = Setting::lookup($localeSettingKey, 'en-us');

        return response()->json(['status' => 'ok', 'locale' => $locale]);
    }

    /**
     * Get all current user permissions.
     *
     * @return \Illuminate\Http\Response
     */
    #[SkipAuthorizationCheck]
    public function getUserPermissions(Request $request)
    {
        $user        = $request->user();
        $permissions = $user->getAllPermissions();

        return response()->json(['permissions' => $permissions]);
    }
}
