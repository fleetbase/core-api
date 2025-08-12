<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\ApiRequestLog;
use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PurgeApiLogs extends Command
{
    use PurgeCommand;

    protected $signature = 'purge:api-logs
                            {--days=30}
                            {--disk=}
                            {--force}
                            {--skip-backup}';

    protected $description = 'Purge API request logs and API events.';

    public function handle(): int
    {
        $days = (int) $this->option('days') ?: 30;
        $disk = $this->option('disk');

        // ApiRequestLog
        $reqModel = new ApiRequestLog();
        $reqQuery = $reqModel->newQuery();
        if (Schema::hasColumn($reqModel->getTable(), 'created_at')) {
            $reqQuery->where('created_at', '<', now()->subDays($days));
        }
        $this->runPurge($reqQuery, $reqModel, $disk, 'backups/api-logs/requests');

        // ApiEvent
        $evtModel = new ApiEvent();
        $evtQuery = $evtModel->newQuery();
        if (Schema::hasColumn($evtModel->getTable(), 'created_at')) {
            $evtQuery->where('created_at', '<', now()->subDays($days));
        }
        $this->runPurge($evtQuery, $evtModel, $disk, 'backups/api-logs/events');

        return Command::SUCCESS;
    }
}
