<?php

namespace Fleetbase\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

trait PurgeCommand
{
    use ForcesCommands;

    /**
     * True if caller passed --skip-backup on the command.
     */
    protected function shouldSkipBackup(): bool
    {
        return method_exists($this, 'option') && (bool) $this->option('skip-backup');
    }

    /**
     * Build a clear confirmation line that respects --skip-backup.
     */
    protected function confirmDeleteLine(string $tableName): string
    {
        return $this->shouldSkipBackup()
            ? "Permanently delete selected records from {$tableName} WITHOUT BACKUP?"
            : "Do you want to permanently delete the selected records from {$tableName}?";
    }

    /**
     * Pick the disk to use: explicit wins, else app default.
     */
    protected function resolveBackupDisk(?string $diskFromOption): string
    {
        return $diskFromOption ?: (string) config('filesystems.default', 'local');
    }

    /**
     * Write a simple SQL INSERT dump to $fileName for the given rows.
     */
    protected function writeSqlDump(string $tableName, Collection $records, string $fileName): void
    {
        if ($records->isEmpty()) {
            file_put_contents($fileName, "-- empty set\n");

            return;
        }

        $columns = array_keys($records->first());
        $quoted  = array_map(fn ($c) => '`' . str_replace('`', '``', $c) . '`', $columns);

        // Start file (create/overwrite once)
        if (!file_exists($fileName)) {
            @mkdir(dirname($fileName), 0775, true);
            file_put_contents($fileName, "-- Dump of {$tableName}\n");
        }

        $dump  = "INSERT INTO `{$tableName}` (" . implode(', ', $quoted) . ")\nVALUES\n";

        $rows = [];
        foreach ($records as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $val = $row[$col] ?? null;
                if ($val === null) {
                    $vals[] = 'NULL';
                } elseif (is_numeric($val) && !preg_match('/^0[0-9]/', (string) $val)) {
                    $vals[] = (string) $val;
                } else {
                    $vals[] = "'" . str_replace("'", "''", (string) $val) . "'";
                }
            }
            $rows[] = '(' . implode(', ', $vals) . ')';
        }

        $dump .= implode(",\n", $rows) . ";\n";
        file_put_contents($fileName, $dump, FILE_APPEND);
    }

    /**
     * Reset AUTO_INCREMENT when an integer id exists.
     */
    protected function resetTableIndex(string $tableName): void
    {
        if (!Schema::hasColumn($tableName, 'id')) {
            return;
        }

        $table = DB::getTablePrefix() . $tableName;
        try {
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1;");
        } catch (\Throwable $e) {
            // ignore non‑MySQL / non‑int PK cases
        }
    }

    /**
     * Decide which key to chunk by.
     */
    protected function detectPrimaryKey(string $tableName, ?Model $model = null): ?string
    {
        // prefer model key if present
        if ($model && $model->getKeyName()) {
            return $model->getKeyName();
        }
        $cols = Schema::getColumnListing($tableName);

        return in_array('uuid', $cols, true) ? 'uuid' : (in_array('id', $cols, true) ? 'id' : null);
    }

    /**
     * Standard purge flow: confirm -> (optional backup) -> delete -> reset index.
     *
     * @param Builder     $baseQuery  already-filtered query for rows to purge
     * @param Model       $model      model instance for table/meta
     * @param string|null $diskOption --disk option value; null means use app default
     * @param string      $backupPath destination path prefix on chosen disk
     *
     * @return int rows deleted
     */
    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $tableName = $model->getTable();
        $count     = (clone $baseQuery)->count();

        if ($count === 0) {
            $this->info("No records to purge from {$tableName}.");

            return 0;
        }

        if (!$this->confirmOrForce($this->confirmDeleteLine($tableName))) {
            $this->warn("Skipped purging {$tableName}.");

            return 0;
        }

        // ===== BACKUP (only if NOT skipped) =====
        if (!$this->shouldSkipBackup()) {
            $disk     = $this->resolveBackupDisk($diskOption);
            $tmpName  = str_replace([' ', ':'], '_', "{$tableName}_" . now()->format('Y-m-d_H-i-s') . '.sql');
            $localTmp = storage_path("app/tmp/{$tmpName}");
            $this->info("Backing up {$count} records from {$tableName} to '{$disk}:{$backupPath}/{$tmpName}'...");

            $buffer = collect();

            // stream rows to file in chunks
            (clone $baseQuery)->orderBy($this->detectPrimaryKey($tableName, $model) ?? 'created_at')->chunk(1000, function ($chunk) use (&$buffer, $tableName, $localTmp) {
                $buffer = $buffer->concat($chunk->map(fn ($m) => $m->getAttributes()));
                if ($buffer->count() >= 5000) {
                    $this->writeSqlDump($tableName, $buffer, $localTmp);
                    $buffer = collect();
                }
            });
            if ($buffer->count() > 0) {
                $this->writeSqlDump($tableName, $buffer, $localTmp);
            }

            // upload and done
            $remote = trim($backupPath, '/') . "/{$tmpName}";
            Storage::disk($disk)->put($remote, file_get_contents($localTmp));
            $this->info('Backup uploaded.');
        } else {
            $this->warn('Skipping backup as --skip-backup was provided.');
        }

        // ===== DELETE =====
        $this->info("Deleting {$count} records from {$tableName}...");
        $deleted  = 0;
        $pkColumn = $this->detectPrimaryKey($tableName, $model);

        if ($pkColumn) {
            (clone $baseQuery)->orderBy($pkColumn)->chunkById(1000, function ($chunk) use (&$deleted, $pkColumn, $tableName) {
                $ids = $chunk->pluck($pkColumn)->all();
                if (!empty($ids)) {
                    DB::table($tableName)->whereIn($pkColumn, $ids)->delete();
                    $deleted += count($ids);
                }
            }, $pkColumn);
        } else {
            (clone $baseQuery)->chunk(1000, function ($chunk) use (&$deleted) {
                foreach ($chunk as $m) {
                    $m->delete();
                    $deleted++;
                }
            });
        }

        $this->resetTableIndex($tableName);
        $this->info("Purge completed. Deleted: {$deleted}");

        return $deleted;
    }
}
