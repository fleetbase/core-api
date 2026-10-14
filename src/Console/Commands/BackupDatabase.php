<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\DatabaseBackup;
use Fleetbase\Services\DatabaseBackup\DatabaseBackupException;
use Fleetbase\Services\DatabaseBackup\DatabaseBackupService;
use Fleetbase\Support\DatabaseBackupSettings;
use Illuminate\Console\Command;

/**
 * Dump the configured databases and upload them to the backup disk.
 *
 * Exits non-zero when any database fails, so the scheduler reports FAIL instead of DONE.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
                            {--connection=* : Back up only these connections (default: those in the backup settings)}
                            {--trigger=console : Recorded as what started the run (scheduled, manual or console)}
                            {--force : Run even when backups are disabled in the settings}';

    protected $description = 'Dump the MySQL databases, upload them to the backup disk and apply retention';

    public function handle(DatabaseBackupService $service): int
    {
        $settings = DatabaseBackupSettings::settings();

        if (!$settings['enabled'] && !$this->option('force')) {
            $this->info('Database backups are disabled. Enable them under Admin → Database Backups, or pass --force.');

            return self::SUCCESS;
        }

        $trigger = in_array($this->option('trigger'), [DatabaseBackup::TRIGGER_SCHEDULED, DatabaseBackup::TRIGGER_MANUAL, DatabaseBackup::TRIGGER_CONSOLE], true)
            ? $this->option('trigger')
            : DatabaseBackup::TRIGGER_CONSOLE;

        try {
            $records = $service->run($trigger, $this->option('connection') ?: null, $settings);
        } catch (DatabaseBackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (!$records) {
            $this->error('No database connections are configured for backup.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($records as $record) {
            if ($record->status === DatabaseBackup::STATUS_COMPLETED) {
                $this->info(sprintf('Backed up %s to %s:%s (%d bytes, %d ms)', $record->database, $record->disk, $record->path, $record->size_bytes, $record->duration_ms));
            } else {
                $failed++;
                $this->error(sprintf('Backup of %s failed: %s', $record->database, $record->error));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
