<?php

use Carbon\Carbon;
use Fleetbase\Models\Report;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class ReportModelCacheFake
{
    public array $values    = [];
    public array $puts      = [];
    public array $forgotten = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->puts[]       = compact('key', 'value', 'ttl');

        return true;
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
        unset($this->values[$key]);

        return true;
    }

    public function tags(array $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }
}

class ReportModelSpy extends Report
{
    public int $saves     = 0;
    public array $updates = [];

    public function save(array $options = []): bool
    {
        $this->saves++;
        $this->syncOriginal();

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);
        $this->syncOriginal();

        return true;
    }

    public function assertValidQueryConfig(): void
    {
        $this->validateQueryConfig();
    }

    public function writeCacheResults(array $results, array $columns, float $executionTime): void
    {
        $this->cacheResults($results, $columns, $executionTime);
    }

    public function nextRunFor(string $frequency, string $time, string $timezone): Carbon
    {
        return $this->calculateNextRun($frequency, $time, $timezone);
    }

    public function recordExecutionStats(float $executionTime, int $resultCount): void
    {
        $this->updateExecutionStats($executionTime, $resultCount);
    }

    public function writeExecutionLog(float $executionTime, int $resultCount, ?string $error = null): void
    {
        $this->logExecution($executionTime, $resultCount, $error);
    }

    public function writeExportLog(string $format, int $rowCount): void
    {
        $this->logExport($format, $rowCount);
    }
}

class ReportModelRegistrySpy extends ReportSchemaRegistry
{
    public array $calls = [];

    public function getAvailableTables(string $extension, ?string $category = null): array
    {
        $this->calls[] = ['getAvailableTables', $extension, $category];

        return [['name' => 'orders', 'extension' => $extension, 'category' => $category]];
    }

    public function getTableColumns(string $tableName): array
    {
        $this->calls[] = ['getTableColumns', $tableName];

        return [['name' => 'public_id']];
    }

    public function getTableRelationships(string $tableName): array
    {
        $this->calls[] = ['getTableRelationships', $tableName];

        return [['name' => 'payload']];
    }

    public function getTableSchema(string $tableName): array
    {
        $this->calls[] = ['getTableSchema', $tableName];

        return ['table' => ['name' => $tableName]];
    }
}

