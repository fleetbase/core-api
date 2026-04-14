<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\ClientCompanyRequest;
use Fleetbase\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Org-scoped CRUD over client companies.
 *
 * All operations resolve the active organization from the
 * `fleetbase.company.context` middleware (either from the
 * `X-Company-Context` header or the authenticated user's default
 * company) and scope every query to:
 *
 *   parent_company_uuid = <resolved org uuid>
 *   AND is_client = true
 *
 * Out-of-scope targets deliberately return 404 (not 403) so that we
 * do not leak the existence of client companies belonging to other
 * organizations.
 */
class ClientCompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $this->resolveOrg($request);

        $clients = Company::where('parent_company_uuid', $org->uuid)
            ->where('is_client', true)
            ->orderBy('name')
            ->get();

        return response()->json(['clients' => $clients]);
    }

    public function store(ClientCompanyRequest $request): JsonResponse
    {
        $org = $this->resolveOrg($request);

        // Only payload-sanitized fields flow in. Tenancy-critical
        // fields (parent_company_uuid, company_type, is_client) are
        // server-controlled and IGNORED from the payload.
        $client = Company::create([
            'name'                => $request->input('name'),
            'client_code'         => $request->input('client_code'),
            'client_settings'     => $request->input('client_settings'),
            'parent_company_uuid' => $org->uuid,
            'company_type'        => 'client',
            'is_client'           => true,
        ]);

        return response()->json(['client' => $client->fresh()], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $org    = $this->resolveOrg($request);
        $client = $this->findScopedClient($org, $uuid);

        return response()->json(['client' => $client]);
    }

    public function update(ClientCompanyRequest $request, string $uuid): JsonResponse
    {
        $org    = $this->resolveOrg($request);
        $client = $this->findScopedClient($org, $uuid);

        // Strict whitelist. Tenancy/identity fields
        // (parent_company_uuid, company_type, is_client, uuid,
        // public_id, company_users, owner_uuid, etc.) are explicitly
        // NOT included.
        $client->update($request->only(['name', 'client_code', 'client_settings']));

        return response()->json(['client' => $client->fresh()]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $org    = $this->resolveOrg($request);
        $client = $this->findScopedClient($org, $uuid);
        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * Resolve the active organization for this request.
     *
     * The context binding is populated by
     * `CompanyContextResolver` (the `fleetbase.company.context`
     * middleware). If missing — or if the resolved company is NOT
     * an organization (for example, a client company somehow bound
     * as context) — this hard-fails with 403.
     */
    private function resolveOrg(Request $request): Company
    {
        $company = $request->attributes->get('company');
        if (!$company instanceof Company) {
            $company = app()->bound('companyContext') ? app('companyContext') : null;
        }

        abort_unless($company instanceof Company && $company->isOrganization(), 403);

        return $company;
    }

    /**
     * Fetch a client company by uuid, verifying:
     *   - uuid is a well-formed UUID string
     *   - record exists
     *   - is_client = true
     *   - parent_company_uuid matches the resolved org
     *
     * Any failed invariant yields 404 — never 403 — so that
     * cross-org existence is not leaked.
     */
    private function findScopedClient(Company $org, string $uuid): Company
    {
        abort_unless(Str::isUuid($uuid), 404);

        $client = Company::where('uuid', $uuid)
            ->where('parent_company_uuid', $org->uuid)
            ->where('is_client', true)
            ->first();

        abort_unless($client, 404);

        return $client;
    }
}
