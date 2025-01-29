<?php

namespace Fleetbase\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DataPurger
{
    /**
     * Delete all data related to a company, including foreign key relationships.
     *
     * @param \Fleetbase\Models\Company $company
     * @param bool                      $deletePermanently whether to permanently delete the company or soft delete
     * @param bool                      $verbose           whether to output detailed logs during deletion
     */
    public static function deleteCompanyData($company, bool $deletePermanently = true, bool $verbose = false)
    {
        $companyUuid       = $company->uuid;
        $defaultConnection = 'mysql';
        $connections       = config('database.connections');

        foreach ($connections as $connectionName => $config) {
            if ($config['driver'] !== 'mysql') {
                continue; // Skip non-MySQL connections
            }

            DB::setDefaultConnection($connectionName);

            // Disable foreign key checks for safe deletion
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            try {
                // Fetch all table names
                $tables = DB::select('SHOW TABLES');

                // Track related records for deletion
                $relatedRecords = [];

                foreach ($tables as $table) {
                    $tableName = array_values((array) $table)[0]; // Get table name
                    $columns   = Schema::getColumnListing($tableName);

                    // Skip system tables
                    if (Str::startsWith($tableName, ['registry_', 'billing_'])) {
                        continue;
                    }

                    // Check if table has a `company_uuid` column (direct deletion)
                    if (in_array('company_uuid', $columns)) {
                        $rowCount = DB::table($tableName)->where('company_uuid', $companyUuid)->count();

                        if ($rowCount > 0) {
                            // Store related record primary keys for cascade deletion
                            $primaryKey = self::getPrimaryKey($columns);
                            if ($primaryKey) {
                                $relatedRecords[$tableName] = DB::table($tableName)
                                    ->where('company_uuid', $companyUuid)
                                    ->pluck($primaryKey)
                                    ->toArray();
                            }

                            // Delete the records
                            DB::table($tableName)->where('company_uuid', $companyUuid)->delete();

                            if ($verbose) {
                                echo "Deleted {$rowCount} rows from {$tableName} for company_uuid {$companyUuid}.\n";
                            }
                        } elseif ($verbose) {
                            echo "No rows found in {$tableName} for company_uuid {$companyUuid}.\n";
                        }
                    }
                }

                // Handle dependent records by foreign keys
                self::deleteRelatedRecords($relatedRecords, $verbose);
            } catch (\Exception $e) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                throw $e;
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }

        // Reset to the original default connection
        DB::setDefaultConnection($defaultConnection);

        // Delete the company record itself
        if ($deletePermanently) {
            $deletedRows = DB::delete('DELETE FROM companies WHERE uuid = ?', [$companyUuid]);

            if ($verbose) {
                if ($deletedRows) {
                    echo "Permanently deleted company record for UUID {$companyUuid}.\n";
                } else {
                    echo "Failed to delete company record for UUID {$companyUuid}. It may not exist or could not be found.\n";
                }
            }
        } else {
            $company->delete(); // Soft delete

            if ($verbose) {
                echo "Soft deleted company record for UUID {$companyUuid}.\n";
            }
        }
    }

    /**
     * Deletes records from tables that reference previously deleted records.
     *
     * @param array $relatedRecords an associative array of table names and their primary keys to delete
     * @param bool  $verbose        whether to output logs
     */
    protected static function deleteRelatedRecords(array $relatedRecords, bool $verbose = false)
    {
        foreach ($relatedRecords as $table => $primaryKeys) {
            $columns = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                if (self::isForeignKey($column, $table)) {
                    // Find dependent records
                    $dependentRecords = DB::table($table)
                        ->whereIn($column, $primaryKeys)
                        ->pluck(self::getPrimaryKey($columns))
                        ->toArray();

                    if (!empty($dependentRecords)) {
                        DB::table($table)->whereIn($column, $primaryKeys)->delete();

                        if ($verbose) {
                            echo 'Deleted ' . count($dependentRecords) . " dependent records from {$table} where {$column} matched deleted primary keys.\n";
                        }
                    }
                }
            }
        }
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
}
