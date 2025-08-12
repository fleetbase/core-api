<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\WebhookRequestLog;
use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PurgeWebhookLogs extends Command
{
    use PurgeCommand;

    protected $signature = 'purge:webhook-logs
                            {--days=30 : Only purge records older than this many days}
                            {--disk= : Filesystem disk for backups; defaults to app default}
                            {--force : Do not ask for interactive confirmation}
                            {--skip-backup : Skip creating a backup and delete immediately}';

    protected $description = 'Purge webhook request logs.';

    public function handle(): int
    {
        $days  = (int) $this->option('days') ?: 30;
        $disk  = $this->option('disk');
        $model = new WebhookRequestLog();

        $query = $model->newQuery();
        if (Schema::hasColumn($model->getTable(), 'created_at')) {
            $query->where('created_at', '<', now()->subDays($days));
        }

        $this->runPurge($query, $model, $disk, 'backups/webhook-logs');

        return Command::SUCCESS;
    }
}
