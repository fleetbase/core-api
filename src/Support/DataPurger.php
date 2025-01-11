<?php

namespace Fleetbase\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataPurger
{
    public static function deleteCompanyData($company)
    {
        $companyUuid = $company->uuid;

        $connections = config('database.connections');

        foreach ($connections as $connectionName => $config) {
            if ($config['driver'] !== 'mysql') {
                continue; // Skip non-MySQL connections
            }

            DB::setDefaultConnection($connectionName);

            // Disable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            try {
                $tables   = Schema::getAllTables();
                $dbPrefix = DB::getTablePrefix();

                foreach ($tables as $table) {
                    $tableName = $table->{"{$dbPrefix}Tables_in_" . $config['database']};
                    $columns   = Schema::getColumnListing($tableName);

                    if (!in_array('company_uuid', $columns)) {
                        continue;
                    }

                    DB::table($tableName)->where('company_uuid', $companyUuid)->delete();
                }
            } catch (\Exception $e) {
                // Re-enable foreign key checks in case of an error
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                throw $e;
            }
        }

        // Re-enable foreign key checks after deletion
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $company->delete(); // Delete the company record itself
    }
}
