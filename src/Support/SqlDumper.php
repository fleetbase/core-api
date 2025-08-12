<?php

namespace Fleetbase\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SqlDumper.
 *
 * Creates a SQL INSERT dump for all rows related to a given Company,
 * while keeping memory usage low and being resilient for large datasets.
 *
 * Design goals
 *  - Preserve existing public API:
 *      - createCompanyDump(\Fleetbase\Models\Company $company, ?string $fileName = null): string
 *      - getCompanyDumpSql($company): string
 *  - Stream to disk in chunks (no giant in-memory strings).
 *  - Prefer real table/column discovery; degrade gracefully.
 *  - Heuristic secondary pass to include dependent rows in tables without company scope.
 *
 * Notes
 *  - We emit INSERT statements only (no schema). Consumers can import into an existing DB.
 *  - Output is ANSI SQL-ish (backticks for identifiers for MySQL compatibility).
 *  - Values are best-effort quoted; numerics are left bare, NULL is unquoted.
 */
class SqlDumper
{
    /** Chunk size for selecting rows per table. */
    protected int $chunk = 1000;

    protected ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null, int $chunk = 1000)
    {
        $this->db    = $db ?: DB::connection();
        $this->chunk = max(100, $chunk);
    }

    /**
     * Creates a dump file for the company data and stores it locally.
     *
     * @param \Fleetbase\Models\Company $company
     * @param string|null               $fileName Optional absolute or storage_path()-relative path.
     *                                            Defaults to storage_path('app/tmp/company_<uuid>_<timestamp>.sql').
     *
     * @return string Absolute path to the created dump file
     */
    public static function createCompanyDump($company, ?string $fileName = null)
    {
        $self = new static();
        $uuid = (string) $company->uuid;

        $fileName = $fileName ?: storage_path('app/tmp/company_' . $uuid . '_' . now()->format('Ymd_His') . '.sql');
        @mkdir(dirname($fileName), 0775, true);

        // Write header
        file_put_contents($fileName, "-- Fleetbase SQL Dump for company {$uuid}\n-- Generated at " . now()->toDateTimeString() . "\n\n");

        $self->dumpByCompany($uuid, $fileName);

        return $fileName;
    }

    /**
     * Returns the SQL content for the company's dump.
     * Warning: For very large datasets, prefer createCompanyDump() and stream from disk.
     *
     * @param \Fleetbase\Models\Company $company
     *
     * @return string
     */
    public static function getCompanyDumpSql($company)
    {
        $path = static::createCompanyDump($company);
        try {
            return file_get_contents($path) ?: '';
        } finally {
            // Leave the temporary file on disk for auditing; comment next line to remove it.
            // @unlink($path);
        }
    }

    /**
     * Main streaming routine: write INSERTs for all tables matching company_uuid
     * and attempt to include dependent rows from related tables (secondary pass).
     */
    protected function dumpByCompany(string $companyUuid, string $filePath): void
    {
        $dbName = $this->db->getDatabaseName();

        $tables             = $this->listTables($dbName);
        $relatedPrimaryKeys = []; // table => set of PKs included, used for dependent tables without company_uuid

        // Primary pass: tables with company_uuid
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, 'company_uuid')) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (empty($columns)) {
                continue;
            }

            $this->streamTableForCompany($table, $columns, $companyUuid, $filePath, $relatedPrimaryKeys);
        }

        // Secondary pass: tables without company_uuid but with FK-ish columns pointing to already dumped tables
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'company_uuid')) {
                // already handled
                continue;
            }
            $columns = Schema::getColumnListing($table);
            if (empty($columns)) {
                continue;
            }

            // For each related table we dumped, look for columns like {table}_id or {table}_uuid (singular/plural/snake-safe)
            $fkMatches = $this->guessForeignKeyMatches($table, $columns, array_keys($relatedPrimaryKeys));
            if (empty($fkMatches)) {
                continue;
            }

            // For each FK match, delete duplicates by merging sets
            $fkMatches = array_unique($fkMatches);

            // Build WHERE fk IN (...) OR fk2 IN (...) ... per batch
            // To avoid huge IN lists, process per related table in chunks.
            foreach ($fkMatches as $fk) {
                $parents = $this->collectPrimaryKeysForFk($fk, $relatedPrimaryKeys);
                if (empty($parents)) {
                    continue;
                }

                // Stream rows in chunks where $fk in (batch)
                $this->streamTableByForeignSet($table, $columns, $fk, $parents, $filePath);
            }
        }
    }

    /**
     * Stream a table's rows scoped by company_uuid, writing batched INSERTs.
     * Track the primary keys dumped into $relatedPrimaryKeys for dependency pass.
     *
     * @param array<string>                    $columns
     * @param array<string,array<string,bool>> $relatedPrimaryKeys ref map of table => pk => true
     */
    protected function streamTableForCompany(string $table, array $columns, string $companyUuid, string $filePath, array &$relatedPrimaryKeys): void
    {
        $pk         = static::getPrimaryKey($columns) ?? $columns[0];
        $quotedCols = $this->quoteIdentifiers($columns);

        $last = null;
        while (true) {
            $q = $this->db->table($table)
                ->where('company_uuid', (string) $companyUuid)
                ->orderBy($pk)
                ->limit($this->chunk);

            if ($last !== null) {
                // keyset pagination: strictly greater than last seen key
                $q->where($pk, '>', $last);
            }

            $rows = $q->get();
            if ($rows->isEmpty()) {
                break;
            }

            $values = [];
            foreach ($rows as $row) {
                $arr      = (array) $row;
                $values[] = '(' . implode(', ', static::formatRecordValues($arr)) . ')';

                if (isset($arr[$pk])) {
                    $relatedPrimaryKeys[$table]                     = $relatedPrimaryKeys[$table] ?? [];
                    $relatedPrimaryKeys[$table][(string) $arr[$pk]] = true;
                    $last                                           = (string) $arr[$pk]; // advance cursor
                }
            }

            $insert = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedCols) . ")\nVALUES\n" . implode(",\n", $values) . ";\n\n";
            file_put_contents($filePath, $insert, FILE_APPEND);

            // If pk wasn’t present, we can’t advance reliably; bail to avoid loops
            if ($pk === null) {
                break;
            }
        }
    }

    /**
     * Stream rows from $table where $fkColumn is in $parents (set of IDs), writing batched INSERTs.
     *
     * @param array<string>      $columns
     * @param array<string,bool> $parents
     */
    protected function streamTableByForeignSet(string $table, array $columns, string $fkColumn, array $parents, string $filePath): void
    {
        if (!in_array($fkColumn, $columns, true)) {
            return;
        }

        $quotedCols = $this->quoteIdentifiers($columns);

        // chunk the parent ids
        $parentIds = array_keys($parents);
        $batchSize = 1000;
        for ($i = 0; $i < count($parentIds); $i += $batchSize) {
            $batch = array_slice($parentIds, $i, $batchSize);

            $rows = $this->db->table($table)
                ->whereIn($fkColumn, $batch)
                ->limit($this->chunk)
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $values = [];
            foreach ($rows as $row) {
                $arr      = (array) $row;
                $values[] = '(' . implode(', ', static::formatRecordValues($arr)) . ')';
            }

            $insert = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedCols) . ")\nVALUES\n" . implode(",\n", $values) . ";\n\n";
            file_put_contents($filePath, $insert, FILE_APPEND);
        }
    }

    /**
     * Guess likely FK columns on $table that reference any of $relatedTables.
     *
     * @param array<string> $columns
     * @param array<string> $relatedTables
     *
     * @return array<string> list of column names likely acting as FKs
     */
    protected function guessForeignKeyMatches(string $table, array $columns, array $relatedTables): array
    {
        $matches = [];

        foreach ($relatedTables as $parent) {
            foreach ($columns as $col) {
                if (static::isForeignKey($col, $parent)) {
                    $matches[] = $col;
                }
            }
        }

        return $matches;
    }

    /**
     * Merge primary key sets across tables that map to a given FK column.
     *
     * @param array<string,array<string,bool>> $relatedPrimaryKeys
     *
     * @return array<string,bool>
     */
    protected function collectPrimaryKeysForFk(string $fkColumn, array $relatedPrimaryKeys): array
    {
        $merged = [];
        foreach ($relatedPrimaryKeys as $table => $set) {
            // only merge sets that this FK could plausibly reference
            if (static::isForeignKey($fkColumn, $table)) {
                foreach ($set as $id => $_true) {
                    $merged[$id] = true;
                }
            }
        }

        return $merged;
    }

    /**
     * Quote identifier list with backticks (MySQL compatible).
     *
     * @param array<string> $columns
     *
     * @return array<string>
     */
    protected function quoteIdentifiers(array $columns): array
    {
        return array_map(function ($c) {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $columns);
    }

    /**
     * Return a best-effort primary key for the given column list.
     * Prefers 'uuid', else 'id', else null.
     */
    protected static function getPrimaryKey(array $columns)
    {
        if (in_array('id', $columns, true)) {
            return 'id';
        }
        if (in_array('uuid', $columns, true)) {
            return 'uuid';
        }

        return null;
    }

    /**
     * Heuristic check if a column name looks like a FK to $relatedTable.
     * Matches patterns like: related_table_id, related_table_uuid, relatedtable_id, singular_id, etc.
     */
    protected static function isForeignKey(string $column, string $relatedTable)
    {
        $c = Str::snake($column);
        $t = Str::snake($relatedTable);

        $candidates = [
            $t . '_id',
            $t . '_uuid',
            Str::singular($t) . '_id',
            Str::singular($t) . '_uuid',
            str_replace('_', '', $t) . '_id',
            str_replace('_', '', $t) . '_uuid',
        ];

        return in_array($c, $candidates, true);
    }

    /**
     * Formats record values for SQL insertion.
     *
     * - NULL -> NULL
     * - booleans -> 0/1
     * - numeric strings -> as-is
     * - everything else -> single-quoted with basic escaping
     *
     * @return array<string>
     */
    protected static function formatRecordValues(array $record)
    {
        return array_map(function ($value) {
            if (is_null($value)) {
                return 'NULL';
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            // numeric (int/float) or numeric-string (but not leading zero like '0123')
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            if (is_string($value) && is_numeric($value) && !preg_match('/^0[0-9]/', $value)) {
                return $value;
            }

            // fallback: quote string
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $record);
    }

    /**
     * List tables for the current database.
     *
     * @return array<int,string>
     */
    protected function listTables(string $dbName): array
    {
        $driver = $this->db->getDriverName();

        if ($driver === 'mysql') {
            $rows = $this->db->select('SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = "BASE TABLE"', [$dbName]);

            return array_map(fn ($r) => (string) $r->TABLE_NAME, $rows);
        }

        if ($driver === 'pgsql') {
            $rows = $this->db->select("SELECT tablename as TABLE_NAME FROM pg_catalog.pg_tables WHERE schemaname = 'public'");

            return array_map(fn ($r) => (string) $r->TABLE_NAME, $rows);
        }

        // Fallback: try Doctrine or Schema
        if (method_exists(Schema::getConnection(), 'getDoctrineSchemaManager')) {
            try {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();

                return $sm->listTableNames();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $all = Schema::getAllTables();
        $out = [];
        foreach ($all as $row) {
            if (is_object($row)) {
                $out[] = $row->name ?? (string) ($row->TABLE_NAME ?? '');
            } elseif (is_array($row)) {
                $out[] = $row['name'] ?? (string) ($row['TABLE_NAME'] ?? '');
            } else {
                $out[] = (string) $row;
            }
        }

        return array_values(array_filter($out));
    }
}
