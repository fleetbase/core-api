<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

class PurgeActivityLogs extends Command
{
    use PurgeCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:activity-logs 
                            {--days=30 : The number of days to preserve logs (default: 30)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purges Activity logs older than the specified number of days, with an option to back up records before deletion.';

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

        $this->info("Purging Activity logs older than {$days} days...");

        // Calculate the cutoff date
        $cutoffDate = now()->subDays($days);

        // Backup and purge logs
        $this->backupAndDelete(Activity::class, 'activity', $cutoffDate, 'backups/activity-logs');

        $this->info('Purge completed successfully.');
    }
}
