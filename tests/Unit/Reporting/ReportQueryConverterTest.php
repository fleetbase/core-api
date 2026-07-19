<?php

use Fleetbase\Support\Reporting\ReportQueryConverter;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

function report_converter_registry_fixture(): ReportSchemaRegistry
{
    $registry = new ReportSchemaRegistry();
    $registry->setCacheEnabled(false);

    $pickup = Relationship::hasAutoJoin('pickup', 'locations')
        ->localKey('pickup_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('city'),
            Column::make('street1'),
        ]);

    $payload = Relationship::hasAutoJoin('payload', 'payloads')
        ->localKey('payload_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('description'),
            Column::make('pickup_uuid'),
        ])
        ->with([$pickup]);

    $registry->registerTable(
        Table::make('orders')
            ->columns([
                Column::make('uuid'),
                Column::make('company_uuid'),
                Column::make('tracking_number'),
                Column::make('status'),
                Column::make('payload_uuid'),
                Column::make('total', 'decimal')->aggregatable(),
            ])
            ->relationships([$payload])
    );

    $registry->registerTable(
        Table::make('payloads')
            ->columns([
                Column::make('uuid'),
                Column::make('description'),
                Column::make('pickup_uuid'),
            ])
    );

    return $registry;
}

function report_converter_database_fixture(mixed $companyUuid = 'company-1'): void
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container();

    session()->flush();
    if ($companyUuid !== null) {
        session(['company' => $companyUuid]);
    }

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    config([
        'database.default'             => 'testing',
        'database.connections.testing' => $connectionConfig,
    ]);

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('tracking_number');
        $table->string('status');
        $table->string('payload_uuid')->nullable();
        $table->decimal('total', 10, 2)->default(0);
    });
    $schema->create('payloads', function ($table) {
        $table->string('uuid')->primary();
        $table->string('description');
        $table->string('pickup_uuid')->nullable();
    });
    $schema->create('locations', function ($table) {
        $table->string('uuid')->primary();
        $table->string('city');
        $table->string('street1')->nullable();
    });

    $connection = $capsule->getConnection('testing');
    $connection->table('locations')->insert([
        ['uuid' => 'location-1', 'city' => 'Ulaanbaatar', 'street1' => 'Peace Avenue'],
        ['uuid' => 'location-2', 'city' => 'Erdenet', 'street1' => 'Main Road'],
    ]);
    $connection->table('payloads')->insert([
        ['uuid' => 'payload-1', 'description' => 'Electronics', 'pickup_uuid' => 'location-1'],
        ['uuid' => 'payload-2', 'description' => 'Groceries', 'pickup_uuid' => 'location-2'],
        ['uuid' => 'payload-3', 'description' => 'Parts', 'pickup_uuid' => 'location-1'],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1', 'tracking_number' => 'T-001', 'status' => 'dispatched', 'payload_uuid' => 'payload-1', 'total' => 125.50],
        ['uuid' => 'order-2', 'company_uuid' => 'company-1', 'tracking_number' => 'T-002', 'status' => 'pending', 'payload_uuid' => 'payload-2', 'total' => 75.00],
        ['uuid' => 'order-3', 'company_uuid' => 'company-2', 'tracking_number' => 'T-003', 'status' => 'dispatched', 'payload_uuid' => 'payload-3', 'total' => 999.00],
    ]);
}

function report_converter_execute(array $config, mixed $companyUuid = 'company-1'): array
{
    report_converter_database_fixture($companyUuid);

    return (new ReportQueryConverter(report_converter_registry_fixture(), $config))->execute();
}

