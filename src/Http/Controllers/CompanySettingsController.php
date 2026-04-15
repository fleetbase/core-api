<?php

namespace Fleetbase\Http\Controllers;

use Fleetbase\Http\Requests\CompanySettingsUpdateRequest;
use Fleetbase\Models\Company;
use Fleetbase\Support\CompanySettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySettingsController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->authorizeCompanyAccess($request, $company);

        return response()->json([
            'settings' => CompanySettingsResolver::forCompany($company->uuid)->all(),
        ]);
    }

    public function update(CompanySettingsUpdateRequest $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        $this->authorizeCompanyAccess($request, $company);

        $resolver = CompanySettingsResolver::forCompany($company->uuid);

        foreach ($request->input('settings', []) as $key => $value) {
            $resolver->set((string) $key, $value);
        }

        return response()->json([
            'settings' => $resolver->all(),
        ]);
    }

    private function resolveCompany(Request $request): Company
    {
        $company = $request->attributes->get('company');

        if (!$company instanceof Company && app()->bound('companyContext')) {
            $candidate = app('companyContext');
            if ($candidate instanceof Company) {
                $company = $candidate;
            }
        }

        abort_unless($company instanceof Company, 403);

        return $company;
    }

    private function authorizeCompanyAccess(Request $request, Company $company): void
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($user->canAccessCompany($company->uuid), 403);
    }
}
