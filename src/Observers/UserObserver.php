<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Services\UserCacheService;

class UserObserver
{
    /**
     * Handle the User "updated" event.
     *
     * @param \Fleetbase\Models\User $user
     *
     * @return void
     */
    public function updated(User $user): void
    {
        // Invalidate cache when user is updated
        UserCacheService::invalidateUser($user);
    }

    /**
     * Handle the User "deleted" event.
     *
     * @return void
     */
    public function deleted(User $user)
    {
        // Invalidate cache when user is deleted
        UserCacheService::invalidateUser($user);

        // remove company user records
        if (session('company')) {
            CompanyUser::where(['company_uuid' => session('company'), 'user_uuid' => $user->uuid])->delete();
        }
    }

    /**
     * Handle the User "restored" event.
     *
     * @param \Fleetbase\Models\User $user
     *
     * @return void
     */
    public function restored(User $user): void
    {
        // Invalidate cache when user is restored
        UserCacheService::invalidateUser($user);
    }
}
