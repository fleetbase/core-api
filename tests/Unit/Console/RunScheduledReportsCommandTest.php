<?php

use Fleetbase\Console\Commands\RunScheduledReports;
use Fleetbase\Models\Report;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class RunScheduledReportsTestCommand extends RunScheduledReports
{
    public array $messages        = [];
    public array $executedReports = [];

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
        $table->string('schedule_frequency')->nullable();
        $table->timestamp('next_scheduled_run')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $capsule->getConnection('mysql')->table('reports')->insert([
        'uuid'               => 'report-uuid-1',
        'public_id'          => 'report_1234567',
        'title'              => 'Daily Orders',
        'schedule_frequency' => 'daily',
        'next_scheduled_run' => '2026-07-17 08:00:00',
        'created_at'         => '2026-07-17 07:00:00',
        'updated_at'         => '2026-07-17 07:00:00',
        'deleted_at'         => null,
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