test('report query converter executes tenant scoped nested auto join queries with computed columns', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number', 'label' => 'Tracking Number'],
            ['name' => 'payload.description', 'label' => 'Payload'],
            ['name' => 'payload.pickup.city', 'label' => 'Pickup City'],
        ],
        'computed_columns' => [
            ['name' => 'status_upper', 'expression' => 'UPPER(status)', 'type' => 'string', 'label' => 'Status Upper'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'payload.pickup.city'],
                'operator' => ['value' => '='],
                'value'    => 'Ulaanbaatar',
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'tracking_number'],
                'direction' => ['value' => 'desc'],
            ],
        ],
        'limit' => 10,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->tracking_number)->toBe('T-001')
        ->and($result['data'][0]->payload_description)->toBe('Electronics')
        ->and($result['data'][0]->payload_pickup_city)->toBe('Ulaanbaatar')
        ->and($result['data'][0]->status_upper)->toBe('DISPATCHED')
        ->and($result['meta']['query_bindings'])->toContain('company-1')
        ->and($result['meta']['query_bindings'])->toContain('Ulaanbaatar')
        ->and($result['meta']['joined_tables'])->toHaveCount(2)
        ->and($result['meta']['joined_tables'][0]['path'])->toBe('payload')
        ->and($result['meta']['joined_tables'][1]['path'])->toBe('payload.pickup')
        ->and($result['columns'])->sequence(
            fn ($column) => $column->name->toBe('tracking_number'),
            fn ($column) => $column->name->toBe('payload_description'),
            fn ($column) => $column->name->toBe('payload_pickup_city'),
            fn ($column) => $column->name->toBe('status_upper')->computed->toBeTrue(),
        );
});

test('report query converter builds grouped aggregate result metadata and skips unsafe group ordering', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'status', 'label' => 'Status'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status', 'alias' => 'order_status'],
                'aggregateFn' => ['value' => 'sum'],
                'aggregateBy' => ['name' => 'total'],
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'sum_total'],
                'direction' => ['value' => 'desc'],
            ],
            [
                'column'    => ['name' => 'tracking_number'],
                'direction' => ['value' => 'asc'],
            ],
        ],
        'limit' => 10,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveCount(2)
        ->and(array_map(fn ($row) => $row->order_status, $result['data']))->toBe(['dispatched', 'pending'])
        ->and((float) $result['data'][0]->sum_total)->toBe(125.5)
        ->and((float) $result['data'][1]->sum_total)->toBe(75.0)
        ->and($result['meta']['selected_columns'])->toContain('order_status')
        ->and($result['meta']['selected_columns'])->toContain('sum_total')
        ->and($result['meta']['query_sql'])->toContain('group by')
        ->and($result['meta']['query_sql'])->toContain('`sum_total` desc')
        ->and($result['meta']['query_sql'])->not->toContain('tracking_number asc')
        ->and($result['columns'])->toContainEqual([
            'name'           => 'sum_total',
            'column_name'    => 'sum_total',
            'label'          => 'Sum (total)',
            'type'           => 'decimal',
            'auto_join_path' => null,
        ]);
});

test('report query converter applies validator accepted operators and manual join aliases', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
            ['name' => 'manual_payload.description', 'alias' => 'manual_description'],
        ],
        'joins' => [
            [
                'name'       => 'manual_payload',
                'table'      => 'payloads',
                'alias'      => 'manual_payload',
                'type'       => 'left',
                'localTable' => 'orders',
                'localKey'   => 'payload_uuid',
                'foreignKey' => 'uuid',
            ],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'eq'],
                'value'    => 'dispatched',
            ],
            [
                'field'    => ['name' => 'tracking_number'],
                'operator' => ['value' => 'starts_with'],
                'value'    => 'T-00',
            ],
            [
                'field'    => ['name' => 'manual_payload.description'],
                'operator' => ['value' => 'contains'],
                'value'    => 'tron',
            ],
            [
                'boolean'    => 'or',
                'conditions' => [
                    [
                        'field'    => ['name' => 'total'],
                        'operator' => ['value' => 'gte'],
                        'value'    => 100,
                    ],
                    [
                        'field'    => ['name' => 'tracking_number'],
                        'operator' => ['value' => 'ends_with'],
                        'value'    => '001',
                    ],
                ],
            ],
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'not_in'],
                'value'    => 'cancelled, failed',
            ],
            [
                'field'    => ['name' => 'payload_uuid'],
                'operator' => ['value' => 'is_not_null'],
                'value'    => null,
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'manual_payload.description'],
                'direction' => ['value' => 'asc'],
            ],
        ],
        'limit' => 10,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->tracking_number)->toBe('T-001')
        ->and($result['data'][0]->manual_description)->toBe('Electronics')
        ->and($result['meta']['manual_joins_used'])->toBe([
            [
                'table'       => 'payloads',
                'alias'       => 'manual_payload',
                'type'        => 'left',
                'local_key'   => 'payload_uuid',
                'foreign_key' => 'uuid',
            ],
        ])
        ->and($result['meta']['query_sql'])->toContain('"manual_payload"."description" LIKE ?')
        ->and($result['meta']['query_sql'])->toContain('"orders"."payload_uuid" is not null')
        ->and($result['meta']['query_bindings'])->toContain('company-1')
        ->and($result['meta']['query_bindings'])->toContain('dispatched')
        ->and($result['meta']['query_bindings'])->toContain('T-00%')
        ->and($result['meta']['query_bindings'])->toContain('%tron%')
        ->and($result['meta']['query_bindings'])->toContain(100);
});

