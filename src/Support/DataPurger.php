<?php

namespace Fleetbase\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DataPurger
{
    protected static array $skipColumns = ['companies', 'registry_', 'billing_', 'model_has', 'role_has', 'monitored_scheduled_task_log_items'];

    /**
     * Delete all data related to a company, including foreign key relationships.
     *
     * @param \Fleetbase\Models\Company $company
     * @param bool                      $deletePermanently whether to permanently delete the company or soft delete
     * @param bool                      $verbose           whether to output detailed logs during deletion
     * @param callable|null             $progressCallback  Optional callback to track progress
     */
    public static function deleteCompanyData($company, bool $deletePermanently = true, bool $verbose = false, ?callable $progressCallback = null)
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
                $tables          = DB::select('SHOW TABLES');
                $totalTables     = count($tables);
                $tablesProcessed = 0;

                // Track related records for deletion
                $relatedRecords = [];

                foreach ($tables as $table) {
                    $tableName = array_values((array) $table)[0]; // Get table name
                    $columns   = Schema::getColumnListing($tableName);

                    // Skip system tables
                    if (Str::startsWith($tableName, static::$skipColumns)) {
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

                    // Update progress
                    $tablesProcessed++;
                    $progress = round(($tablesProcessed / $totalTables) * 100, 2);
                    if ($progressCallback) {
                        $progressCallback($progress, $tableName, $rowCount);
                    }
                }

                // Handle dependent records by foreign keys
                self::deleteRelatedRecords($relatedRecords, $verbose, $progressCallback);
            } catch (\Exception $e) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                throw $e;
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }

        // Reset to the original default connection
        DB::setDefaultConnection($defaultConnection);
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Delete the company record itself
        if ($deletePermanently) {
            $deletedRows = DB::delete('DELETE FROM companies WHERE uuid = ?', [$companyUuid]);

            if ($progressCallback) {
                $progressCallback(100, 'companies', $deletedRows);
            }

            if ($verbose) {
                echo $deletedRows
                    ? "Permanently deleted company record for UUID {$companyUuid}.\n"
                    : "Failed to delete company record for UUID {$companyUuid}.\n";
            }
        } else {
            try {
                $company->delete(); // Soft delete
            } catch (\Exception $e) {
                echo $e->getMessage();
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');

                return;
            }

            if ($verbose) {
                echo "Soft deleted company record for UUID {$companyUuid}.\n";
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Deletes records from tables that reference previously deleted records.
     *
     * @param array         $relatedRecords   an associative array of table names and their primary keys to delete
     * @param bool          $verbose          whether to output logs
     * @param callable|null $progressCallback Optional callback for progress tracking
     */
    protected static function deleteRelatedRecords(array $relatedRecords, bool $verbose = false, ?callable $progressCallback = null)
    {
        $processedTables = [];
        $totalTables     = count($relatedRecords);
        $tablesProcessed = 0;

        // Cache table schemas to avoid redundant queries
        $allTables   = collect(Schema::getAllTables())->mapWithKeys(fn ($table) => [array_values((array) $table)[0] => true]);
        $columnCache = [];

        foreach ($relatedRecords as $table => $primaryKeys) {
            if ($verbose) {
                echo "Checking related records in table: {$table}\n";
            }

            // Cache columns for this table
            if (!isset($columnCache[$table])) {
                $columnCache[$table] = Schema::getColumnListing($table);
            }
            $columns = $columnCache[$table];

            if (empty($columns)) {
                continue;
            }

            foreach ($allTables as $relatedTableName => $_) {
                // Skip system tables
                if (Str::startsWith($relatedTableName, static::$skipColumns)) {
                    continue;
                }

                if (in_array($relatedTableName, $processedTables)) {
                    continue;
                }

                // Cache columns for related table
                if (!isset($columnCache[$relatedTableName])) {
                    $columnCache[$relatedTableName] = Schema::getColumnListing($relatedTableName);
                }
                $relatedColumns = $columnCache[$relatedTableName];

                if (empty($relatedColumns)) {
                    continue;
                }

                $primaryKey = self::getPrimaryKey($relatedColumns);
                if (!$primaryKey) {
                    continue;
                }

                // Collect all related foreign keys for batch deletion
                $foreignKeyMatches = [];
                foreach ($relatedColumns as $relatedColumn) {
                    if (self::isForeignKey($relatedColumn, $table)) {
                        $foreignKeyMatches[] = $relatedColumn;
                    }
                }

                if (!empty($foreignKeyMatches)) {
                    foreach ($foreignKeyMatches as $foreignKey) {
                        $dependentRecords = DB::table($relatedTableName)
                            ->whereIn($foreignKey, $primaryKeys)
                            ->pluck($primaryKey)
                            ->toArray();

                        if (!empty($dependentRecords)) {
                            // Batch delete instead of multiple queries
                            DB::table($relatedTableName)->whereIn($foreignKey, $primaryKeys)->delete();
                            $processedTables[] = $relatedTableName;

                            if ($verbose) {
                                echo 'Deleted ' . count($dependentRecords) . " records from {$relatedTableName} where {$foreignKey} matched deleted primary keys.\n";
                            }

                            // Update progress
                            $tablesProcessed++;
                            $progress = round(($tablesProcessed / $totalTables) * 100, 2);
                            if ($progressCallback) {
                                $progressCallback($progress, $relatedTableName, count($dependentRecords));
                            }
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
        $primaryKey = in_array('uuid', $columns) ? 'uuid' : (in_array('id', $columns) ? 'id' : null);

        if (!$primaryKey) {
            echo 'Warning: Could not determine primary key from columns: ' . implode(',', $columns) . "\n";
        }

        return $primaryKey;
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
