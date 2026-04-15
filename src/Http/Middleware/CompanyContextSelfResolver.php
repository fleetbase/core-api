<?php

namespace Fleetbase\Http\Middleware;

use Closure;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active Company context for self-service or role-agnostic routes.
 *
 * Differs from CompanyContextResolver (the strict variant) in one way only:
 * it does NOT hard-block client-role users. Both org and client users can
 * access their own or header-targeted companies, gated by canAccessCompany().
 *
 * Use this on routes that serve both roles (e.g. CompanySettings).
 * Use the strict CompanyContextResolver on org-admin-only routes.
 */
class CompanyContextSelfResolver
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Auth middleware handles 401s.
        if (!$user) {
            return $next($request);
        }

        $header = $request->header('X-Company-Context');
        $header = is_string($header) ? trim($header) : $header;

        if ($header !== null && $header !== '') {
            if (!Str::isUuid($header)) {
                return $this->forbid();
            }

            if (!$user->canAccessCompany($header)) {
                return $this->forbid();
            }

            $company = Company::where('uuid', $header)->first();
            if (!$company instanceof Company) {
                return $this->forbid();
            }

            $this->bind($request, $company);
            return $next($request);
        }

        // No header → fall back to user's default.
        $default = $user->defaultCompany();
        if (!$default instanceof Company) {
            return $this->forbid();
        }

        $this->bind($request, $default);
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if (app()->bound('companyContext')) {
            app()->forgetInstance('companyContext');
        }
    }

    private function bind(Request $request, Company $company): void
    {
        $request->attributes->set('company', $company);
        app()->instance('companyContext', $company);
    }

    private function forbid(): Response
    {
        return response()->json(
            ['error' => 'Access denied to this company context'],
            403
        );
    }
}
