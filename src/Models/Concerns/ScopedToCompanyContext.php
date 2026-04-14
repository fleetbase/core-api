<?php

namespace Fleetbase\Models\Concerns;

use Fleetbase\Models\Company;

/**
 * Query scope for multi-tenant models. Filters queries to rows belonging to
 * the single company resolved by CompanyContextResolver middleware.
 *
 * Resolution order (first match wins):
 *   1. The current Request's `company` attribute (set by the middleware on
 *      each request). Preferred because it is tied to the Request object's
 *      lifetime and cannot leak across requests.
 *   2. The container's `companyContext` instance (also set by the middleware).
 *      Used as a fallback for code paths where a Request is not available
 *      (e.g. queued jobs, console commands with explicit context priming).
 *
 * Fail-closed contract:
 *   If neither binding is populated, the scope forces the query to return
 *   zero rows (applies an always-false predicate). A tenant-owned model
 *   used without the middleware degrades to invisible, never to "leaks
 *   every tenant's data."
 *
 * Usage:
 *     class Shipment extends Model {
 *         use ScopedToCompanyContext;
 *     }
 *
 *     Shipment::inCompanyContext()->where(...)->get();
 */
trait ScopedToCompanyContext
{
    /**
     * Filter a query to the currently resolved company.
     * Returns an empty result set when no company context is bound.
     */
    public function scopeInCompanyContext($query)
    {
        $company = $this->resolveCompanyContext();
        $column  = $this->getTable() . '.company_uuid';

        if ($company instanceof Company) {
            return $query->where($column, $company->uuid);
        }

        // No context bound → fail closed. Always-false predicate that the
        // query builder + any database can evaluate cheaply.
        return $query->whereRaw('1 = 0');
    }

    /**
     * Resolve the active Company from the Request or container. Returns null
     * when no context is bound.
     */
    protected function resolveCompanyContext(): ?Company
    {
        // Prefer the Request attribute — tied to the current request and
        // guaranteed not to bleed across lifecycles.
        if (app()->bound('request')) {
            $request = app('request');
            if (isset($request->attributes)
                && $request->attributes instanceof \Symfony\Component\HttpFoundation\ParameterBag
                && $request->attributes->has('company')) {
                $candidate = $request->attributes->get('company');
                if ($candidate instanceof Company) {
                    return $candidate;
                }
            }
        }

        // Fall back to the container instance (useful for queue jobs).
        if (app()->bound('companyContext')) {
            $candidate = app('companyContext');
            if ($candidate instanceof Company) {
                return $candidate;
            }
        }

        return null;
    }
}
