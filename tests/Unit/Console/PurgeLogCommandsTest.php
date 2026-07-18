<?php

use Fleetbase\Console\Commands\PurgeActivityLogs;
use Fleetbase\Console\Commands\PurgeApiLogs;
use Fleetbase\Console\Commands\PurgeScheduledTaskLogs;
use Fleetbase\Console\Commands\PurgeWebhookLogs;
use Fleetbase\Traits\ForcesCommands;
use Illuminate\Console\Command;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class PurgeApiLogsTestCommand extends PurgeApiLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class PurgeWebhookLogsTestCommand extends PurgeWebhookLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class PurgeActivityLogsTestCommand extends PurgeActivityLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class PurgeScheduledTaskLogsTestCommand extends PurgeScheduledTaskLogs
{
    public array $purges = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    protected function runPurge(Builder $baseQuery, Model $model, ?string $diskOption = null, string $backupPath = 'backups'): int
    {
        $query = $baseQuery->getQuery();

        $this->purges[] = [
            'table'       => $model->getTable(),
            'disk'        => $diskOption,
            'backup_path' => $backupPath,
            'wheres'      => $query->wheres ?? [],
            'bindings'    => $query->bindings['where'] ?? [],
        ];

        return 0;
    }
}

class ForcesCommandsTestCommand extends Command
{
    use ForcesCommands;

    public array $warnings = [];
    public array $confirmations = [];

    public function __construct(private array $options = [], private bool $confirmationResult = false)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function warn($string, $verbosity = null): void
    {
        $this->warnings[] = $string;
    }

    public function confirm($question, $default = false): bool
    {
        $this->confirmations[] = $question;

        return $this->confirmationResult;
    }

    public function confirmForTest(string $message): bool
    {
        return $this->confirmOrForce($message);
    }
}

function purge_log_commands_database(bool $withCreatedAt = true): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        'activitylog.table_name'     => 'activity_log',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach (['api_request_logs', 'api_events', 'webhook_request_logs', 'activity_log', 'monitored_scheduled_task_log_items'] as $tableName) {
        $schema->dropIfExists($tableName);
        $schema->create($tableName, function ($table) use ($withCreatedAt) {
            $table->string('uuid')->primary();
            if ($withCreatedAt) {
                $table->timestamp('created_at')->nullable();
            }
        });
    }

    return $capsule;
}

function purge_log_cutoff(array $purge): ?string
{
    $binding = $purge['bindings'][0] ?? null;

    return $binding instanceof Carbon ? $binding->toDateTimeString() : null;
}

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('builds API request and event log purge queries with age cutoff disk and backup paths', function () {
    purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $command = new PurgeApiLogsTestCommand([
        'days' => 45,
        'disk' => 's3-archive',
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->purges)->toHaveCount(2)
        ->and($command->purges[0]['table'])->toBe('api_request_logs')
        ->and($command->purges[0]['disk'])->toBe('s3-archive')
        ->and($command->purges[0]['backup_path'])->toBe('backups/api-logs/requests')
        ->and($command->purges[0]['wheres'][0])->toMatchArray([
            'type'     => 'Basic',
            'column'   => 'created_at',
            'operator' => '<',
            'boolean'  => 'and',
        ])
        ->and(purge_log_cutoff($command->purges[0]))->toBe('2026-06-02 12:00:00')
        ->and($command->purges[1]['table'])->toBe('api_events')
        ->and($command->purges[1]['disk'])->toBe('s3-archive')
        ->and($command->purges[1]['backup_path'])->toBe('backups/api-logs/events')
        ->and($command->purges[1]['wheres'][0]['column'])->toBe('created_at')
        ->and(purge_log_cutoff($command->purges[1]))->toBe('2026-06-02 12:00:00');
});

it('uses default API log retention and avoids age filters when created_at is unavailable', function () {
    purge_log_commands_database(withCreatedAt: false);
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $command = new PurgeApiLogsTestCommand([
        'days' => null,
        'disk' => null,
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->purges)->toHaveCount(2)
        ->and($command->purges[0]['table'])->toBe('api_request_logs')
        ->and($command->purges[0]['disk'])->toBeNull()
        ->and($command->purges[0]['wheres'])->toBe([])
        ->and($command->purges[0]['bindings'])->toBe([])
        ->and($command->purges[1]['table'])->toBe('api_events')
        ->and($command->purges[1]['wheres'])->toBe([])
        ->and($command->purges[1]['bindings'])->toBe([]);
});

it('builds webhook log purge query with default retention and webhook backup path', function () {
    purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $command = new PurgeWebhookLogsTestCommand([
        'days' => 0,
        'disk' => 'local-archive',
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->purges)->toHaveCount(1)
        ->and($command->purges[0]['table'])->toBe('webhook_request_logs')
        ->and($command->purges[0]['disk'])->toBe('local-archive')
        ->and($command->purges[0]['backup_path'])->toBe('backups/webhook-logs')
        ->and($command->purges[0]['wheres'][0])->toMatchArray([
            'type'     => 'Basic',
            'column'   => 'created_at',
            'operator' => '<',
            'boolean'  => 'and',
        ])
        ->and(purge_log_cutoff($command->purges[0]))->toBe('2026-06-17 12:00:00');
});

it('builds activity and scheduled task log purge queries with dedicated backup paths', function () {
    purge_log_commands_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $activity = new PurgeActivityLogsTestCommand([
        'days' => 14,
        'disk' => 'audit-archive',
    ]);
    $scheduled = new PurgeScheduledTaskLogsTestCommand([
        'days' => 7,
        'disk' => 'task-archive',
    ]);

    expect($activity->handle())->toBe(0)
        ->and($scheduled->handle())->toBe(0)
        ->and($activity->purges[0]['table'])->toBe('activity_log')
        ->and($activity->purges[0]['disk'])->toBe('audit-archive')
        ->and($activity->purges[0]['backup_path'])->toBe('backups/activity-logs')
        ->and(purge_log_cutoff($activity->purges[0]))->toBe('2026-07-03 12:00:00')
        ->and($scheduled->purges[0]['table'])->toBe('monitored_scheduled_task_log_items')
        ->and($scheduled->purges[0]['disk'])->toBe('task-archive')
        ->and($scheduled->purges[0]['backup_path'])->toBe('backups/scheduled-task-logs')
        ->and(purge_log_cutoff($scheduled->purges[0]))->toBe('2026-07-10 12:00:00');
});

it('forces command confirmation when force is present and otherwise prompts', function () {
    $forced = new ForcesCommandsTestCommand(['force' => true]);
    $prompt = new ForcesCommandsTestCommand(['force' => false], true);

    expect($forced->confirmForTest('Delete records?'))->toBeTrue()
        ->and($forced->warnings)->toBe(['Force flag detected: Skipping confirmation.'])
        ->and($forced->confirmations)->toBe([])
        ->and($prompt->confirmForTest('Delete records?'))->toBeTrue()
        ->and($prompt->warnings)->toBe([])
        ->and($prompt->confirmations)->toBe(['Delete records?']);
});
