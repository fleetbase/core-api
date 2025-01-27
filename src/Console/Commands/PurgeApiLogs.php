<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\ApiRequestLog;
use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;

class PurgeApiLogs extends Command
{
    use PurgeCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:api-logs 
                            {--days=30 : The number of days to preserve logs (default: 30)} {--force : Force the command to run without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purges API logs and events older than the specified number of days, with an option to back up records before deletion.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Determine the number of days to preserve
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('The number of days must be a positive integer.');

            return;
        }

        $this->info("Purging API logs and events older than {$days} days...");

        // Calculate the cutoff date
        $cutoffDate = now()->subDays($days);

        // Backup and purge logs
        $this->backupAndDelete(ApiRequestLog::class, 'api_request_logs', $cutoffDate, 'backups/api-logs');
        $this->backupAndDelete(ApiEvent::class, 'api_events', $cutoffDate, 'backups/api-events');

        $this->info('Purge completed successfully.');
    }
}
