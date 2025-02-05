<?php

namespace Fleetbase\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeOrphanedModelRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:orphaned-model-records';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes orphaned records from model_has_roles, model_has_policies, and model_has_permissions where the referenced model no longer exists or is soft deleted.';

    /**
     * The tables to clean.
     *
     * @var array
     */
    protected $tables = [
        'model_has_roles',
        'model_has_policies',
        'model_has_permissions',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting orphaned model record purge...');

        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                $this->warn("Table {$table} does not exist, skipping...");
                continue;
            }

            $this->purgeTable($table);
        }

        $this->info('Purge process completed.');
    }

    /**
     * Purges orphaned records from a given table.
     */
    protected function purgeTable(string $table)
    {
        $this->info("Checking table: {$table}...");

        $query = DB::table($table)
            ->select('model_type', 'model_uuid')
            ->distinct()
            ->get();

        $deletedCount = 0;

        foreach ($query as $record) {
            $modelClass      = $record->model_type;
            $modelIdentifier = $record->model_uuid; // Can be either `uuid` or `id`

            // Skip if model class does not exist
            if (!class_exists($modelClass)) {
                $this->warn("Model class {$modelClass} does not exist, skipping...");
                continue;
            }

            // Get the primary key for the model
            $primaryKey = $this->getModelPrimaryKey($modelClass);
            if (!$primaryKey) {
                $this->warn("Could not determine primary key for {$modelClass}, skipping...");
                continue;
            }

            // Check if model uses SoftDeletes
            if ($this->usesSoftDeletes($modelClass)) {
                // Ensure soft-deleted records are ignored
                $modelExists = $modelClass::where($primaryKey, $modelIdentifier)->whereNull('deleted_at')->exists();
            } else {
                $modelExists = $modelClass::where($primaryKey, $modelIdentifier)->exists();
            }

            // Delete orphaned record if model does not exist
            if (!$modelExists) {
                DB::table($table)
                    ->where('model_type', $modelClass)
                    ->where('model_uuid', $modelIdentifier)
                    ->delete();

                $deletedCount++;
                $this->line("Deleted orphaned record from {$table} where model_type = {$modelClass} and model_uuid = {$modelIdentifier}");
            }
        }

        $this->info("Finished checking {$table}. {$deletedCount} orphaned records deleted.");
    }

    /**
     * Determines the primary key for a given model.
     */
    protected function getModelPrimaryKey(string $modelClass): ?string
    {
        try {
            $table = (new $modelClass())->getTable();

            // Check if the table has a `uuid` column; if not, fallback to `id`
            if (Schema::hasColumn($table, 'uuid')) {
                return 'uuid';
            } elseif (Schema::hasColumn($table, 'id')) {
                return 'id';
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Checks if a model uses SoftDeletes.
     */
    protected function usesSoftDeletes(string $modelClass): bool
    {
        try {
            $reflection = new \ReflectionClass($modelClass);

            return $reflection->hasMethod('bootSoftDeletes') || in_array(SoftDeletes::class, class_uses($modelClass));
        } catch (\ReflectionException $e) {
            return false;
        }
    }
}
