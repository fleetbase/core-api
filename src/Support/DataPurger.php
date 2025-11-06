<?php

namespace Fleetbase\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DataPurger.
 *
 * Purpose
 * -------
 * Perform a thorough purge of a Company's data across the database
 * while being fast, memory‑safe, and predictable.
 *
 * Highlights
 * ----------
 * - Deletes by `company_uuid` wherever it exists (primary fast path).
 * - Optional deep pass to remove rows that reference deleted rows via FK columns.
 * - Chunked deletes (no huge IN (...) lists; no full table hydration).
 * - Safe toggling of foreign key checks during hard deletes (MySQL/MariaDB).
 * - Verbose logging, dry‑run support, and per‑table result stats.
 *
 * Assumptions
 * -----------
 * - Most tables that should be purged include a `company_uuid` column.
 * - Database supports INFORMATION_SCHEMA views (MySQL/MariaDB/PostgreSQL). The class
 *   degrades gracefully if FK metadata is unavailable.
 */
class DataPurger
{
    /** @var array<string> Tables we will never touch. */
    protected array $skipTables = [
        // platform / framework tables
        'migrations',
        'failed_jobs',
        'password_resets',
        'personal_access_tokens',
        // role/permission libs
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        // horizon/telescope/queue/monitoring (sample)
        'jobs',
        'job_batches',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'monitored_scheduled_task_logs',
        'monitored_scheduled_task_log_items',
    ];

    /** @var array<string> Additional explicit exclusions of extension tables. */
    protected array $skipPrefixes = [];

    /** @var string Column to match company scope. */
    protected string $companyColumn = 'company_uuid';

    protected bool $verbose = false;

    protected bool $dryRun = false;

    /** @var bool When true, disable FK checks during hard delete (MySQL/MariaDB). */
    protected bool $disableForeignKeys = true;

    protected ConnectionInterface|Connection $db;

    /** @var \Closure|null */
    protected $logger;

    public function __construct(?ConnectionInterface $db = null, ?\Closure $logger = null)
    {
        $this->db     = $db ?: DB::connection(); /** @var Connection|ConnectionInterface $db */
        $this->logger = $logger;
    }

    /**
     * Purge a company's data across the database.
     *
     * @param string $companyUuid       target company UUID
     * @param bool   $deleteCompanyRow  also delete the company row itself (hard delete)
     * @param bool   $deepReferencePass after primary pass, delete rows that reference deleted rows via FK columns
     * @param bool   $verbose           emit per‑table logs
     * @param bool   $dryRun            plan only; do not execute destructive queries
     *
     * @return array{tables: array<string,int>, total:int}
     */
    public function purgeCompany(
        string $companyUuid,
        bool $deleteCompanyRow = true,
        bool $deepReferencePass = false,
        bool $verbose = false,
        bool $dryRun = false,
    ): array {
        $this->verbose = $verbose;
        $this->dryRun  = $dryRun;

        $this->log('Starting purge', ['company' => $companyUuid, 'deep' => $deepReferencePass, 'dry_run' => $dryRun]);

        $tables = $this->listTenantTables();

        $resultPerTable = [];
        $totalDeleted   = 0;

        $this->db->beginTransaction();
        try {
            if ($this->disableForeignKeys) {
                $this->toggleForeignKeys(false);
            }

            // Primary fast path: delete by company_uuid everywhere it exists
            foreach ($tables as $table) {
                if (!Schema::hasColumn($table, $this->companyColumn)) {
                    continue;
                }

                $deleted                = $this->deleteByCompanyColumn($table, $this->companyColumn, $companyUuid);
                $resultPerTable[$table] = $deleted;
                $totalDeleted += $deleted;
            }

            // Optional deep pass: remove rows referencing deleted rows
            if ($deepReferencePass) {
                $deepDeleted = $this->deepReferenceCleanup($companyUuid);
                foreach ($deepDeleted as $table => $count) {
                    if (!isset($resultPerTable[$table])) {
                        $resultPerTable[$table] = 0;
                    }
                    $resultPerTable[$table] += $count;
                    $totalDeleted += $count;
                }
            }

            // Finally, company row (if requested)
            if ($deleteCompanyRow && Schema::hasTable('companies')) {
                $deleted                     = $this->deleteRows('companies', fn ($q) => $q->where('uuid', $companyUuid));
                $resultPerTable['companies'] = ($resultPerTable['companies'] ?? 0) + $deleted;
                $totalDeleted += $deleted;
            }

            if ($this->disableForeignKeys) {
                $this->toggleForeignKeys(true);
            }

            if ($this->dryRun) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->log('Purge failed', ['error' => $e->getMessage()], 'error');
            throw $e;
        }

        $this->log('Purge complete', ['total_deleted' => $totalDeleted]);

        return ['tables' => $resultPerTable, 'total' => $totalDeleted];
    }

