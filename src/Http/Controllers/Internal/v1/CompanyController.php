<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Exports\CompanyExport;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Http\Resources\User as UserResource;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\ExtensionInstall;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class CompanyController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'company';

    /**
     * Find company by public_id or invitation code.
     *
     * @return \Illuminate\Http\Response
     */
    public function findCompany(string $id)
    {
        $id         = trim($id);
        $isPublicId = Str::startsWith($id, ['company_']);

        if ($isPublicId) {
            $company = Company::where('public_id', $id)->first();
        } else {
            $invite = Invite::where(['uri' => $id, 'reason' => 'join_company'])->with(['subject'])->first();

            if ($invite) {
                $company = $invite->subject;
            }
        }

        return new Organization($company);
    }

    /**
     * Get the current organization's two factor authentication settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTwoFactorSettings()
    {
        $company = Auth::getCompany();

        if (!$company) {
            return response()->error('No company session found', 401);
        }

        $twoFaSettings = TwoFactorAuth::getTwoFaSettingsForCompany($company);

        return response()->json($twoFaSettings->value);
    }

    /**
     * Save the two factor authentication settings for the current company.
     *
     * @param Request $request the HTTP request
     *
     * @return \Illuminate\Http\Response
     */
    public function saveTwoFactorSettings(Request $request)
    {
        $twoFaSettings = $request->array('twoFaSettings');
        $company       = Auth::getCompany();

        if (!$company) {
            return response()->error('No company session found', 401);
        }
        if (isset($twoFaSettings['enabled']) && $twoFaSettings['enabled'] === false) {
            $twoFaSettings['enforced'] = false;
        }
        TwoFactorAuth::saveTwoFaSettingsForCompany($company, $twoFaSettings);

        return response()->json(['message' => 'Two-Factor Authentication saved successfully']);
    }

    /**
     * Get all users for a company.
     *
     * @param string $id The company id
     *
     * @return \Illuminate\Http\Response
     */
    public function users(string $id, Request $request)
    {
        $searchQuery = $request->searchQuery();
        $limit       = $request->input(['limit', 'nestedLimit'], 20);
        $paginate    = $request->boolean('paginate');
        $exclude     = $request->array('exclude');

        // Start user query
        $usersQuery = CompanyUser::whereHas('company',
            function ($query) use ($id) {
                $query->where('public_id', $id);
                $query->orWhere('uuid', $id);
            }
        )
        ->whereHas('user')
        ->whereNotIn('user_uuid', $exclude)
        ->with(['user']);

        // Search query
        if ($searchQuery) {
            $usersQuery->whereHas('user', function ($query) use ($searchQuery) {
                $query->search($searchQuery);
            });
        }

        // Sort query
        $usersQuery->applySortFromRequest($request);

        // paginate results
        if ($paginate) {
            $users = $usersQuery->fastPaginate($limit);

            // fix results
            $transformedItems = $users->getCollection()->map(function ($companyUser) {
                return $companyUser->user;
            });

            // replace in pagination
            $users->setCollection($transformedItems);

            return response()->json([
                'users' => UserResource::collection($users->getCollection()),
                'meta'  => [
                    'current_page' => $users->currentPage(),
                    'from'         => $users->firstItem(),
                    'last_page'    => $users->lastPage(),
                    'path'         => $users->path(),
                    'per_page'     => $users->perPage(),
                    'to'           => $users->lastItem(),
                    'total'        => $users->total(),
                ],
            ]);
        }

        // get users
        $users = $usersQuery->get();

        // fix results
        $users = $users->map(function ($companyUser) {
            $companyUser->loadMissing('user');

            return $companyUser->user;
        });

        return UserResource::collection($users);
    }

    public function extensions(string $id, AdminRequest $request): JsonResponse
    {
        $company = $this->resolveAdminCompany($id);

        if (!$company) {
            return response()->json(['error' => 'Organization not found.'], 404);
        }

        $extensions = ExtensionInstall::where('company_uuid', $company->uuid)
            ->with('extension')
            ->latest('created_at')
            ->get()
            ->filter(fn ($install) => $install->extension !== null)
            ->map(function ($install) {
                $extension = $install->extension;

                return [
                    'id'           => $install->uuid,
                    'uuid'         => $install->uuid,
                    'extension_id' => $extension->extension_id,
                    'name'         => $extension->display_name ?: $extension->name,
                    'description'  => $extension->description,
                    'icon'         => $extension->fa_icon ?: 'puzzle-piece',
                    'slug'         => $extension->slug,
                    'key'          => $extension->key,
                    'version'      => $extension->version,
                    'status'       => $extension->status ?: 'installed',
                    'installed_at' => $install->created_at,
                ];
            })
            ->values();

        return response()->json(['extensions' => $extensions]);
    }

    public function setAdminStatus(string $id, AdminRequest $request): JsonResponse
    {
        $company = $this->resolveAdminCompany($id);
        $status  = $request->input('status');

        if (!$company) {
            return response()->json(['error' => 'Organization not found.'], 404);
        }

        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            return response()->json(['error' => 'Invalid organization status.'], 422);
        }

        $oldStatus       = $company->status;
        $company->status = $status === 'active' ? null : $status;
        $company->save();

        $this->logAdminCompanyActivity($request, $company, 'Organization status changed', [
            'old'        => ['status' => $oldStatus],
            'attributes' => ['status' => $company->status ?: 'active'],
        ], 'updated');

        return response()->json(['company' => new Organization($company->refresh())]);
    }

    public function setAdminOnboarding(string $id, AdminRequest $request): JsonResponse
    {
        $company = $this->resolveAdminCompany($id);

        if (!$company) {
            return response()->json(['error' => 'Organization not found.'], 404);
        }

        $completed = $request->boolean('completed');
        $oldValue  = $company->onboarding_completed_at;

        $company->onboarding_completed_at      = $completed ? now() : null;
        $company->onboarding_completed_by_uuid = $completed ? $request->user()->uuid : null;
        $company->save();

        $this->logAdminCompanyActivity($request, $company, $completed ? 'Organization onboarding marked complete' : 'Organization onboarding marked incomplete', [
            'old'        => ['onboarding_completed_at' => $oldValue],
            'attributes' => ['onboarding_completed_at' => $company->onboarding_completed_at],
        ], 'updated');

        return response()->json(['company' => new Organization($company->refresh())]);
    }

    public function transferOwnershipAdmin(string $id, AdminRequest $request): JsonResponse
    {
        $company    = $this->resolveAdminCompany($id);
        $newOwnerId = $request->input('newOwner');

        if (!$company) {
            return response()->json(['error' => 'Organization not found.'], 404);
        }

        $newOwner = $company->getCompanyUser($newOwnerId);

        if (!$newOwner) {
            return response()->json(['error' => 'The new owner is not a member of this organization.'], 422);
        }

        $oldOwnerUuid = $company->owner_uuid;
        $company->assignOwner($newOwner);

        $this->logAdminCompanyActivity($request, $company, 'Organization ownership transferred', [
            'old'        => ['owner_uuid' => $oldOwnerUuid],
            'attributes' => ['owner_uuid' => $newOwner->uuid],
        ], 'updated');

        return response()->json([
            'status'   => 'ok',
            'newOwner' => new UserResource($newOwner),
            'company'  => new Organization($company->refresh()),
        ]);
    }

    public function activateAdminUser(string $id, string $userId, AdminRequest $request): JsonResponse
    {
        return $this->setAdminCompanyUserStatus($id, $userId, $request, 'active');
    }

    public function deactivateAdminUser(string $id, string $userId, AdminRequest $request): JsonResponse
    {
        return $this->setAdminCompanyUserStatus($id, $userId, $request, 'inactive');
    }

    public function verifyAdminUser(string $id, string $userId, AdminRequest $request): JsonResponse
    {
        [$company, $user, $companyUser, $error] = $this->resolveAdminCompanyUser($id, $userId);

        if ($error) {
            return $error;
        }

        $user->manualVerify();

        $this->logAdminCompanyActivity($request, $company, 'Organization user verified', [
            'attributes' => ['user_uuid' => $user->uuid, 'email_verified_at' => $user->email_verified_at],
        ], 'updated', $user);

        return response()->json([
            'message' => 'User verified',
            'user'    => new UserResource($user->refresh()),
        ]);
    }

    public function removeAdminUser(string $id, string $userId, AdminRequest $request): JsonResponse
    {
        [$company, $user, $companyUser, $error] = $this->resolveAdminCompanyUser($id, $userId);

        if ($error) {
            return $error;
        }

        if ($company->owner_uuid === $user->uuid) {
            return response()->json(['error' => 'Transfer ownership before removing the organization owner.'], 422);
        }

        $companyUser->delete();

        $nextCompany = $user->companies()->where('companies.uuid', '!=', $company->uuid)->first();

        if ($nextCompany && $user->company_uuid === $company->uuid) {
            $user->update(['company_uuid' => $nextCompany->uuid]);
        }

        event(new UserRemovedFromCompany($user, $company));

        $this->logAdminCompanyActivity($request, $company, 'Organization user removed', [
            'attributes' => ['user_uuid' => $user->uuid, 'email' => $user->email],
        ], 'deleted', $user);

        return response()->json(['message' => 'User removed']);
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
        $fileName     = trim(Str::slug('company-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new CompanyExport($selections), $fileName);
    }

    private function setAdminCompanyUserStatus(string $id, string $userId, AdminRequest $request, string $status): JsonResponse
    {
        [$company, $user, $companyUser, $error] = $this->resolveAdminCompanyUser($id, $userId);

        if ($error) {
            return $error;
        }

        if ($status === 'inactive' && $company->owner_uuid === $user->uuid) {
            return response()->json(['error' => 'Transfer ownership before deactivating the organization owner.'], 422);
        }

        $oldStatus           = $companyUser->status;
        $companyUser->status = $status;
        $companyUser->save();

        if ($status === 'active') {
            $user->activate();
        }

        $this->logAdminCompanyActivity($request, $company, $status === 'active' ? 'Organization user activated' : 'Organization user deactivated', [
            'old'        => ['status' => $oldStatus],
            'attributes' => ['status' => $status, 'user_uuid' => $user->uuid],
        ], 'updated', $user);

        return response()->json([
            'message' => $status === 'active' ? 'User activated' : 'User deactivated',
            'status'  => $status,
            'user'    => new UserResource($user->refresh()),
        ]);
    }

    private function resolveAdminCompany(string $id): ?Company
    {
        return Company::where('uuid', $id)->orWhere('public_id', $id)->first();
    }

    private function resolveAdminCompanyUser(string $companyId, string $userId): array
    {
        $company = $this->resolveAdminCompany($companyId);

        if (!$company) {
            return [null, null, null, response()->json(['error' => 'Organization not found.'], 404)];
        }

        $user = User::where('uuid', $userId)->orWhere('public_id', $userId)->first();

        if (!$user) {
            return [$company, null, null, response()->json(['error' => 'User not found.'], 404)];
        }

        $companyUser = CompanyUser::where(['company_uuid' => $company->uuid, 'user_uuid' => $user->uuid])->first();

        if (!$companyUser) {
            return [$company, $user, null, response()->json(['error' => 'User is not a member of this organization.'], 404)];
        }

        return [$company, $user, $companyUser, null];
    }

    private function logAdminCompanyActivity(AdminRequest $request, Company $company, string $description, array $properties = [], string $event = 'updated', ?User $subject = null): void
    {
        $activity = activity('admin')
            ->causedBy($request->user())
            ->performedOn($subject ?? $company)
            ->withProperties($properties)
            ->event($event)
            ->log($description);

        $activity->company_id = $company->uuid;
        $activity->save();
    }

    /**
     * Transfer ownership of company to another member, and make them the Administrator.
     *
     * @return \Illuminate\Http\Response
     */
    public function transferOwnership(Request $request)
    {
        $companyId      = $request->input('company');
        $newOwnerId     = $request->input('newOwner');
        $leave          = $request->boolean('leave');

        // Get and validate organization
        $company = Company::where('uuid', $companyId)->first();
        if (!$company) {
            return response()->error('No organization found to transfer ownership for.');
        }

        // Get and validate the new owner
        $newOwner = $company->getCompanyUser($newOwnerId);
        if (!$newOwner) {
            return response()->error('The new owner provided could not be found for transfer of ownership.');
        }

        // Change the company owner
        $company->assignOwner($newOwner);

        // If the current user has opted to leave, remove them from the organization
        if ($leave) {
            $currentUser = $request->user();
            if ($currentUser) {
                $currentCompanyUser = $company->getCompanyUserPivot($currentUser);
                if ($currentCompanyUser) {
                    $currentCompanyUser->delete();
                }
                // Switch organization
                $nextOrganization = $currentUser->companies()->where('companies.uuid', '!=', $company->uuid)->first();
                if ($nextOrganization) {
                    $currentUser->setCompany($nextOrganization);
                }
            }
        }

        return response()->json([
            'status'          => 'ok',
            'newOwner'        => $newOwner,
            'currentUserLeft' => $leave,
        ]);
    }

    /**
     * Remove the current user, or user selected via request param from an organization.
     *
     * @return \Illuminate\Http\Response
     */
    public function leaveOrganization(Request $request)
    {
        $companyId        = $request->input('company');
        $currentUserId    = $request->input('user');
        $currentUser      = Str::isUuid($currentUserId) ? User::where('uuid', $currentUserId)->first() : Auth::getUserFromSession($request);

        // If not current user - error
        if (!$currentUser) {
            return response()->error('Unable to leave organization.');
        }

        // Get and validate organization
        $company = Company::where('uuid', $companyId)->first();
        if (!$company) {
            return response()->error('No organization found for user to leave.');
        }

        $currentCompanyUser = $company->getCompanyUserPivot($currentUser);
        if (!$currentCompanyUser) {
            return response()->error('User selected to leave organization is not a member of this organization.');
        }

        // Remove user from organization
        $currentCompanyUser->delete();

        // Switch organization
        $nextOrganization = $currentUser->companies()->where('companies.uuid', '!=', $company->uuid)->first();
        if ($nextOrganization) {
            $currentUser->setCompany($nextOrganization);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
