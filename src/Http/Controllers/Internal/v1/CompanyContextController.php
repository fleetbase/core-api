<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stateless multi-tenant company context controller.
 *
 * Two endpoints:
 *   GET  /v1/companies/current-context — read the middleware-resolved company
 *   POST /v1/companies/switch-context  — validation oracle only (NO mutation)
 *
 * The system is stateless (Task 8). There is no session-stored context. The
 * Ember client sends `X-Company-Context: <uuid>` per request; the middleware
 * (`fleetbase.company.context`) resolves + binds it to
 * `$request->attributes->get('company')` and `app('companyContext')`. After
 * the request ends, that binding is cleared.
 *
 * Consequently:
 *   - `current` reads ONLY what the middleware already resolved for THIS
 *     request. No fallback lookup, no DB write, no rebind.
 *   - `switch` validates a target UUID against the user's pivot access and
 *     echoes back the company shape. It does NOT mutate session, the
 *     container, request attributes, or the database. The Ember client uses
 *     the 200/403 response to decide whether to send the new UUID via
 *     `X-Company-Context` on subsequent requests.
 */
class CompanyContextController extends Controller
{
    /**
     * Return the company resolved by CompanyContextResolver middleware for
     * THIS request. No additional lookup; no mutation.
     */
    public function current(Request $request): JsonResponse
    {
        $this->guardClient($request);

        $company = $this->resolvedCompany($request);
        abort_unless($company instanceof Company, 403);

        return response()->json([
            'company' => $this->shape($company),
        ]);
    }

    /**
     * Validation oracle. Validates that the target company UUID is real
     * and accessible by the user, then echoes back the company info.
     *
     * Crucially: this endpoint does NOT mutate any state.
     *   - No session writes
     *   - No app()->instance() rebinding
     *   - No request attribute changes
     *   - No DB writes
     */
    public function switch(Request $request): JsonResponse
    {
        $this->guardClient($request);

        $user = $request->user();
        abort_unless($user, 403);

        $uuid = $request->input('company_uuid');

        // Validate format BEFORE any DB hit.
        if (!is_string($uuid) || !Str::isUuid($uuid)) {
            return $this->forbid();
        }

        // Access check (pivot membership).
        if (!$user->canAccessCompany($uuid)) {
            return $this->forbid();
        }

        // Resolve the company. Null => forbid (don't leak existence).
        $company = Company::where('uuid', $uuid)->first();
        if (!$company instanceof Company) {
            return $this->forbid();
        }

        // Defense-in-depth: never echo back a client company to a non-client
        // request (the client guardrail above already handled it, but cheap
        // insurance — canAccessCompany is the primary gate).
        if ($company->isClient() && !$this->resolvedCompany($request)?->isOrganization()) {
            return $this->forbid();
        }

        return response()->json([
            'company' => $this->shape($company),
        ]);
    }

    /**
     * If the resolved (current) company is a client, the user is a
     * client-role actor — 403 immediately. The middleware already enforces
     * this; this is defense-in-depth.
     */
    private function guardClient(Request $request): void
    {
        $resolved = $this->resolvedCompany($request);
        if ($resolved instanceof Company && $resolved->isClient()) {
            abort(403);
        }
    }

    /**
     * Read the middleware-resolved company. Request attribute first, then
     * container fallback (matches ScopedToCompanyContext trait's order).
     */
    private function resolvedCompany(Request $request): ?Company
    {
        $candidate = $request->attributes->get('company');
        if ($candidate instanceof Company) {
            return $candidate;
        }

        if (app()->bound('companyContext')) {
            $bound = app('companyContext');
            if ($bound instanceof Company) {
                return $bound;
            }
        }

        return null;
    }

    /**
     * Minimal safe response shape — uuid, name, company_type. Don't leak
     * internals like client_settings or stripe_id.
     */
    private function shape(Company $company): array
    {
        return [
            'uuid'         => $company->uuid,
            'name'         => $company->name,
            'company_type' => $company->company_type,
        ];
    }

    private function forbid(): JsonResponse
    {
        return response()->json(['error' => 'Access denied to this company context'], 403);
    }
}