    /**
     * Delete rows from a table by matching the tenant column in fixed-size chunks.
     */
    protected function deleteByCompanyColumn(string $table, string $column, string $companyUuid): int
    {
        $this->log("Purging {$table} by {$column} = {$companyUuid}");

        // No COUNT(*) upfront to save a full scan; we loop until no more deletions.
        $total = 0;
        while (true) {
            if ($this->dryRun) {
                // Simulate one batch to keep logs meaningful without scanning the table.
                break;
            }
            $deleted = $this->db->table($table)
                ->where($column, $companyUuid)
                ->limit(1000)
                ->delete();
            if ($deleted === 0) {
                break;
            }
            $total += $deleted;
        }

        return $total;
    }

    /**
     * OPTIONAL deep pass:
     * Attempt to delete rows in tables that do not have company_uuid, but reference rows
     * from tables that were already purged (by company_uuid) using FK columns named *_id/*_uuid.
     *
     * This inspects information_schema to find FKs referencing tables that have company_uuid.
     * For each FK, it deletes rows whose FK value is now orphaned by the purge.
     *
     * @return array<string,int> count deleted per table
     */
    protected function deepReferenceCleanup(string $companyUuid): array
    {
        $deletedPerTable = [];

        // Find all tables that DO have company_uuid (purged in the primary pass).
        $tenantTables = $this->listTenantTables()->filter(fn ($t) => Schema::hasColumn($t, $this->companyColumn))->values();

        // For each such table, get its PK column (uuid preferred, else id).
        $tablePk = [];
        foreach ($tenantTables as $t) {
            $tablePk[$t] = $this->detectKey($t);
        }

        // For each foreign key that points to those tables, delete rows whose FK
        // refers to a row owned by this company. (We re-evaluate the FK values in batches.)
        foreach ($this->listForeignKeysReferencing($tenantTables->all()) as $fk) {
            [$childTable, $childColumn, $parentTable, $parentColumn] = $fk;

            // Skip if parent PK couldn't be determined
            $parentKey = $tablePk[$parentTable] ?? null;
            if (!$parentKey) {
                continue;
            }

            $this->log("Deep cleanup {$childTable}.{$childColumn} -> {$parentTable}.{$parentColumn}");

            $total = 0;
            while (true) {
                if ($this->dryRun) {
                    break;
                }
                $ids = $this->db->table($parentTable)
                    ->where($this->companyColumn, $companyUuid)
                    ->limit(1000)
                    ->pluck($parentColumn)
                    ->all();

                if (empty($ids)) {
                    break;
                }

                $deleted = $this->db->table($childTable)->whereIn($childColumn, $ids)->delete();
                if ($deleted === 0) {
                    break;
                }
                $total += $deleted;
            }

            if ($total > 0) {
                $deletedPerTable[$childTable] = ($deletedPerTable[$childTable] ?? 0) + $total;
            }
        }

        return $deletedPerTable;
    }

