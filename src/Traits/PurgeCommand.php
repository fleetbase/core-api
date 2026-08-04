<?php

namespace Fleetbase\Traits;

use Fleetbase\Exceptions\PurgeBackupException;
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
     * Make sure the directory holding a dump file exists.
     */
    protected function ensureDumpDirectory(string $fileName): void
    {
        $directory = dirname($fileName);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    /**
     * Write a simple SQL INSERT dump to $fileName for the given rows.
     */
    protected function writeSqlDump(string $tableName, Collection $records, string $fileName): void
    {
        if ($records->isEmpty()) {
            $this->ensureDumpDirectory($fileName);
            file_put_contents($fileName, "-- empty set\n", FILE_APPEND);

            return;
        }

        $columns = array_keys($records->first());
        $quoted  = array_map(fn ($c) => '`' . str_replace('`', '``', $c) . '`', $columns);

        // Start file (create/overwrite once)
        if (!file_exists($fileName)) {
            $this->ensureDumpDirectory($fileName);
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
     * Stream the local dump to the backup disk and prove it landed intact.
     *
     * Deleting rows is only safe once the backup is known to exist and to be non-empty,
     * so every failure here aborts before the caller reaches its delete stage.
     *
     * @throws PurgeBackupException when the backup cannot be verified
     */
    protected function uploadBackup(string $localTmp, string $disk, string $remote): void
    {
        if (!is_file($localTmp) || filesize($localTmp) === 0) {
            throw new PurgeBackupException('was not written locally, or is empty', $disk, $remote);
        }

        $filesystem = Storage::disk($disk);
        $stream     = fopen($localTmp, 'rb');
        $uploaded   = $stream !== false && $filesystem->writeStream($remote, $stream) !== false;

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$uploaded || !$filesystem->exists($remote)) {
            throw new PurgeBackupException('upload failed', $disk, $remote);
        }

        if ($filesystem->size($remote) === 0) {
            throw new PurgeBackupException('is empty', $disk, $remote);
        }
    }

    /**
     * Keep only the --keep-backups most recent dumps for this table, if the option is set.
     *
     * Scoped to this table's own dumps so a shared or reused backup prefix is never touched,
     * and non-fatal: the backup is already safe by the time this runs.
     */
    protected function pruneBackups(string $disk, string $backupPath, string $tableName, string $justUploaded): void
    {
        try {
            // Commands using this trait without declaring the option simply opt out of pruning.
            $keep = method_exists($this, 'option') ? $this->option('keep-backups') : null;
        } catch (\Throwable $e) {
            return;
        }

        if ($keep === null || $keep === '' || (int) $keep < 1) {
            return;
        }

        $keep       = (int) $keep;
        $filesystem = Storage::disk($disk);

        try {
            // Dump names are "<table>_<Y-m-d>_<H-i-s>.sql", so for a single table
            // sorting by name descending is the same as sorting newest first.
            $dumps = collect($filesystem->files(trim($backupPath, '/')))
                ->filter(fn ($path) => $path !== $justUploaded && preg_match('/^' . preg_quote($tableName, '/') . '_[\d\-_]+\.sql$/', basename($path)))
                ->sortDesc()
                ->values();

            // The upload we just made is the newest and is excluded above, so it counts against the budget.
            $stale = $dumps->slice($keep - 1);
            if ($stale->isEmpty()) {
                return;
            }

            foreach ($stale as $path) {
                $filesystem->delete($path);
            }

            $this->info("Pruned {$stale->count()} old backup(s), keeping the {$keep} most recent.");
        } catch (\Throwable $e) {
            $this->warn('Could not prune old backups: ' . $e->getMessage());
        }
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
            $remote   = trim($backupPath, '/') . "/{$tmpName}";
            $this->info("Backing up {$count} records from {$tableName} to '{$disk}:{$remote}'...");

            // A dump left behind by an earlier run would be appended to rather than replaced.
            if (is_file($localTmp)) {
                @unlink($localTmp);
            }

            try {
                // Stream rows to file one chunk at a time so neither the rows nor the SQL
                // they produce are ever fully resident in memory.
                (clone $baseQuery)->orderBy($this->detectPrimaryKey($tableName, $model) ?? 'created_at')->chunk(1000, function ($chunk) use ($tableName, $localTmp) {
                    $this->writeSqlDump($tableName, $chunk->map(fn ($m) => $m->getAttributes()), $localTmp);
                });

                $this->uploadBackup($localTmp, $disk, $remote);
                $this->pruneBackups($disk, $backupPath, $tableName, $remote);
            } finally {
                if (is_file($localTmp)) {
                    @unlink($localTmp);
                }
            }

            $this->info('Backup verified and uploaded.');
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
            (clone $baseQuery)->orderByRaw('1')->chunk(1000, function ($chunk) use (&$deleted, $tableName) {
                foreach ($chunk as $m) {
                    $deleteQuery = DB::table($tableName);

                    foreach ($m->getAttributes() as $column => $value) {
                        $value === null ? $deleteQuery->whereNull($column) : $deleteQuery->where($column, $value);
                    }

                    $deleted += $deleteQuery->delete();
                }
            });
        }

        $this->resetTableIndex($tableName);
        $this->info("Purge completed. Deleted: {$deleted}");

        return $deleted;
    }
}
