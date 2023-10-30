<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Notifications\UserCreated;
use Fleetbase\Support\NotificationRegistry;

class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * @return void
     */
    public function created(User $user)
    {
        // load user company
        $user->load(['company.owner']);

        // create company user record
        if ($user->company_uuid) {
            CompanyUser::create(['company_uuid' => $user->company_uuid, 'user_uuid' => $user->uuid, 'status' => $user->status]);
        }

        // invite user to join company
        $user->sendInviteFromCompany();

        // Notify the company owner a user has been created
        // $user->company->owner->notify(new UserCreated($user, $user->company));
        NotificationRegistry::notify(UserCreated::class, $user, $user->company);
    }

    /**
     * Handle the User "deleted" event.
     *
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
