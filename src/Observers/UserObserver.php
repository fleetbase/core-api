<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Company;
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
        // load user company
        $user->load(['company']);

        // create company user record
        if ($user->company_uuid) {
            CompanyUser::create(['company_uuid' => $user->company_uuid, 'user_uuid' => $user->uuid, 'status' => $user->status]);
        }

        // invite user to join company
        $user->sendInviteFromCompany();
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
