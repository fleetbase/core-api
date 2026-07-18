<?php

use Fleetbase\Console\Commands\RunScheduledReports;
use Fleetbase\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class RunScheduledReportsTestCommand extends RunScheduledReports
{
    public array $messages        = [];
    public array $executedReports = [];
    public array $tables          = [];

    public function __construct(private array $options = [], private bool $executionResult = true)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }

    protected function executeReport(Report $report): bool
    {
        $this->executedReports[] = [
            'uuid'      => $report->uuid,
            'public_id' => $report->public_id,
            'title'     => $report->title,
        ];

        return $this->executionResult;
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = []): void
    {
        $this->tables[] = compact('headers', 'rows');
    }
}

class RunScheduledReportsExecuteCommand extends RunScheduledReports
{
    public array $messages = [];

    public function option($key = null)
    {
        return null;
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }

    public function runReport(Report $report): bool
    {
        return $this->executeReport($report);
    }
}

class RunScheduledReportsExecutableReport extends Report
{
    public function __construct(private array|Throwable|null $executionResult = null, array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public function execute(array $options = []): array
    {
        if ($this->executionResult instanceof Throwable) {
            throw $this->executionResult;
        }

        return $this->executionResult ?? ['success' => true, 'results' => [], 'meta' => ['total_rows' => 0]];
    }
}

class RunScheduledReportsCacheFake
{
    public function forget(string $key): bool
    {
        return true;
    }

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        return $value;
    }
}

class RunScheduledReportsResponseCacheFake
{
    public function clear(): void
    {
    }
}

function run_scheduled_reports_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new RunScheduledReportsCacheFake());
    $container->instance('responsecache', new RunScheduledReportsResponseCacheFake());
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('reports');
    $schema->create('reports', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('title')->nullable();
        $table->boolean('is_scheduled')->default(false);
        $table->string('schedule_frequency')->nullable();
        $table->string('schedule_time')->nullable();
        $table->string('schedule_timezone')->nullable();
        $table->timestamp('next_scheduled_run')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->dropIfExists('report_executions');
    $schema->create('report_executions', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('report_uuid')->nullable()->index();
        $table->float('execution_time')->nullable();
        $table->integer('result_count')->nullable();
        $table->string('status')->nullable();
        $table->text('error_message')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
    });

    $capsule->getConnection('mysql')->table('reports')->insert([
        [
            'uuid'               => 'report-uuid-1',
            'public_id'          => 'report_1234567',
            'title'              => 'Daily Orders',
            'is_scheduled'       => true,
            'schedule_frequency' => 'daily',
            'schedule_time'      => '08:00',
            'schedule_timezone'  => 'UTC',
            'next_scheduled_run' => '2026-07-17 08:00:00',
            'created_at'         => '2026-07-17 07:00:00',
            'updated_at'         => '2026-07-17 07:00:00',
            'deleted_at'         => null,
        ],
        [
            'uuid'               => 'report-uuid-2',
            'public_id'          => 'report_7654321',
            'title'              => 'Weekly Revenue',
            'is_scheduled'       => true,
            'schedule_frequency' => 'weekly',
            'schedule_time'      => '13:00',
            'schedule_timezone'  => 'UTC',
            'next_scheduled_run' => '2026-07-18 09:00:00',
            'created_at'         => '2026-07-18 07:00:00',
            'updated_at'         => '2026-07-18 07:00:00',
            'deleted_at'         => null,
        ],
        [
            'uuid'               => 'report-uuid-future',
            'public_id'          => 'report_future',
            'title'              => 'Future Report',
            'is_scheduled'       => true,
            'schedule_frequency' => 'daily',
            'schedule_time'      => '08:00',
            'schedule_timezone'  => 'UTC',
            'next_scheduled_run' => '2026-07-19 08:00:00',
            'created_at'         => '2026-07-18 07:00:00',
            'updated_at'         => '2026-07-18 07:00:00',
            'deleted_at'         => null,
        ],
        [
            'uuid'               => 'report-uuid-unscheduled',
            'public_id'          => 'report_unscheduled',
            'title'              => 'Manual Report',
            'is_scheduled'       => false,
            'schedule_frequency' => null,
            'schedule_time'      => null,
            'schedule_timezone'  => null,
            'next_scheduled_run' => '2026-07-17 08:00:00',
            'created_at'         => '2026-07-18 07:00:00',
            'updated_at'         => '2026-07-18 07:00:00',
            'deleted_at'         => null,
        ],
    ]);

    return $capsule;
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('returns an error when a requested scheduled report cannot be found', function () {
    run_scheduled_reports_database();

    $command = new RunScheduledReportsTestCommand([
        'report'  => 'missing-report',
        'dry-run' => false,
    ]);

    expect($command->handle())->toBe(1)
        ->and($command->messages)->toBe([
            ['error', 'Report not found: missing-report'],
        ])
        ->and($command->executedReports)->toBe([]);
});

