<?php

namespace Fleetbase\Services\DatabaseBackup;

use Fleetbase\Models\DatabaseBackup;
use Fleetbase\Notifications\DatabaseBackupFailed;
use Fleetbase\Support\DatabaseBackupSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Dumps the configured MySQL databases, uploads them to a filesystem disk, trims old
 * backups and records every attempt.
 *
 * Designed so a failure can never pass for a success, which is what the old command did:
 *  - the dump client runs without a shell, so there is no pipeline to hide its exit code
 *    behind gzip's; compression happens here, in PHP, as the output streams in;
 *  - the dump must end with mysqldump's completion marker and be at least a minimum size;
 *  - the uploaded object's size must match the local file;
 *  - retention only runs when every database in the run succeeded, so a broken backup
 *    never trims away the last good ones.
 */
class DatabaseBackupService
{
    public const LOCK_KEY = 'database-backups:run';

    public const COMPLETION_MARKER = '-- Dump completed';

    /**
     * Back up every configured connection (or the given ones).
     *
     * @return array<int, DatabaseBackup> one record per connection
     *
     * @throws DatabaseBackupException when another backup is already running
     */
    public function run(string $trigger = DatabaseBackup::TRIGGER_CONSOLE, ?array $connections = null, ?array $settings = null): array
    {
        $settings ??= DatabaseBackupSettings::settings();
        $connections = $connections ?: $settings['connections'];

        $lock = $this->acquireLock();
        if ($lock === false) {
            throw new DatabaseBackupException('Another database backup is already running.');
        }

        try {
            $records = [];
            foreach ($connections as $connection) {
                $records[] = $this->backupConnection((string) $connection, $trigger, $settings);
            }

            $failed = array_values(array_filter($records, fn (DatabaseBackup $record) => $record->status === DatabaseBackup::STATUS_FAILED));

            if ($failed) {
                $this->notifyFailure($failed, $settings);
            } elseif ($records) {
                $this->pruneQuietly($settings);
            }

            return $records;
        } finally {
            if (is_object($lock)) {
                $lock->release();
            }
        }
    }

    /**
     * Dump, verify and upload one connection's database, recording the outcome.
     */
    public function backupConnection(string $connection, string $trigger, array $settings): DatabaseBackup
    {
        $database = (string) config("database.connections.{$connection}.database", $connection);
        $started  = hrtime(true);
        $record   = DatabaseBackup::create([
            'connection_name' => $connection,
            'database'        => $database,
            'status'          => DatabaseBackup::STATUS_RUNNING,
            'trigger'         => $trigger,
            'disk'            => $settings['disk'],
            'started_at'      => now(),
        ]);

        $file = null;
        try {
            $file = $this->dump($connection, $this->fileName($database));
            $size = (int) filesize($file);

            if ($size < $settings['min_size_bytes']) {
                throw new DatabaseBackupException(sprintf('The compressed dump is %d bytes, below the %d byte minimum.', $size, $settings['min_size_bytes']));
            }

            $path = $this->objectPath($settings['path'], basename($file));
            $this->upload($this->disk($settings), $file, $path, $size);

            $record->fill([
                'status'     => DatabaseBackup::STATUS_COMPLETED,
                'path'       => $path,
                'size_bytes' => $size,
            ]);
        } catch (\Throwable $e) {
            $record->fill([
                'status' => DatabaseBackup::STATUS_FAILED,
                'error'  => mb_substr($e->getMessage(), 0, 4000),
            ]);

            Log::error('Database backup failed', ['connection' => $connection, 'database' => $database, 'error' => $e->getMessage()]);
        } finally {
            if ($file && is_file($file)) {
                @unlink($file);
            }
        }

        $record->fill([
            'duration_ms'  => (int) ((hrtime(true) - $started) / 1_000_000),
            'completed_at' => now(),
        ])->save();

        return $record;
    }

    /**
     * Stream the dump client's output into a gzip file and verify it finished.
     *
     * @return string the path of the compressed dump
     */
    public function dump(string $connection, string $fileName): string
    {
        $config = config("database.connections.{$connection}");
        if (!is_array($config) || !in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new DatabaseBackupException("Connection [{$connection}] is not a MySQL connection.");
        }

        $directory = rtrim((string) config('database-backups.tmp_dir', sys_get_temp_dir()), '/');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DatabaseBackupException("Cannot create the backup directory {$directory}.");
        }

        $file = $directory . '/' . $fileName;
        $gz   = @gzopen($file, 'wb6');
        if ($gz === false) {
            throw new DatabaseBackupException("Cannot write {$file}.");
        }

        $tail   = '';
        $stderr = '';

