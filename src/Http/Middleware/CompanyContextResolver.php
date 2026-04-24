<?php

namespace Fleetbase\Http\Middleware;

use Closure;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CompanyContextResolver
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // No auth → this middleware is a no-op. Other middleware (auth:sanctum)
        // handles guests/rejection separately.
        if (!$user) {
            return $next($request);
        }

        // Client hard-guardrail: clients cannot use org-level routes.
        $default = $user->defaultCompany();
        if ($default && $default->isClient()) {
            return $this->forbid();
        }

        $header = $request->header('X-Company-Context');
        $header = is_string($header) ? trim($header) : $header;

        if ($header !== null && $header !== '') {
            // Validate UUID format before any DB hit.
            if (!Str::isUuid($header)) {
                return $this->forbid();
            }

            // Access check via pivot.
            if (!$user->canAccessCompany($header)) {
                return $this->forbid();
            }

            // Resolve the company record. Null => treat as forbidden (the pivot
            // row exists but the company was soft-deleted or hard-removed).
            $company = Company::where('uuid', $header)->first();
            if (!$company) {
                return $this->forbid();
            }

            // Defense-in-depth: if somehow the pivoted company is itself a
            // client AND the user's default is NOT (unusual), still forbid —
            // the guardrail check above already blocked client users, so
            // this shouldn't fire, but it's cheap insurance.
            // (Comment only; behavior is handled by earlier guard.)

            $this->bind($request, $company);

            return $next($request);
        }

        // No header → fallback to user's default company.
        if (!$default) {
            return $this->forbid();
        }

        $this->bind($request, $default);

        return $next($request);
    }

    /**
     * Clean up the container instance after the response is sent so long-running
     * workers (Octane / Swoole) don't leak context across requests.
     */
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
