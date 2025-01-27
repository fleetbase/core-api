<?php

namespace Fleetbase\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataPurger
{
    /**
     * Delete all data related to a company.
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

            // Disable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            try {
                // Fetch all table names
                $tables = DB::select('SHOW TABLES');

                foreach ($tables as $table) {
                    $tableName = array_values((array) $table)[0]; // Get table name
                    $columns   = Schema::getColumnListing($tableName);

                    // Check if table has a `company_uuid` column
                    if (!in_array('company_uuid', $columns)) {
                        continue;
                    }

                    // Count rows to be deleted
                    $rowCount = DB::table($tableName)->where('company_uuid', $companyUuid)->count();

                    if ($rowCount > 0) {
                        // Delete rows associated with the company UUID
                        DB::table($tableName)->where('company_uuid', $companyUuid)->delete();

                        // Output verbose logs
                        if ($verbose) {
                            echo "Deleted {$rowCount} rows from {$tableName} for company_uuid {$companyUuid}.\n";
                        }
                    } elseif ($verbose) {
                        echo "No rows found in {$tableName} for company_uuid {$companyUuid}.\n";
                    }
                }
            } catch (\Exception $e) {
                // Re-enable foreign key checks in case of an error
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                throw $e;
            } finally {
                // Re-enable foreign key checks after processing
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
}