test('report query converter returns structured failures for missing tenant scope and invalid configs', function () {
    $missingCompany = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ], null);

    expect($missingCompany['success'])->toBeFalse()
        ->and($missingCompany['error'])->toBe('No active company in session; cannot scope report by company_uuid.')
        ->and($missingCompany['meta'])->toHaveKey('execution_time_ms');

    $invalidComputedColumn = report_converter_execute([
        'table'            => ['name' => 'orders'],
        'columns'          => [],
        'computed_columns' => [
            ['name' => 'dangerous', 'expression' => 'DROP(status)'],
        ],
    ]);

    expect($invalidComputedColumn['success'])->toBeFalse()
        ->and($invalidComputedColumn['error'])->toContain("Invalid computed column 'dangerous'")
        ->and($invalidComputedColumn['error'])->toContain('forbidden SQL keyword: DROP');
});

test('report query converter exports successful results and exposes supported export formats', function () {
    report_converter_database_fixture();

    $converter = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number', 'label' => 'Tracking Number'],
            ['name' => 'status', 'label' => 'Status'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'eq'],
                'value'    => 'pending',
            ],
        ],
    ]);

    $export  = $converter->export('csv');
    $content = file_get_contents($export['filepath']);

    expect($export['success'])->toBeTrue()
        ->and($export['format'])->toBe('csv')
        ->and($export['filename'])->toStartWith('report-orders-')
        ->and($export['rows'])->toBe(1)
        ->and($export['size'])->toBeGreaterThan(0)
        ->and($content)->toContain('"Tracking Number",Status')
        ->and($content)->toContain('T-002,pending')
        ->and(array_keys($converter->getAvailableExportFormats()))->toContain('csv', 'json', 'excel');
});

test('report query converter returns execution failure when exporting invalid queries', function () {
    report_converter_database_fixture(null);

    $converter = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ]);

    $export = $converter->export('json');

    expect($export['success'])->toBeFalse()
        ->and($export['error'])->toBe('No active company in session; cannot scope report by company_uuid.');
});

test('report query converter analyzes structural complexity without executing sql', function () {
    $simple = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ]);

    $complex = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'            => ['name' => 'orders'],
        'columns'          => array_fill(0, 22, ['name' => 'tracking_number']),
        'computed_columns' => [
            ['name' => 'status_upper', 'expression' => 'UPPER(status)'],
            ['name' => 'total_label', 'expression' => "CONCAT('total:', total)"],
        ],
        'joins' => [
            ['table' => 'payloads'],
        ],
        'conditions' => [
            ['conditions' => array_fill(0, 6, [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'eq'],
                'value'    => 'pending',
            ])],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status'],
                'aggregateFn' => ['value' => 'count'],
                'aggregateBy' => ['name' => '*'],
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'count_all'],
                'direction' => ['value' => 'desc'],
            ],
        ],
        'limit' => 25,
    ]);

    expect($simple->getQueryAnalysis())->toMatchArray([
        'table_name'             => 'orders',
        'complexity'             => 'simple',
        'joins_count'            => 0,
        'selected_columns_count' => 1,
        'conditions_count'       => 0,
        'has_limit'              => false,
    ])
        ->and($complex->getQueryAnalysis())->toMatchArray([
            'table_name'             => 'orders',
            'complexity'             => 'complex',
            'joins_count'            => 1,
            'selected_columns_count' => 24,
            'conditions_count'       => 6,
            'group_by_count'         => 1,
            'sort_by_count'          => 1,
            'has_limit'              => true,
            'limit'                  => 25,
        ]);
});

