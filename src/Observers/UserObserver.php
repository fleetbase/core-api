<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Services\UserCacheService;
use Illuminate\Support\Facades\Cache;

class UserObserver
{
    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Invalidate user cache when user is updated
        UserCacheService::invalidateUser($user);

        // Invalidate organizations cache (user might be an owner)
        $this->invalidateOrganizationsCache($user);
    }

    /**
     * Handle the User "deleted" event.
     *
     * @return void
     */
    public function deleted(User $user)
    {
        // Invalidate user cache when user is deleted
        UserCacheService::invalidateUser($user);

        // Invalidate organizations cache
        $this->invalidateOrganizationsCache($user);

        // remove company user records
        if (session('company')) {
            CompanyUser::where(['company_uuid' => session('company'), 'user_uuid' => $user->uuid])->delete();
        }
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        // Invalidate user cache when user is restored
        UserCacheService::invalidateUser($user);

        // Invalidate organizations cache
        $this->invalidateOrganizationsCache($user);
    }

    /**
     * Invalidate organizations cache for the user.
     *
     * This clears the cached organizations list which includes owner relationships.
     * When a user updates their profile and they are an owner of organizations,
     * the cached organization data needs to be refreshed to reflect the updated owner info.
     */
    private function invalidateOrganizationsCache(User $user): void
    {
        $cacheKey = "user_organizations_{$user->uuid}";
        Cache::forget($cacheKey);
    }
}
