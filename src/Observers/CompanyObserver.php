<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\Company;
use Illuminate\Support\Facades\Cache;

class CompanyObserver
{
    /**
     * Clear org caches for all users of this company.
     *
     * @param \App\Models\Company $company
     */
    protected function clearUserOrganizationCache(Company $company)
    {
        // Ensure company_users relationship is loaded
        $company->loadMissing(['users:uuid']);

        foreach ($company->users as $user) {
            Cache::forget("user_organizations_{$user->uuid}");
        }
    }

    /**
     * Handle the Company "created" event.
     */
    public function created(Company $company)
    {
        $this->clearUserOrganizationCache($company);
    }

    /**
     * Handle the Company "updated" event.
     */
    public function updated(Company $company)
    {
        $this->clearUserOrganizationCache($company);
    }

    /**
     * Handle the Company "deleted" event.
     */
    public function deleted(Company $company)
    {
        $this->clearUserOrganizationCache($company);
    }

    /**
     * Handle the Company "restored" event.
     */
    public function restored(Company $company)
    {
        $this->clearUserOrganizationCache($company);
    }

    /**
     * Handle the Company "force deleted" event.
     */
    public function forceDeleted(Company $company)
    {
        $this->clearUserOrganizationCache($company);
    }
}
