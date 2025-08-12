<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

class PurgeScheduledTaskLogs extends Command
{
    use PurgeCommand;

    protected $signature = 'purge:scheduled-task-logs
                            {--days=30}
                            {--disk=}
                            {--force}
                            {--skip-backup}';

    protected $description = 'Purge scheduled task logs.';

    public function handle(): int
    {
        $days  = (int) $this->option('days') ?: 30;
        $disk  = $this->option('disk');
        $model = new MonitoredScheduledTaskLogItem();

        $query = $model->newQuery();
        if (Schema::hasColumn($model->getTable(), 'created_at')) {
            $query->where('created_at', '<', now()->subDays($days));
        }

        $this->runPurge($query, $model, $disk, 'backups/scheduled-task-logs');

        return Command::SUCCESS;
    }
}
