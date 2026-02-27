<?php

namespace Fleetbase\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

/**
 * CompanyScope — Tenant Isolation Global Scope.
 *
 * Automatically constrains every Eloquent query on models that carry a
 * `company_uuid` column to the company that is stored in the current
 * session.  This is the primary defence against the cross-tenant IDOR
 * vulnerability (GHSA-3wj9-hh56-7fw7) where single-record operations
 * (find, update, delete) were performed by UUID/public_id alone without
 * verifying the resource belonged to the caller's company.
 *
 * Behaviour
 * ---------
 * - Applied automatically to every model that calls `addGlobalScope(new CompanyScope)`.
 * - Only activates when a company UUID is present in the session AND the
 *   model's table actually has a `company_uuid` column (checked once per
 *   table name and cached in a static array to avoid repeated Schema calls).
 * - Does NOT activate during console/CLI execution (artisan commands,
 *   queue workers, migrations) to avoid breaking background jobs.
 * - Does NOT activate when no session company is set (e.g. unauthenticated
 *   requests, installer routes) so those paths continue to work unchanged.
 *
 * Escape Hatches
 * --------------
 * When a query genuinely needs to cross company boundaries (e.g. super-admin
 * tooling, system-level lookups), call one of the macro helpers added by
 * this scope's extend() method:
 *
 *   Model::withoutCompanyScope()->where(...)->get();
 *   Model::withoutGlobalScope(CompanyScope::class)->where(...)->get();
 *
 * The `withoutCompanyScope()` macro is the preferred, readable form.
 */
class CompanyScope implements Scope
{
    /**
     * Per-process cache of which table names have a `company_uuid` column.
     * Avoids repeated Schema::hasColumn() calls on the same table.
     *
     * @var array<string, bool>
     */
    protected static array $columnCache = [];

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        // Never apply during CLI execution (artisan, queue workers, etc.)
        if (app()->runningInConsole()) {
            return;
        }

        $companyUuid = Session::get('company');

        // Only apply when there is an active company session.
        if (empty($companyUuid)) {
            return;
        }

        // Only apply when the model's table actually has a company_uuid column.
        // Cache the result per table to avoid repeated Schema introspection.
        $table = $model->getTable();
        if (!isset(static::$columnCache[$table])) {
            static::$columnCache[$table] = Schema::hasColumn($table, 'company_uuid');
        }

        if (!static::$columnCache[$table]) {
            return;
        }

        $builder->where($model->qualifyColumn('company_uuid'), $companyUuid);
    }

    /**
     * Extend the query builder with the withoutCompanyScope macro.
     *
     * @return void
     */
    public function extend(Builder $builder)
    {
        $this->addWithoutCompanyScope($builder);
    }

    /**
     * Add the withoutCompanyScope macro to the builder.
     *
     * @return void
     */
    protected function addWithoutCompanyScope(Builder $builder)
    {
        $builder->macro('withoutCompanyScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(CompanyScope::class);
        });
    }

    /**
     * Flush the column existence cache.
     * Useful in tests where tables may be created/dropped between cases.
     */
    public static function flushColumnCache(): void
    {
        static::$columnCache = [];
    }
}