function report_model_container(): ReportModelCacheFake
{
    Illuminate\Database\Eloquent\Model::clearBootedModels();

    $container = bind_test_container([
        'fleetbase.connection.db' => 'mysql',
        'reports.cache_ttl'       => 900,
    ]);

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    config([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $cache = new ReportModelCacheFake();
    $container->instance('cache', $cache);
    Cache::swap($cache);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('report_executions', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('report_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->float('execution_time')->nullable();
        $table->integer('result_count')->nullable();
        $table->text('query_config')->nullable();
        $table->string('status')->nullable();
        $table->text('error_message')->nullable();
        $table->timestamp('executed_at')->nullable();
        $table->timestamps();
    });
    $schema->create('report_audit_logs', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('report_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('action')->nullable();
        $table->float('execution_time')->nullable();
        $table->integer('result_count')->nullable();
        $table->text('error_message')->nullable();
        $table->text('query_config')->nullable();
        $table->string('ip_address')->nullable();
        $table->string('user_agent')->nullable();
        $table->text('metadata')->nullable();
        $table->text('details')->nullable();
        $table->timestamps();
    });

    return $cache;
}

function report_model_query_config(array $overrides = []): array
{
    return array_replace_recursive([
        'table' => [
            'name'  => 'orders',
            'label' => 'Orders',
        ],
        'columns' => [
            ['name' => 'public_id', 'label' => 'Order ID'],
            ['name' => 'status', 'label' => 'Status'],
        ],
    ], $overrides);
}

it('exposes report relationships and forwards schema lookups to the registry', function () {
    report_model_container();

    $registry = new ReportModelRegistrySpy();
    app()->instance(ReportSchemaRegistry::class, $registry);

    $report = new Report();

    expect($report->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($report->createdBy()->getForeignKeyName())->toBe('created_by_uuid')
        ->and($report->executions()->getForeignKeyName())->toBe('report_uuid')
        ->and($report->auditLogs()->getForeignKeyName())->toBe('report_uuid')
        ->and(Report::getAvailableTables('fleetops', 'operations'))->toBe([['name' => 'orders', 'extension' => 'fleetops', 'category' => 'operations']])
        ->and(Report::getTableColumns('orders'))->toBe([['name' => 'public_id']])
        ->and(Report::getTableRelationships('orders'))->toBe([['name' => 'payload']])
        ->and(Report::getTableSchema('orders'))->toBe(['table' => ['name' => 'orders']])
        ->and($registry->calls)->toBe([
            ['getAvailableTables', 'fleetops', 'operations'],
            ['getTableColumns', 'orders'],
            ['getTableRelationships', 'orders'],
            ['getTableSchema', 'orders'],
        ]);
});

it('validates report query config shape and exposes simple source metadata', function () {
    report_model_container();

    $report = new ReportModelSpy();

    expect(fn () => $report->assertValidQueryConfig())
        ->toThrow(InvalidArgumentException::class, 'Query configuration is required');

    expect($report->getQueryComplexity())->toBe('invalid')
        ->and($report->export('csv'))->toBe([
            'success' => false,
            'error'   => 'Query configuration is required',
        ]);

    $report->query_config = ['table' => ['name' => 'orders']];

    expect(fn () => $report->assertValidQueryConfig())
        ->toThrow(InvalidArgumentException::class, 'Query configuration missing required key: columns');

    $report->query_config = ['table' => [], 'columns' => [['name' => 'public_id']]];

    expect(fn () => $report->assertValidQueryConfig())
        ->toThrow(InvalidArgumentException::class, 'Table name is required in query configuration');

    $report->query_config = ['table' => ['name' => 'orders'], 'columns' => []];

    expect(fn () => $report->assertValidQueryConfig())
        ->toThrow(InvalidArgumentException::class, 'At least one column must be selected');

    $validConfig          = report_model_query_config();
    $report->query_config = $validConfig;
    $report->assertValidQueryConfig();

    expect($report->getQueryComplexity())->toBe('simple')
        ->and($report->getSelectedColumnsCount())->toBe(2)
        ->and($report->getSourceTable())->toBe($validConfig['table'])
        ->and($report->hasFilters())->toBeFalse()
        ->and($report->hasJoins())->toBeFalse()
        ->and($report->hasGrouping())->toBeFalse()
        ->and($report->hasSorting())->toBeFalse()
        ->and($report->getFiltersSummary())->toBe([])
        ->and($report->getJoinsSummary())->toBe([]);
});

it('classifies query complexity and summarizes filters joins and auto joins', function () {
    report_model_container();

    $report               = new ReportModelSpy();
    $report->query_config = report_model_query_config([
        'columns' => [
            ['name' => 'public_id', 'label' => 'Order ID'],
            ['name' => 'payload_uuid', 'label' => 'Payload', 'auto_join_path' => 'payload.uuid'],
            ['name' => 'customer_uuid', 'label' => 'Customer', 'auto_join_path' => 'customer.uuid'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'status', 'label' => 'Status'],
                'operator' => ['value' => '=', 'label' => 'equals'],
                'value'    => 'dispatched',
            ],
            [
                'field'    => ['name' => 'created_at'],
                'operator' => ['value' => '>='],
                'value'    => '2026-07-01',
            ],
        ],
        'joins' => [
            [
                'table'           => 'payloads',
                'label'           => 'Payloads',
                'type'            => 'left',
                'selectedColumns' => ['public_id', 'tracking_number'],
            ],
        ],
        'groupBy' => ['status'],
        'sortBy'  => [['field' => 'created_at', 'direction' => 'desc']],
    ]);

    expect($report->getQueryComplexity())->toBe('complex')
        ->and($report->getSelectedColumnsCount())->toBe(5)
        ->and($report->hasFilters())->toBeTrue()
        ->and($report->hasJoins())->toBeTrue()
        ->and($report->hasGrouping())->toBeTrue()
        ->and($report->hasSorting())->toBeTrue()
        ->and($report->getFiltersSummary())->toBe([
            ['field' => 'Status', 'operator' => 'equals', 'value' => 'dispatched'],
            ['field' => 'created_at', 'operator' => '>=', 'value' => '2026-07-01'],
        ])
        ->and($report->getJoinsSummary())->toBe([
            ['table' => 'payloads', 'label' => 'Payloads', 'type' => 'LEFT', 'columns_count' => 2],
        ])
        ->and($report->hasAutoJoins())->toBeTrue()
        ->and($report->getAutoJoinColumns())->toHaveCount(2);

    $wideReport               = new Report();
    $wideReport->query_config = report_model_query_config([
        'columns' => array_map(fn ($index) => ['name' => "column_{$index}"], range(1, 11)),
    ]);

    expect($wideReport->getQueryComplexity())->toBe('complex');

    $filteredReport               = new Report();
    $filteredReport->query_config = report_model_query_config([
        'conditions' => [
            ['field' => 'status', 'operator' => '=', 'value' => 'pending'],
        ],
    ]);

    expect($filteredReport->getQueryComplexity())->toBe('moderate');
});

it('caches and clears report result payloads with stable report cache keys', function () {
    $cache = report_model_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00', 'UTC'));

    $report = new ReportModelSpy();
    $report->setRawAttributes(['uuid' => 'report-1'], true);

    $report->writeCacheResults([['status' => 'created']], ['status'], 42.5);

    expect($cache->puts)->toHaveCount(1)
        ->and($cache->puts[0]['key'])->toBe('report_results_report-1')
        ->and($cache->puts[0]['ttl'])->toBe(900)
        ->and($report->getCachedResults())->toBe([
            'results'        => [['status' => 'created']],
            'columns'        => ['status'],
            'execution_time' => 42.5,
            'cached_at'      => '2026-07-17T10:00:00.000000Z',
        ]);

    $report->clearCache();

    expect($cache->forgotten)->toBe(['report_results_report-1'])
        ->and($report->getCachedResults())->toBeNull();

    Carbon::setTestNow();
});

it('updates report execution metrics using rolling averages', function () {
    report_model_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 15:30:00', 'UTC'));

    $report = new ReportModelSpy();
    $report->setRawAttributes([
        'uuid'                   => 'report-averages',
        'execution_count'        => 2,
        'average_execution_time' => 100.0,
        'last_result_count'      => 50,
        'last_executed_at'       => Carbon::parse('2026-07-16 15:30:00', 'UTC'),
    ], true);

    $report->recordExecutionStats(40.0, 12);

    expect($report->execution_count)->toBe(3)
        ->and($report->average_execution_time)->toBe(80.0)
        ->and($report->last_result_count)->toBe(12)
        ->and($report->last_executed_at->toISOString())->toBe('2026-07-17T15:30:00.000000Z')
        ->and($report->saves)->toBe(1);

    Carbon::setTestNow();
});

it('writes report execution and export audit records through model relationships', function () {
    report_model_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 16:45:00', 'UTC'));

    $report = new ReportModelSpy();
    $report->setRawAttributes([
        'uuid'         => 'report-loggable',
        'query_config' => report_model_query_config(),
    ], true);

    $report->writeExecutionLog(75.25, 9, 'timeout');
    $report->writeExportLog('csv', 9);

    $execution = Capsule::connection('mysql')->table('report_executions')->where('report_uuid', 'report-loggable')->first();
    $audit     = Capsule::connection('mysql')->table('report_audit_logs')->where('report_uuid', 'report-loggable')->first();

    expect($execution->execution_time)->toBe(75.25)
        ->and($execution->result_count)->toBe(9)
        ->and($execution->error_message)->toBe('timeout')
        ->and($audit->action)->toBe('export');

    Carbon::setTestNow();
});

it('clones reports with reset execution metrics and schedules future runs', function () {
    report_model_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 09:30:00', 'Asia/Singapore'));

    $report = new ReportModelSpy();
    $report->setRawAttributes([
        'uuid'                   => 'report-1',
        'title'                  => 'Daily Orders',
        'query_config'           => report_model_query_config(),
        'execution_count'        => 8,
        'average_execution_time' => 25.5,
        'last_result_count'      => 200,
        'last_executed_at'       => Carbon::parse('2026-07-16 12:00:00', 'UTC'),
    ], true);

    $clone = $report->cloneWithConfig(report_model_query_config(['table' => ['name' => 'payloads']]));

    expect($clone)->toBeInstanceOf(ReportModelSpy::class)
        ->and($clone)->not->toBe($report)
        ->and($clone->title)->toBe('Daily Orders (Copy)')
        ->and($clone->query_config['table']['name'])->toBe('payloads')
        ->and($clone->execution_count)->toBe(0)
        ->and($clone->average_execution_time)->toBeNull()
        ->and($clone->last_result_count)->toBeNull()
        ->and($clone->last_executed_at)->toBeNull()
        ->and($clone->saves)->toBe(1);

    $report->schedule('daily', '08:00', 'Asia/Singapore');

    expect($report->updates)->toHaveCount(1)
        ->and($report->updates[0]['is_scheduled'])->toBeTrue()
        ->and($report->updates[0]['schedule_frequency'])->toBe('daily')
        ->and($report->updates[0]['schedule_time'])->toBe('08:00')
        ->and($report->updates[0]['schedule_timezone'])->toBe('Asia/Singapore')
        ->and($report->updates[0]['next_scheduled_run']->toISOString())->toBe('2026-07-18T00:00:00.000000Z')
        ->and($report->nextRunFor('weekly', '13:15', 'Asia/Singapore')->toISOString())->toBe('2026-07-20T05:15:00.000000Z')
        ->and($report->nextRunFor('monthly', '07:45', 'Asia/Singapore')->toISOString())->toBe('2026-07-31T23:45:00.000000Z')
        ->and($report->nextRunFor('custom', '07:45', 'Asia/Singapore')->toISOString())->toBe('2026-07-17T02:30:00.000000Z');

    Carbon::setTestNow();
});

it('reports performance metrics from current execution state', function () {
    report_model_container();

    $report               = new ReportModelSpy();
    $report->query_config = report_model_query_config([
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => '='],
                'value'    => 'created',
            ],
        ],
        'joins' => [
            [
                'table'           => 'payloads',
                'label'           => 'Payloads',
                'type'            => 'left',
                'selectedColumns' => ['public_id'],
            ],
        ],
        'sortBy' => [['field' => 'created_at']],
    ]);
    $report->setRawAttributes(array_merge($report->getAttributes(), [
        'execution_count'        => 3,
        'average_execution_time' => 12.75,
        'last_result_count'      => 44,
        'last_executed_at'       => Carbon::parse('2026-07-17 11:00:00', 'UTC'),
    ]), true);

    expect($report->getPerformanceMetrics())->toBe([
        'execution_count'        => 3,
        'average_execution_time' => 12.75,
        'last_result_count'      => 44,
        'last_executed_at'       => '2026-07-17T11:00:00.000000Z',
        'complexity'             => 'complex',
        'selected_columns_count' => 3,
        'has_joins'              => true,
        'has_filters'            => true,
        'has_grouping'           => false,
        'has_sorting'            => true,
    ]);
});
