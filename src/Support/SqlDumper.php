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
     * Generates the SQL dump string for the company data.
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

            foreach ($tables as $table) {
                $tableName = $table->{"{$dbPrefix}Tables_in_" . $config['database']};
                $columns   = Schema::getColumnListing($tableName);

                if (!in_array('company_uuid', $columns)) {
                    continue;
                }

                $records = DB::table($tableName)->where('company_uuid', $companyUuid)->get();

                if ($records->isEmpty()) {
                    continue;
                }

                $dump .= "-- Dumping data for table `{$tableName}`\n";
                foreach ($records as $record) {
                    $values = array_map(function ($value) {
                        return is_null($value) ? 'NULL' : "'" . addslashes($value) . "'";
                    }, (array) $record);

                    $columnsList = implode(', ', array_keys((array) $record));
                    $valuesList  = implode(', ', $values);
                    $dump .= "INSERT INTO `{$tableName}` ({$columnsList}) VALUES ({$valuesList});\n";
                }
                $dump .= "\n";
            }
        }

        return $dump;
    }
}
