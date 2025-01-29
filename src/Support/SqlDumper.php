<?php

namespace Fleetbase\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SqlDumper
{
    /**
     * Creates a dump file for the company data and stores it locally.
     *
     * @param \Fleetbase\Models\Company $company
     *
     * @return string the path to the created dump file
     */
    public static function createCompanyDump($company, ?string $fileName = null)
    {
        $companyName = str_replace([' ', '//'], '_', $company->name);
        $ownerEmail  = str_replace('//', '', $company->owner->email ?? '');
        $date        = now()->format('Y_m_d_His');
        $fileName    = $fileName ?? "{$companyName}_{$ownerEmail}_dump_{$date}.sql";
        $filePath    = storage_path("app/tmp/{$fileName}");

        // Ensure tmp directory exists
        if (!is_dir(storage_path('app/tmp'))) {
            mkdir(storage_path('app/tmp'), 0755, true);
        }

        // Generate SQL dump
        $dumpSql = self::getCompanyDumpSql($company);

        // Write dump to file
        file_put_contents($filePath, $dumpSql);

        return $filePath;
    }

    /**
     * Generates the SQL dump string for the company data, including foreign key relationships.
     *
     * @param \Fleetbase\Models\Company $company
     *
     * @return string the generated SQL dump string
     */
    public static function getCompanyDumpSql($company)
    {
        $companyUuid = $company->uuid;
        $connections = config('database.connections');
        $dump        = '';

        foreach ($connections as $connectionName => $config) {
            if ($config['driver'] !== 'mysql') {
                continue; // Skip non-MySQL connections
            }

            DB::setDefaultConnection($connectionName);
            $tables   = Schema::getAllTables();
            $dbPrefix = DB::getTablePrefix();

            // Store related records by their primary keys
            $relatedRecords = [];

            foreach ($tables as $table) {
                $tableName = $table->{"{$dbPrefix}Tables_in_" . $config['database']};
                if (in_array($tableName, ['activity', 'api_events', 'api_request_logs', 'webhook_request_logs', 'carts'])) {
                    continue;
                }

                $columns   = Schema::getColumnListing($tableName);
                if (in_array('company_uuid', $columns)) {
                    // Get records related to the company
                    $records = DB::table($tableName)->where('company_uuid', $companyUuid)->get();

                    if ($records->isEmpty()) {
                        continue;
                    }

                    $dump .= "-- Dumping data for table `{$tableName}`\n";
                    foreach ($records as $record) {
                        $values      = self::formatRecordValues((array) $record);
                        $columnsList = implode(', ', array_keys((array) $record));
                        $valuesList  = implode(', ', $values);
                        $dump .= "INSERT INTO `{$tableName}` ({$columnsList}) VALUES ({$valuesList});\n";

                        // Store primary keys of these records
                        $primaryKey = self::getPrimaryKey($columns);
                        if ($primaryKey && isset($record->{$primaryKey})) {
                            $relatedRecords[$tableName][] = $record->{$primaryKey};
                        }
                    }
                    $dump .= "\n";
                }
            }

            // Handle dependent records based on foreign keys
            foreach ($tables as $table) {
                $tableName = $table->{"{$dbPrefix}Tables_in_" . $config['database']};
                $columns   = Schema::getColumnListing($tableName);

                foreach ($columns as $column) {
                    foreach ($relatedRecords as $relatedTable => $primaryKeys) {
                        if (self::isForeignKey($column, $relatedTable)) {
                            // Fetch dependent records where the column value matches primary keys
                            $records = DB::table($tableName)
                                ->whereIn($column, $primaryKeys)
                                ->get();

                            if ($records->isEmpty()) {
                                continue;
                            }

                            $dump .= "-- Dumping dependent data for table `{$tableName}` (linked to `{$relatedTable}` via `{$column}`)\n";
                            foreach ($records as $record) {
                                $values      = self::formatRecordValues((array) $record);
                                $columnsList = implode(', ', array_keys((array) $record));
                                $valuesList  = implode(', ', $values);
                                $dump .= "INSERT INTO `{$tableName}` ({$columnsList}) VALUES ({$valuesList});\n";
                            }
                            $dump .= "\n";
                        }
                    }
                }
            }
        }

        return $dump;
    }

    /**
     * Determines the primary key of a table (prioritizing `uuid`, falling back to `id`).
     *
     * @return string|null
     */
    protected static function getPrimaryKey(array $columns)
    {
        return in_array('uuid', $columns) ? 'uuid' : (in_array('id', $columns) ? 'id' : null);
    }

    /**
     * Checks if a column is likely a foreign key referencing another table.
     *
     * @return bool
     */
    protected static function isForeignKey(string $column, string $relatedTable)
    {
        return str_ends_with($column, '_id') || str_ends_with($column, '_uuid') || strpos($column, $relatedTable) !== false;
    }

    /**
     * Formats record values for SQL insertion.
     *
     * @return array
     */
    protected static function formatRecordValues(array $record)
    {
        return array_map(function ($value) {
            return is_null($value) ? 'NULL' : "'" . addslashes($value) . "'";
        }, $record);
    }
}
