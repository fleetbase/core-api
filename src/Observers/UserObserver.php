<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Models\Driver;

class UserObserver
{
    /**
     * Handle the User "deleted" event.
     *
     * @param  \Fleetbase\Models\User  $user
     * @return void
     */
    public function deleted(User $user)
    {
        // if the user deleted is a driver, delete their driver record to
        Driver::where('user_uuid', $user->uuid)->delete();

        // remove company user records
        if (session('company')) {
            CompanyUser::where(['company_uuid' => session('company'), 'user_uuid' => $user->uuid])->delete();
        }
    }
}
