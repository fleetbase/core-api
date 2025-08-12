<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

class PurgeActivityLogs extends Command
{
    use PurgeCommand;

    protected $signature = 'purge:activity-logs
                            {--days=30}
                            {--disk=}
                            {--force}
                            {--skip-backup}';

    protected $description = 'Purge activity/audit logs.';

    public function handle(): int
    {
        $days  = (int) $this->option('days') ?: 30;
        $disk  = $this->option('disk');
        $model = new Activity();

        $query = $model->newQuery();
        if (Schema::hasColumn($model->getTable(), 'created_at')) {
            $query->where('created_at', '<', now()->subDays($days));
        }

        $this->runPurge($query, $model, $disk, 'backups/activity-logs');

        return Command::SUCCESS;
    }
}
