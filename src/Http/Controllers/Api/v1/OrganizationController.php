<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Organization;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class OrganizationController extends Controller
{
    public function listOrganizations(Request $request)
    {
        $limit       = max(1, min((int) $request->input('limit', 10), 100));
        $searchQuery = trim((string) $request->input('query', ''));

        $organizations = Company::query()
            ->select(['name', 'public_id'])
            ->whereHas('users')
            ->when($searchQuery !== '', function ($query) use ($searchQuery) {
                $query->where('companies.name', 'LIKE', '%' . $searchQuery . '%');
            })
            ->limit($limit)
            ->get()
            ->map(function (Company $company) {
                return [
                    'name'      => $company->name,
                    'public_id' => $company->public_id,
                ];
            })
            ->values();

        return response()->json($organizations);
    }

    public function getCurrent(Request $request)
    {
        $token       = $request->bearerToken();
        if (empty($token)) {
            return response()->error('No API key found to fetch company details with.');
        }

        $isSecretKey = Str::startsWith($token, '$');
        $companyId   = null;

        // Depending on API key format set the connection to find credential on
        $connection = Str::startsWith($token, 'flb_test_') ? 'sandbox' : 'mysql';

        // Find the API Credential record
        $findApKey = ApiCredential::on($connection)
            ->where(function ($query) use ($isSecretKey, $token) {
                if ($isSecretKey) {
                    $query->where('secret', $token);
                } else {
                    $query->where('key', $token);
                }
            })
            ->with(['company.owner'])
            ->withoutGlobalScopes();

        // Get the api credential model record
        $apiCredential = $findApKey->first();
        if ($apiCredential) {
            $companyId = $apiCredential->company_uuid;
        }

        // If no api credential found then try for personal access token then get company from the tokenable
        if (!$apiCredential) {
            $apiCredential = PersonalAccessToken::findToken($token);

            if ($apiCredential && $apiCredential->tokenable) {
                $companyId = $apiCredential->tokenable->company_uuid;
            }
        }

        // Handle no api credential found
        if (!$apiCredential) {
            return response()->error('No API key found to fetch company details with.');
        }

        // Get the organization owning the API key
        $organization = Company::where('uuid', $companyId)->first();

        return new Organization($organization);
    }
}
