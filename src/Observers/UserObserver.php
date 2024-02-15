<?php

namespace Fleetbase\Observers;

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
        // Make sure we have company
        $company = $user->getCompany();

        // If no company delete user and throw exception
        if (!$company) {
            $user->deleteQuietly();
            throw new \Exception('Unable to assign user to company.');
        }

        if (CompanyUser::where(['company_uuid' => $company->uuid, 'user_uuid' => $user->uuid])->doesntExist()) {
            CompanyUser::create(['company_uuid' => $company->uuid, 'user_uuid' => $user->uuid, 'status' => $user->status]);
        }

        // invite user to join company
        $user->sendInviteFromCompany($company);

        // Notify the company owner a user has been created
        if ($company) {
            NotificationRegistry::notify(UserCreated::class, $user, $company);
        }
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
