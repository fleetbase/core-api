<?php

namespace Fleetbase\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

trait PurgeCommand
{
    use ForcesCommands;

    /**
     * Backup and delete records for a given model and table.
     *
     * @param \Illuminate\Support\Carbon $cutoffDate
     */
    protected function backupAndDelete(string $modelClass, string $tableName, $cutoffDate, string $path = 'backups'): void
    {
        // Fetch records older than the cutoff date
        $query = $modelClass::where('created_at', '<', $cutoffDate);

        // Include trashed if possible
        if (Schema::hasColumn($tableName, 'deleted_at')) {
            $query->where(function ($query) {
                $query->whereNull('deleted_at');
                $query->orWhereNotNull('deleted_at');
            });
        }

        // Get records
        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info("No records to purge from {$tableName}.");

            return;
        }

        $currentDate     = now()->toDateTimeString();
        $firstRecordDate = $records->min('created_at');
        $lastRecordDate  = $records->max('created_at');
        $this->info("Found {$records->count()} records in {$tableName} from {$firstRecordDate} to {$lastRecordDate}.");

        if (!$this->confirmOrForce("Do you want to permanently delete these records from {$tableName}?")) {
            $this->warn("Skipped purging {$tableName}.");

            return;
        }

        $this->info("Backing up {$records->count()} records from {$tableName}...");

        // Create SQL dump
        $sqlDumpFileName = str_replace([' ', ':'], '_', "{$tableName}_backup_{$firstRecordDate}_to_{$lastRecordDate}_created_{$currentDate}.sql");
        $localPath       = storage_path("app/tmp/{$sqlDumpFileName}");
        $remotePath      = "{$path}/{$sqlDumpFileName}";
        $backupData      = $records->map(function ($record) {
            return (array) $record->getAttributes();
        })->toArray();

        $this->createSqlDump($tableName, $backupData, $localPath);

        // Upload to default filesystem
        Storage::put($remotePath, file_get_contents($localPath));
        $this->info("Backup saved to storage as {$sqlDumpFileName}.");

        // Delete records
        $this->hardDelete($tableName, $cutoffDate);
        $this->info("Purged records from {$tableName}.");

        // Reset auto-increment index
        // $this->resetTableIndex($tableName);
        // $this->info("Reset auto-increment index for {$tableName}.");
    }

    /**
     * Hard delete records from the table.
     *
     * @param \Illuminate\Support\Carbon $cutoffDate
     */
    protected function hardDelete(string $tableName, $cutoffDate): void
    {
        DB::table($tableName)->where('created_at', '<', $cutoffDate)->delete();
    }

    /**
     * Create an SQL dump for the given records.
     */
    protected function createSqlDump(string $tableName, array $data, string $fileName): void
    {
        $dump = "INSERT INTO `{$tableName}` (" . implode(', ', array_keys($data[0])) . ") VALUES\n";

        $values = array_map(function ($record) {
            $escapedValues = array_map(function ($value) {
                return is_null($value) ? 'NULL' : "'" . addslashes($value) . "'";
            }, array_values($record));

            return '(' . implode(', ', $escapedValues) . ')';
        }, $data);

        $dump .= implode(",\n", $values) . ";\n";

        file_put_contents($fileName, $dump);
    }

    /**
     * Reset the auto-increment index for a given table.
     */
    protected function resetTableIndex(string $tableName): void
    {
        $table = DB::getTablePrefix() . $tableName;
        DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1;");
    }
}
