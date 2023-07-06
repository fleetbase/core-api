<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;

class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * @param  \Fleetbase\Models\User  $user
     * @return void
     */
    public function created(User $user)
    {
        // create company user record
        if (session('company')) {
            CompanyUser::create(['company_uuid' => session('company'), 'user_uuid' => $user->uuid, 'status' => $user->status]);
        }
    }

    /**
     * Handle the User "deleted" event.
     *
     * @param  \Fleetbase\Models\User  $user
     * @return void
     */
    public function deleted(User $user)
    {
        // remove company user records
        if (session('company')) {
            CompanyUser::where(['company_uuid' => session('company'), 'user_uuid' => $user->uuid])->delete();
        }
    }
}