test('report query converter resolves company scope from object and array session values', function () {
    $objectResult = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'tracking_number'],
                'direction' => ['value' => 'asc'],
            ],
        ],
    ], (object) ['company_uuid' => 'company-1']);

    $arrayResult = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ], ['id' => 'company-2']);

    expect($objectResult['success'])->toBeTrue()
        ->and(array_map(fn ($row) => $row->tracking_number, $objectResult['data']))->toBe(['T-001', 'T-002'])
        ->and($arrayResult['success'])->toBeTrue()
        ->and($arrayResult['data'])->toHaveCount(1)
        ->and($arrayResult['data'][0]->tracking_number)->toBe('T-003');
});

test('report query converter applies remaining scalar condition operators and offsets', function () {
    $notEqual = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'neq'],
                'value'    => 'dispatched',
            ],
        ],
    ]);

    $lessThanOrEqual = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'total'],
                'operator' => ['value' => 'lte'],
                'value'    => 75,
            ],
        ],
    ]);

    $notLike = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'tracking_number'],
                'operator' => ['value' => 'not_like'],
                'value'    => '001',
            ],
        ],
    ]);

    $inArray = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'status'],
                'operator' => ['value' => 'in'],
                'value'    => ['pending'],
            ],
        ],
    ]);

    $notBetween = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'total'],
                'operator' => ['value' => 'not_between'],
                'value'    => [100, 200],
            ],
        ],
    ]);

    $nullPayload = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'payload_uuid'],
                'operator' => ['value' => 'null'],
                'value'    => null,
            ],
        ],
    ]);

    $offset = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'tracking_number'],
                'direction' => ['value' => 'asc'],
            ],
        ],
        'limit'  => 1,
        'offset' => 1,
    ]);

    expect($notEqual['data'][0]->tracking_number)->toBe('T-002')
        ->and($lessThanOrEqual['data'][0]->tracking_number)->toBe('T-002')
        ->and($notLike['data'][0]->tracking_number)->toBe('T-002')
        ->and($inArray['data'][0]->tracking_number)->toBe('T-002')
        ->and($notBetween['data'][0]->tracking_number)->toBe('T-002')
        ->and($nullPayload['data'])->toBe([])
        ->and($offset['data'])->toHaveCount(1)
        ->and($offset['data'][0]->tracking_number)->toBe('T-002');
});

test('report query converter aggregates computed group by metadata supplied by aggregate definitions', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'status'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status'],
                'aggregateFn' => ['value' => 'sum'],
                'aggregateBy' => [
                    'name'        => 'gross_total',
                    'computed'    => true,
                    'computation' => 'total * 2',
                    'type'        => 'decimal',
                    'label'       => 'Gross Total',
                ],
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'sum_gross_total'],
                'direction' => ['value' => 'desc'],
            ],
        ],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveCount(2)
        ->and((float) $result['data'][0]->sum_gross_total)->toBe(251.0)
        ->and((float) $result['data'][1]->sum_gross_total)->toBe(150.0)
        ->and($result['meta']['query_sql'])->toContain('SUM(orders.total * 2)')
        ->and($result['columns'])->toContainEqual([
            'name'        => 'gross_total',
            'column_name' => 'gross_total',
            'label'       => 'Gross Total',
            'type'        => 'decimal',
            'computed'    => true,
            'expression'  => 'total * 2',
        ])
        ->and($result['columns'])->toContainEqual([
            'name'           => 'sum_gross_total',
            'column_name'    => 'sum_gross_total',
            'label'          => 'Sum (gross_total)',
            'type'           => 'decimal',
            'auto_join_path' => null,
        ]);
});