        try {
            $process = $this->makeProcess($this->dumpCommand($config), ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
            $process->setTimeout((int) config('database-backups.timeout', 7200));
            $process->start();

            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $data);
                    $tail = substr($tail . $data, -512);
                } else {
                    $stderr .= $data;
                }
            }

            $exitCode = $process->wait();
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($file);

            throw new DatabaseBackupException('The dump could not run: ' . $e->getMessage(), 0, $e);
        }

        gzclose($gz);

        if ($exitCode !== 0) {
            @unlink($file);

            throw new DatabaseBackupException(sprintf('The dump exited with code %d: %s', $exitCode, trim($stderr) ?: 'no error output'));
        }

        if (!str_contains($tail, static::COMPLETION_MARKER)) {
            @unlink($file);

            throw new DatabaseBackupException('The dump ended without its completion marker, so it is incomplete.' . (trim($stderr) ? ' ' . trim($stderr) : ''));
        }

        return $file;
    }

    /**
     * The dump client's argument list. The password travels in MYSQL_PWD, never on the
     * command line, where any process listing would show it.
     */
    public function dumpCommand(array $config): array
    {
        $command = [(string) config('database-backups.dump_binary', 'mysqldump')];

        if (!empty($config['unix_socket'])) {
            $command[] = '--socket=' . $config['unix_socket'];
        } else {
            $command[] = '--host=' . ($config['host'] ?? '127.0.0.1');
            $command[] = '--port=' . ($config['port'] ?? 3306);
        }

        $command[] = '--user=' . ($config['username'] ?? 'root');

        return array_merge(
            $command,
            (array) config('database-backups.dump_args', []),
            (array) config('database-backups.extra_dump_args', []),
            [(string) $config['database']]
        );
    }

    /**
     * Upload the file and confirm the stored object is the size we wrote.
     */
    public function upload(Filesystem $disk, string $file, string $path, int $size): void
    {
        $stream = fopen($file, 'rb');

        try {
            $disk->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $stored = $disk->size($path);
        if ((int) $stored !== $size) {
            throw new DatabaseBackupException(sprintf('The uploaded backup is %d bytes but the local dump is %d bytes.', $stored, $size));
        }
    }

    /**
     * Delete backups beyond the retention limits, always keeping each database's newest.
     *
     * Only files following this command's naming scheme are considered, so nothing else
     * stored under the same prefix is ever touched.
     *
     * @return array<int, string> the deleted paths
     */
    public function prune(array $settings): array
    {
        if (!$settings['retention_days'] && !$settings['retention_count']) {
            return [];
        }

        $disk   = $this->disk($settings);
        $cutoff = $settings['retention_days'] ? now()->subDays($settings['retention_days'])->format('Ymd-His') : null;
        $groups = [];

        foreach ($disk->files($settings['path']) as $path) {
            if (preg_match('/^(.+)_backup-(\d{8}-\d{6})\.sql(?:\.gz)?$/', basename($path), $matches)) {
                $groups[$matches[1]][$path] = $matches[2];
            }
        }

        $delete = [];
        foreach ($groups as $files) {
            arsort($files);
            $index = 0;
            foreach ($files as $path => $timestamp) {
                $tooMany = $settings['retention_count'] && $index >= $settings['retention_count'];
                $tooOld  = $cutoff && $timestamp < $cutoff;

                if ($index > 0 && ($tooMany || $tooOld)) {
                    $delete[] = $path;
                }
                $index++;
            }
        }

        if ($delete) {
            $disk->delete($delete);
            DatabaseBackup::where('disk', $settings['disk'])->whereIn('path', $delete)->update(['pruned_at' => now()]);
        }

        return $delete;
    }

    /**
     * The disk backups are written to, with the bucket override applied and errors thrown
     * rather than reported as `false`.
     */
    public function disk(array $settings): Filesystem
    {
        $config = config("filesystems.disks.{$settings['disk']}");
        if (!is_array($config)) {
            throw new DatabaseBackupException("Filesystem disk [{$settings['disk']}] is not configured.");
        }

        if ($settings['bucket'] && ($config['driver'] ?? null) === 's3') {
            $config['bucket'] = $settings['bucket'];
        }

        $config['throw'] = true;

        return $this->buildDisk($config);
    }

    public function fileName(string $database): string
    {
        $environment = str_replace([' ', '-'], '_', (string) config('app.env', 'local'));

        return sprintf('%s_%s_backup-%s.sql.gz', $environment, $database, now()->format('Ymd-His'));
    }

    public function objectPath(string $prefix, string $fileName): string
    {
        return ltrim(trim($prefix, '/') . '/' . $fileName, '/');
    }

    protected function pruneQuietly(array $settings): void
    {
        try {
            $this->prune($settings);
        } catch (\Throwable $e) {
            Log::warning('Database backup retention failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<int, DatabaseBackup> $failed
     */
    protected function notifyFailure(array $failed, array $settings): void
    {
        if (!$settings['notify_on_failure'] || !$settings['notify_emails']) {
            return;
        }

        try {
            Notification::route('mail', $settings['notify_emails'])->notify(new DatabaseBackupFailed($failed));
        } catch (\Throwable $e) {
            Log::error('Could not send the database backup failure notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return object|bool a lock to release, true when the cache cannot lock, or false when held elsewhere
     */
    protected function acquireLock()
    {
        try {
            $lock = Cache::lock(static::LOCK_KEY, 4 * 3600);
        } catch (\Throwable $e) {
            return true;
        }

        return $lock->get() ? $lock : false;
    }

    protected function makeProcess(array $command, array $env): Process
    {
        return new Process($command, null, $env);
    }

    protected function buildDisk(array $config): Filesystem
    {
        return Storage::build($config);
    }
}
