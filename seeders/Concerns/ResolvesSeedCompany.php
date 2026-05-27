<?php

namespace Fleetbase\Seeders\Concerns;

use Fleetbase\Models\Company;

trait ResolvesSeedCompany
{
    protected function resolveSeedCompany(?string $fallbackUuidEnv = null, ?string $fallbackPublicIdEnv = null): ?Company
    {
        $companyUuid     = env('SEED_COMPANY_UUID') ?: ($fallbackUuidEnv ? env($fallbackUuidEnv) : null);
        $companyPublicId = env('SEED_COMPANY_PUBLIC_ID') ?: ($fallbackPublicIdEnv ? env($fallbackPublicIdEnv) : null);

        if ($companyUuid) {
            return Company::where('uuid', $companyUuid)->first();
        }

        if ($companyPublicId) {
            return Company::where('public_id', $companyPublicId)->first();
        }

        return Company::query()->orderBy('created_at')->first();
    }
}