    /**
     * List all app tables that we are allowed to touch.
     *
     * @return Collection<string>
     */
    protected function listTenantTables(): Collection
    {
        $driver = $this->db->getDriverName();

        $tables = collect();

        if (method_exists(Schema::getConnection(), 'getDoctrineSchemaManager')) {
            // Doctrine path (works for most drivers)
            try {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                foreach ($sm->listTableNames() as $t) {
                    $tables->push($t);
                }
            } catch (\Throwable $e) {
                // fallback below
            }
        }

        if ($tables->isEmpty()) {
            // Fallback per driver
            if ($driver === 'mysql') {
                $dbName = $this->db->getDatabaseName();
                $tables = $this->db->table('information_schema.tables')
                    ->where('table_schema', $dbName)
                    ->pluck('table_name');
            } elseif ($driver === 'pgsql') {
                $tables = $this->db->table('pg_catalog.pg_tables')
                    ->where('schemaname', 'public')
                    ->pluck('tablename');
            } else {
                // last resort: let Laravel try (may be driver-specific)
                $tables = collect(Schema::getAllTables() ?? [])
                    ->map(function ($row) {
                        if (is_object($row) && isset($row->name)) {
                            return $row->name;
                        }
                        if (is_array($row) && isset($row['name'])) {
                            return $row['name'];
                        }

                        return (string) $row;
                    });
            }
        }

        // Filter out known non-tenant tables/prefixes
        return $tables->filter(function ($t) {
            if (in_array($t, $this->skipTables, true)) {
                return false;
            }
            foreach ($this->skipPrefixes as $prefix) {
                if (Str::startsWith($t, $prefix)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Detect a sensible key column for a table. Prefers 'uuid', else 'id'.
     */
    protected function detectKey(string $table): ?string
    {
        $cols = Schema::getColumnListing($table);
        if (in_array('uuid', $cols, true)) {
            return 'uuid';
        }
        if (in_array('id', $cols, true)) {
            return 'id';
        }

        return null;
    }

    /**
     * Discover foreign keys that reference any of the given parent tables.
     *
     * @param array<string> $parentTables
     *
     * @return array<int,array{0:string,1:string,2:string,3:string}> [child_table, child_column, parent_table, parent_column]
     */
    protected function listForeignKeysReferencing(array $parentTables): array
    {
        $driver = $this->db->getDriverName();
        $result = [];

        if ($driver === 'mysql') {
            $dbName = $this->db->getDatabaseName();
            $rows   = $this->db->table('information_schema.KEY_COLUMN_USAGE')
                ->select('TABLE_NAME as child_table', 'COLUMN_NAME as child_column', 'REFERENCED_TABLE_NAME as parent_table', 'REFERENCED_COLUMN_NAME as parent_column')
                ->where('TABLE_SCHEMA', $dbName)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->get();

            foreach ($rows as $r) {
                if (in_array($r->parent_table, $parentTables, true)) {
                    $result[] = [$r->child_table, $r->child_column, $r->parent_table, $r->parent_column];
                }
            }
        } elseif ($driver === 'pgsql') {
            $sql = "
                SELECT
                    tc.table_name  AS child_table,
                    kcu.column_name AS child_column,
                    ccu.table_name  AS parent_table,
                    ccu.column_name AS parent_column
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                  ON tc.constraint_name = kcu.constraint_name
                 AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage AS ccu
                  ON ccu.constraint_name = tc.constraint_name
                 AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
            ";
            $rows = collect($this->db->select($sql));
            foreach ($rows as $r) {
                if (in_array($r->parent_table, $parentTables, true)) {
                    $result[] = [$r->child_table, $r->child_column, $r->parent_table, $r->parent_column];
                }
            }
        }

        return $result;
    }

    /**
     * Low-level delete helper with a closure filter, processed in chunks when possible.
     *
     * @param \Closure $where closure receives a \Illuminate\Database\Query\Builder
     */
    protected function deleteRows(string $table, \Closure $where, int $batch = 1000): int
    {
        $total = 0;
        while (true) {
            if ($this->dryRun) {
                break;
            }
            $query = $this->db->table($table);
            $where($query);
            $deleted = $query->limit($batch)->delete();
            if ($deleted === 0) {
                break;
            }
            $total += $deleted;
        }

        return $total;
    }

    /**
     * Toggle foreign key checks for MySQL/MariaDB; no-ops for others.
     */
    protected function toggleForeignKeys(bool $enable): void
    {
        $driver = $this->db->getDriverName();
        if ($driver === 'mysql') {
            $this->db->statement('SET FOREIGN_KEY_CHECKS = ' . ($enable ? '1' : '0'));
        }
    }

    protected function log(string $msg, array $ctx = [], string $level = 'info'): void
    {
        if ($this->verbose || $level === 'error') {
            if ($this->logger instanceof \Closure) {
                ($this->logger)($msg, $ctx, $level);
            } else {
                Log::{$level}('[DataPurger] ' . $msg, $ctx);
            }
        }
    }
}