it('dry-runs a specific report resolved by uuid or public id without executing it', function (string $reportId) {
    run_scheduled_reports_database();

    $command = new RunScheduledReportsTestCommand([
        'report'  => $reportId,
        'dry-run' => true,
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toBe([
            ['info', 'Would execute report: Daily Orders (report_1234567)'],
        ])
        ->and($command->executedReports)->toBe([]);
})->with(['report-uuid-1', 'report_1234567']);

it('executes a specific report and maps execution outcome to command status', function () {
    run_scheduled_reports_database();

    $successful = new RunScheduledReportsTestCommand([
        'report'  => 'report_1234567',
        'dry-run' => false,
    ], executionResult: true);

    $failed = new RunScheduledReportsTestCommand([
        'report'  => 'report-uuid-1',
        'dry-run' => false,
    ], executionResult: false);

    expect($successful->handle())->toBe(0)
        ->and($successful->executedReports)->toBe([
            [
                'uuid'      => 'report-uuid-1',
                'public_id' => 'report_1234567',
                'title'     => 'Daily Orders',
            ],
        ])
        ->and($failed->handle())->toBe(1)
        ->and($failed->executedReports)->toBe([
            [
                'uuid'      => 'report-uuid-1',
                'public_id' => 'report_1234567',
                'title'     => 'Daily Orders',
            ],
        ]);
});

it('reports no due scheduled reports when every schedule is future or disabled', function () {
    run_scheduled_reports_database();
    Carbon::setTestNow(Carbon::parse('2026-07-16 00:00:00', 'UTC'));

    $command = new RunScheduledReportsTestCommand([
        'report'  => null,
        'dry-run' => false,
    ]);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toBe([
            ['info', 'No scheduled reports are due for execution.'],
        ])
        ->and($command->executedReports)->toBe([]);
});

it('dry-runs due scheduled reports with stable table rows', function () {
    run_scheduled_reports_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'UTC'));

    $command = new RunScheduledReportsTestCommand([
        'report'  => null,
        'dry-run' => true,
    ]);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toBe([
            ['info', 'Found 2 reports due for execution.'],
        ])
        ->and($command->executedReports)->toBe([])
        ->and($command->tables)->toBe([
            [
                'headers' => ['Title', 'Public ID', 'Frequency', 'Next Run'],
                'rows'    => [
                    ['Daily Orders', 'report_1234567', 'daily', '2026-07-17 08:00:00'],
                    ['Weekly Revenue', 'report_7654321', 'weekly', '2026-07-18 09:00:00'],
                ],
            ],
        ]);
});

it('executes all due scheduled reports and reports aggregate failure status', function () {
    run_scheduled_reports_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'UTC'));

    $success = new RunScheduledReportsTestCommand([
        'report'  => null,
        'dry-run' => false,
    ], executionResult: true);
    $failure = new RunScheduledReportsTestCommand([
        'report'  => null,
        'dry-run' => false,
    ], executionResult: false);

    expect($success->handle())->toBe(Command::SUCCESS)
        ->and($success->messages)->toBe([
            ['info', 'Found 2 reports due for execution.'],
            ['info', 'Execution complete: 2 successful, 0 failed.'],
        ])
        ->and(array_column($success->executedReports, 'uuid'))->toBe(['report-uuid-1', 'report-uuid-2'])
        ->and($failure->handle())->toBe(Command::FAILURE)
        ->and($failure->messages)->toBe([
            ['info', 'Found 2 reports due for execution.'],
            ['info', 'Execution complete: 0 successful, 2 failed.'],
        ])
        ->and(array_column($failure->executedReports, 'uuid'))->toBe(['report-uuid-1', 'report-uuid-2']);
});

it('records successful scheduled report execution and advances the next run', function () {
    $capsule = run_scheduled_reports_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'UTC'));

    $report = new RunScheduledReportsExecutableReport([
        'success' => true,
        'results' => [
            ['id' => 1],
            ['id' => 2],
        ],
        'meta' => [
            'total_rows' => 2,
        ],
    ]);
    $report->exists = true;
    $report->setRawAttributes(Report::where('uuid', 'report-uuid-1')->firstOrFail()->getAttributes(), true);

    $command = new RunScheduledReportsExecuteCommand();

    expect($command->runReport($report))->toBeTrue()
        ->and($command->messages[0])->toBe(['info', 'Executing report: Daily Orders (report_1234567)'])
        ->and($command->messages[1][0])->toBe('info')
        ->and($command->messages[1][1])->toContain('Report executed successfully')
        ->and($command->messages[1][1])->toContain('(2 rows)')
        ->and($capsule->getConnection('mysql')->table('report_executions')->where('report_uuid', 'report-uuid-1')->pluck('status')->all())->toBe(['completed'])
        ->and($capsule->getConnection('mysql')->table('report_executions')->where('report_uuid', 'report-uuid-1')->where('status', 'completed')->value('result_count'))->toBe(2)
        ->and($capsule->getConnection('mysql')->table('reports')->where('uuid', 'report-uuid-1')->value('next_scheduled_run'))->toBe('2026-07-19 08:00:00');
});

it('records failed scheduled report execution without advancing the next run', function () {
    $capsule = run_scheduled_reports_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'UTC'));

    $report = new RunScheduledReportsExecutableReport([
        'success' => false,
        'error'   => 'Warehouse query timed out',
    ]);
    $report->exists = true;
    $report->setRawAttributes(Report::where('uuid', 'report-uuid-2')->firstOrFail()->getAttributes(), true);

    $command = new RunScheduledReportsExecuteCommand();

    expect($command->runReport($report))->toBeFalse()
        ->and($command->messages)->toBe([
            ['info', 'Executing report: Weekly Revenue (report_7654321)'],
            ['error', '✗ Report execution failed: Warehouse query timed out'],
        ])
        ->and($capsule->getConnection('mysql')->table('report_executions')->where('report_uuid', 'report-uuid-2')->pluck('status')->all())->toBe(['failed'])
        ->and($capsule->getConnection('mysql')->table('report_executions')->where('report_uuid', 'report-uuid-2')->where('status', 'failed')->value('error_message'))->toBe('Warehouse query timed out')
        ->and($capsule->getConnection('mysql')->table('reports')->where('uuid', 'report-uuid-2')->value('next_scheduled_run'))->toBe('2026-07-18 09:00:00');
});
