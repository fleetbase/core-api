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
        // Note: With updated_at in cache key, this provides immediate invalidation
        // while the timestamp-based key provides automatic cache busting
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

        // Free the email and phone so they can be used by a new account
        if (!$user->isForceDeleting()) {
            $this->releaseIdentity($user);
        }
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        $this->restoreIdentity($user);

        // Invalidate user cache when user is restored
        UserCacheService::invalidateUser($user);

        // Invalidate organizations cache
        $this->invalidateOrganizationsCache($user);
    }

    /**
     * Move a deleted user's email and phone into meta so they no longer block
     * a new account, including lookups that include trashed users.
     */
    private function releaseIdentity(User $user): void
    {
        $identity = array_filter(['email' => $user->email, 'phone' => $user->phone]);
        if (empty($identity)) {
            return;
        }

        $meta                     = (array) ($user->meta ?? []);
        $meta['deleted_identity'] = $identity;

        $user->forceFill(['email' => null, 'phone' => null, 'meta' => $meta])->saveQuietly();
    }

    /**
     * Put a restored user's email and phone back when no other account has
     * taken them in the meantime.
     */
    private function restoreIdentity(User $user): void
    {
        $meta     = (array) ($user->meta ?? []);
        $identity = (array) data_get($meta, 'deleted_identity', []);
        if (empty($identity)) {
            return;
        }

        foreach (['email', 'phone'] as $column) {
            $value = $identity[$column] ?? null;
            if ($value && !$user->{$column} && User::where($column, $value)->where('uuid', '!=', $user->uuid)->doesntExist()) {
                $user->{$column} = $value;
            }
        }

        unset($meta['deleted_identity']);
        $user->meta = $meta;
        $user->saveQuietly();
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
        Cache::forget("user_organizations_{$user->uuid}");
        Cache::forget("user_organizations_v2_{$user->uuid}");
    }
}
