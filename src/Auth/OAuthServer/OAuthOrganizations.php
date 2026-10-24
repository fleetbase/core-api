<?php

namespace Fleetbase\Auth\OAuthServer;

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Support\Collection;

/**
 * Which organizations a user may share with an app.
 *
 * A user can share an organization they are an active member of AND administer: they own it,
 * hold its Administrator role, or are a system admin. Plain sign-in (openid, profile, email)
 * needs no organization. The same check runs again on every token refresh and API call, so an
 * app loses access when the user leaves the organization or stops administering it.
 */
class OAuthOrganizations
{
    /**
     * Test seam: replaces the Administrator role lookup (spatie/permission) when set.
     *
     * @var (\Closure(CompanyUser): bool)|null
     */
    public static ?\Closure $administratorCheck = null;

    public static function membership(User $user, Company $company): ?CompanyUser
    {
        return CompanyUser::where('user_uuid', $user->uuid)
            ->where('company_uuid', $company->uuid)
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', 'active');
            })
            ->first();
    }

    public static function canShare(User $user, Company $company): bool
    {
        if (!static::userIsActive($user)) {
            return false;
        }

        $membership = static::membership($user, $company);
        if (!$membership) {
            return false;
        }

        if ($user->isAdmin() || $company->owner_uuid === $user->uuid) {
            return true;
        }

        if (static::$administratorCheck) {
            return (bool) (static::$administratorCheck)($membership);
        }

        return $membership->hasRole('Administrator');
    }

    public static function userIsActive(User $user): bool
    {
        return ($user->status ?? 'active') !== 'inactive' && !$user->trashed();
    }

    /**
     * The user's organizations for the consent screen's picker, with whether each can be shared.
     *
     * @return Collection<int, array{id: string, uuid: string, name: string, logo_url: ?string, can_share: bool}>
     */
    public static function forPicker(User $user): Collection
    {
        $companies = Company::whereIn('uuid', CompanyUser::where('user_uuid', $user->uuid)->pluck('company_uuid'))
            ->whereNotNull('owner_uuid')
            ->orderBy('name')
            ->get();

        return $companies->map(fn (Company $company) => [
            'id'        => $company->public_id,
            'uuid'      => $company->uuid,
            'name'      => $company->name,
            'logo_url'  => $company->logo_url,
            'can_share' => static::canShare($user, $company),
        ])->values();
    }

    /**
     * Find one of the user's organizations by public_id or uuid.
     */
    public static function find(User $user, ?string $id): ?Company
    {
        if (!$id) {
            return null;
        }

        $company = Company::where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))->first();
        if (!$company || !static::membership($user, $company)) {
            return null;
        }

        return $company;
    }
}
