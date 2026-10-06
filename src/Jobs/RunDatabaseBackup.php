<?php

namespace Fleetbase\Jobs;

use Fleetbase\Models\DatabaseBackup;
use Fleetbase\Services\DatabaseBackup\DatabaseBackupException;
use Fleetbase\Services\DatabaseBackup\DatabaseBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * A backup an administrator asked for from the console ("Run backup now").
 *
 * Queued so the request returns at once; each database's outcome is recorded by the
 * service and shown in the console's recent runs.
 */
class RunDatabaseBackup implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * A failed dump is recorded, not retried: retrying a dump that timed out would only
     * load the database again.
     */
    public int $tries = 1;

    public int $timeout = 4 * 3600;

    public function __construct(public string $trigger = DatabaseBackup::TRIGGER_MANUAL)
    {
    }

    public function handle(DatabaseBackupService $service): void
    {
        try {
            $service->run($this->trigger);
        } catch (DatabaseBackupException $e) {
            Log::warning('Requested database backup did not run', ['error' => $e->getMessage()]);
        }
    }
}
