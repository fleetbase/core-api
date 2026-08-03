<?php

use Fleetbase\Support\Reporting\ReportQueryConverter;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
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

function report_converter_call(object $target, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$arguments);
}

function report_converter_property(object $target, string $property, mixed $value = null, bool $set = false): mixed
{
    $reflection = new ReflectionProperty($target, $property);
    $reflection->setAccessible(true);

    if ($set) {
        $reflection->setValue($target, $value);
    }

    return $reflection->getValue($target);
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

test('report query converter groups and aggregates through nested auto join relationship paths', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'payload.pickup.city', 'label' => 'Pickup City'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'payload.pickup.city', 'alias' => 'pickup_city'],
                'aggregateFn' => ['value' => 'min'],
                'aggregateBy' => ['name' => 'payload.description'],
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'payload.pickup.city', 'alias' => 'pickup_city'],
                'direction' => ['value' => 'asc'],
            ],
        ],
        'limit' => 10,
    ]);

    expect($result['success'])->toBeTrue()
        ->and(array_map(fn ($row) => $row->pickup_city, $result['data']))->toBe(['Erdenet', 'Ulaanbaatar'])
        ->and(array_map(fn ($row) => $row->min_payload_description, $result['data']))->toBe(['Groceries', 'Electronics'])
        ->and($result['meta']['joined_tables'])->toHaveCount(2)
        ->and($result['meta']['joined_tables'][0]['path'])->toBe('payload')
        ->and($result['meta']['joined_tables'][1]['path'])->toBe('payload.pickup')
        ->and($result['meta']['query_sql'])->toContain('MIN(orders_payload.description)')
        ->and($result['meta']['query_sql'])->toContain('group by')
        ->and($result['meta']['query_sql'])->toContain('`pickup_city` asc')
        ->and($result['columns'])->toContainEqual([
            'name'           => 'min_payload_description',
            'column_name'    => 'min_payload_description',
            'label'          => 'Min (payload.description)',
            'type'           => 'string',
            'auto_join_path' => null,
        ]);
});

test('report query converter groups count all aggregates and skips incomplete aggregate definitions', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'status'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status'],
                'aggregateFn' => ['value' => 'count'],
                'aggregateBy' => ['name' => '*'],
            ],
            [
                'groupBy'     => ['name' => 'payload.pickup.city', 'alias' => 'pickup_city'],
                'aggregateFn' => ['value' => ''],
                'aggregateBy' => ['name' => 'payload.description'],
            ],
        ],
        'sortBy' => [
            [
                'column'    => ['name' => 'count_all'],
                'direction' => ['value' => 'desc'],
            ],
        ],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveCount(2)
        ->and($result['data'][0]->count_all)->toBe(1)
        ->and($result['meta']['query_sql'])->toContain('COUNT(*) as `count_all`')
        ->and($result['meta']['query_sql'])->not->toContain('payload_description')
        ->and($result['columns'])->toContainEqual([
            'name'           => 'count_all',
            'column_name'    => 'count_all',
            'label'          => 'Count',
            'type'           => 'integer',
            'auto_join_path' => null,
        ]);
});

test('report query converter applies manual join default keys aliases and types', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
            ['name' => 'payloads.description', 'alias' => 'default_join_description'],
        ],
        'joins' => [
            [
                'table'      => 'payloads',
                'localTable' => 'orders',
            ],
        ],
        'limit' => 1,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveCount(1)
        ->and($result['data'][0]->default_join_description)->toBeNull()
        ->and($result['meta']['manual_joins_used'])->toBe([
            [
                'table'       => 'payloads',
                'alias'       => 'payloads',
                'type'        => 'left',
                'local_key'   => 'uuid',
                'foreign_key' => 'uuid',
            ],
        ])
        ->and($result['meta']['query_sql'])->toContain('"orders"."uuid" = "payloads"."uuid"');
});

test('report query converter helper contracts resolve aliases computed references and repeated auto joins', function () {
    report_converter_database_fixture();

    $converter = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'            => ['name' => 'orders'],
        'columns'          => [
            ['name' => 'tracking_number'],
        ],
        'computed_columns' => [
            [
                'name'       => 'pickup_label',
                'expression' => "CONCAT(payload.pickup.city, '-', tracking_number)",
            ],
            [
                'name'       => 'decorated_label',
                'expression' => "CONCAT(\"literal.with.dot\", pickup_label, 'quoted.value')",
            ],
        ],
    ]);

    report_converter_property($converter, 'joinAliases', [
        'payload'        => 'orders_payload',
        'payload.pickup' => 'orders_payload_pickup',
    ], true);

    $paths                = [];
    $collectComputedPaths = new ReflectionMethod($converter, 'collectAutoJoinPathsFromComputedColumns');
    $collectComputedPaths->setAccessible(true);
    $collectComputedPaths->invokeArgs($converter, [[
        ['expression' => ''],
        ['expression' => 'decorated_label'],
    ], &$paths, 'orders']);

    $conditionPaths        = [];
    $collectConditionPaths = new ReflectionMethod($converter, 'collectAutoJoinPathsFromConditions');
    $collectConditionPaths->setAccessible(true);
    $collectConditionPaths->invokeArgs($converter, [[
        [
            'conditions' => [
                [
                    'field' => [
                        'name' => 'payload.pickup.city',
                    ],
                ],
            ],
        ],
        [
            'field' => [
                'auto_join_path' => 'payload',
            ],
        ],
    ], &$conditionPaths]);

    expect(report_converter_call($converter, 'resolveAliasAndColumn', 'orders', 'tracking_number'))->toBe(['orders', 'tracking_number'])
        ->and(report_converter_call($converter, 'resolveAliasAndColumn', 'orders', 'payload.pickup.city'))->toBe(['orders_payload_pickup', 'city'])
        ->and(report_converter_call($converter, 'resolveAliasAndColumn', 'orders', 'payload.unknown_label'))->toBe(['orders_payload', 'unknown_label'])
        ->and(report_converter_call($converter, 'resolveAliasAndColumn', 'orders', 'missing.path.city'))->toBe(['orders', 'missing.path.city'])
        ->and($paths)->toBe(['payload.pickup'])
        ->and($conditionPaths)->toBe(['payload.pickup', 'payload'])
        ->and(report_converter_call($converter, 'extractRelationshipPathsFromExpression', 'decorated_label', 'orders'))->toBe(['payload.pickup']);

    report_converter_property($converter, 'joinAliases', [
        'payload' => 'orders_payload',
    ], true);

    expect(report_converter_call($converter, 'resolveAliasAndColumn', 'orders', 'payload.pickup.city'))->toBe(['orders_payload', 'city']);

    report_converter_property($converter, 'joinAliases', [
        'payload'        => 'orders_payload',
        'payload.pickup' => 'orders_payload_pickup',
    ], true);

    $resolved = report_converter_call($converter, 'resolveComputedColumnReferences', 'CASE WHEN decorated_label IS NULL THEN "literal.with.dot" ELSE CONCAT(decorated_label, \'x.y\') END', 'orders');

    expect($resolved)
        ->toContain('orders_payload_pickup.city')
        ->toContain('orders.tracking_number')
        ->toContain('"literal.with.dot"')
        ->toContain("'quoted.value'")
        ->toContain("'x.y'");

    report_converter_property($converter, 'joinAliases', [], true);
    report_converter_call($converter, 'applyAutoJoinPath', DB::table('orders'), 'orders', 'payload.pickup');
    $firstAutoJoins = report_converter_property($converter, 'autoJoins');

    report_converter_call($converter, 'applyAutoJoinPath', DB::table('orders'), 'orders', 'payload.pickup');
    report_converter_call($converter, 'applyAutoJoinPath', DB::table('orders'), 'orders', 'payload.missing');

    expect($firstAutoJoins)->toHaveCount(2)
        ->and(array_column($firstAutoJoins, 'path'))->toBe(['payload', 'payload.pickup'])
        ->and(report_converter_property($converter, 'autoJoins'))->toHaveCount(2);

    report_converter_property($converter, 'autoJoins', [], true);
    report_converter_property($converter, 'joinAliases', [], true);
    report_converter_call($converter, 'applyAutoJoin', DB::table('orders'), 'orders', 'payload');

    expect(report_converter_property($converter, 'autoJoins'))->toBe([
        [
            'path'        => 'payload',
            'table'       => 'payloads',
            'alias'       => 'orders_payload',
            'type'        => 'left',
            'local_key'   => 'payload_uuid',
            'foreign_key' => 'uuid',
        ],
    ]);

    $manualOnlyRegistry = report_converter_registry_fixture();
    $manualOnlyRegistry->registerTable(
        Table::make('manual_orders')
            ->columns([
                Column::make('uuid'),
                Column::make('manual_payload_uuid'),
            ])
            ->relationships([
                Relationship::belongsTo('manual_payload', 'payloads')
                    ->localKey('manual_payload_uuid')
                    ->foreignKey('uuid'),
            ])
    );
    $manualOnlyConverter = new ReportQueryConverter($manualOnlyRegistry, [
        'table'   => ['name' => 'manual_orders'],
        'columns' => [
            ['name' => 'uuid'],
        ],
    ]);

    report_converter_call($manualOnlyConverter, 'applyAutoJoin', DB::table('manual_orders'), 'manual_orders', 'manual_payload');

    expect(report_converter_property($manualOnlyConverter, 'autoJoins'))->toBe([]);

    $missingTableConverter = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'   => ['name' => 'missing_reports'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ]);

    expect(fn () => report_converter_call($missingTableConverter, 'buildQuery'))
        ->toThrow(InvalidArgumentException::class, "Table 'missing_reports' not found in registry");

    $invalidComputedColumnConverter = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'            => ['name' => 'orders'],
        'columns'          => [],
        'computed_columns' => [
            ['name' => 'missing_expression'],
        ],
    ]);
    $selects              = [];
    $buildComputedColumns = new ReflectionMethod($invalidComputedColumnConverter, 'buildComputedColumns');
    $buildComputedColumns->setAccessible(true);

    expect(fn () => $buildComputedColumns->invokeArgs($invalidComputedColumnConverter, [DB::table('orders'), &$selects]))
        ->toThrow(InvalidArgumentException::class, 'Computed column must have both name and expression');
});

test('report query converter honors explicit auto join paths and malformed aggregate metadata defensively', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            [
                'name'           => 'payload.pickup.city',
                'label'          => 'Pickup City',
                'auto_join_path' => 'payload.pickup',
            ],
        ],
        'limit' => 1,
    ]);

    $converter = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'status'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status'],
                'aggregateBy' => null,
            ],
        ],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'][0]->payload_pickup_city)->toBe('Ulaanbaatar')
        ->and($result['meta']['joined_tables'])->toHaveCount(2)
        ->and(report_converter_property($converter, 'queryConfig')['computed_columns'])->toBe([]);
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

test('report query converter returns stable validation errors for malformed query configurations', function () {
    $missingTable = report_converter_execute([
        'table'   => [],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ]);

    $missingColumns = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [],
    ]);

    $unknownTable = report_converter_execute([
        'table'   => ['name' => 'missing_reports'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ]);

    $unknownColumn = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'not_allowed'],
        ],
    ]);

    $ungroupedColumn = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
            ['name' => 'status'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status'],
                'aggregateFn' => ['value' => 'sum'],
                'aggregateBy' => ['name' => 'total'],
            ],
        ],
    ]);

    expect($missingTable['success'])->toBeFalse()
        ->and($missingTable['error'])->toBe('Table name is required')
        ->and($missingColumns['success'])->toBeFalse()
        ->and($missingColumns['error'])->toBe('At least one column or computed column must be selected')
        ->and($unknownTable['success'])->toBeFalse()
        ->and($unknownTable['error'])->toBe("Table 'missing_reports' is not registered")
        ->and($unknownColumn['success'])->toBeFalse()
        ->and($unknownColumn['error'])->toBe("Column 'not_allowed' is not allowed for table 'orders'")
        ->and($ungroupedColumn['success'])->toBeFalse()
        ->and($ungroupedColumn['error'])->toBe("Column 'tracking_number' must be grouped or aggregated when GROUP BY is used");
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

test('report query converter covers protected compatibility helpers and unresolved aliases', function () {
    report_converter_database_fixture();

    $converter = new ReportQueryConverter(report_converter_registry_fixture(), [
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'joins' => [
            ['table' => 'payloads', 'alias' => 'payload_alias'],
        ],
    ]);

    $query = DB::table('orders');

    expect(report_converter_call($converter, 'isConfiguredColumnAllowed', 'orders', 'missing_alias.description'))->toBeFalse()
        ->and(report_converter_call($converter, 'addForeignKeyColumns', $query, 'orders'))->toBeNull()
        ->and(report_converter_call($converter, 'createJoinsForComputedColumn', $query, 'payload.description', 'orders'))->toBeNull();
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

    $invalidSessionValue = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
    ], 123);

    expect($objectResult['success'])->toBeTrue()
        ->and(array_map(fn ($row) => $row->tracking_number, $objectResult['data']))->toBe(['T-001', 'T-002'])
        ->and($arrayResult['success'])->toBeTrue()
        ->and($arrayResult['data'])->toHaveCount(1)
        ->and($arrayResult['data'][0]->tracking_number)->toBe('T-003')
        ->and($invalidSessionValue['success'])->toBeFalse()
        ->and($invalidSessionValue['error'])->toBe('No active company in session; cannot scope report by company_uuid.');
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

    $greaterThan = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'total'],
                'operator' => ['value' => 'gt'],
                'value'    => 100,
            ],
        ],
    ]);

    $lessThan = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'total'],
                'operator' => ['value' => 'lt'],
                'value'    => 100,
            ],
        ],
    ]);

    $between = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number'],
        ],
        'conditions' => [
            [
                'field'    => ['name' => 'total'],
                'operator' => ['value' => 'between'],
                'value'    => [70, 80],
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
        ->and($greaterThan['data'][0]->tracking_number)->toBe('T-001')
        ->and($lessThan['data'][0]->tracking_number)->toBe('T-002')
        ->and($between['data'][0]->tracking_number)->toBe('T-002')
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

// -----------------------------------------------------------------------------
// H2 hardening: manual-join enforcement (identifier + registry validation) and
// tenant-scoping of joined tables on the real execution path.
// -----------------------------------------------------------------------------

test('report query converter rejects a manual join to an unregistered table', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [['name' => 'tracking_number']],
        'joins'   => [
            [
                'table'      => 'personal_access_tokens', // not in the registry
                'localTable' => 'orders',
                'localKey'   => 'uuid',
                'foreignKey' => 'tokenable_id',
            ],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe("Join table 'personal_access_tokens' is not registered");
});

test('report query converter rejects a malicious column alias before building sql', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'tracking_number', 'alias' => 'ok`, (select secret from users) as `leak'],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Invalid column alias');
});

test('report query converter rejects a malicious group-by alias before building sql', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [
            ['name' => 'status', 'label' => 'Status'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'status', 'alias' => 'ok`, (select secret from users) as `leak'],
                'aggregateFn' => ['value' => 'sum'],
                'aggregateBy' => ['name' => 'total'],
            ],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Invalid group-by alias');
});

test('report query converter rejects malicious identifiers in a manual join', function () {
    $result = report_converter_execute([
        'table'   => ['name' => 'orders'],
        'columns' => [['name' => 'tracking_number']],
        'joins'   => [
            [
                'table'      => 'payloads',
                'localTable' => 'orders',
                'localKey'   => 'uuid',
                'foreignKey' => 'uuid) ON 1=1 -- ',
            ],
        ],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Invalid identifier');
});

/**
 * Self-contained fixture with two tenant-scoped tables (widgets → gadgets, both
 * carrying company_uuid) so we can prove that a manual join to a company-scoped
 * table does not leak another tenant's rows through the join.
 */
function report_converter_join_scope_registry(): ReportSchemaRegistry
{
    $registry = new ReportSchemaRegistry();
    $registry->setCacheEnabled(false);

    $registry->registerTable(
        Table::make('widgets')->columns([
            Column::make('uuid'),
            Column::make('company_uuid'),
            Column::make('name'),
        ])
    );
    $registry->registerTable(
        Table::make('gadgets')->columns([
            Column::make('uuid'),
            Column::make('company_uuid'),
            Column::make('widget_uuid'),
            Column::make('label'),
        ])
    );

    return $registry;
}

function report_converter_join_scope_fixture(): void
{
    $connectionConfig = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $container        = bind_test_container();

    session()->flush();
    session(['company' => 'company-1']);

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
    $schema->create('widgets', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('name');
    });
    $schema->create('gadgets', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('widget_uuid');
        $table->string('label');
    });

    $connection = $capsule->getConnection('testing');
    $connection->table('widgets')->insert([
        ['uuid' => 'widget-1', 'company_uuid' => 'company-1', 'name' => 'Mine'],
        ['uuid' => 'widget-2', 'company_uuid' => 'company-2', 'name' => 'Theirs'],
    ]);
    // gadget-mine and gadget-theirs both reference widget-1's uuid, but belong to
    // different companies — the cross-tenant leak vector through the join key.
    $connection->table('gadgets')->insert([
        ['uuid' => 'gadget-mine', 'company_uuid' => 'company-1', 'widget_uuid' => 'widget-1', 'label' => 'Gadget Mine'],
        ['uuid' => 'gadget-theirs', 'company_uuid' => 'company-2', 'widget_uuid' => 'widget-1', 'label' => 'Gadget Theirs'],
    ]);
}

test('report query converter scopes a joined tenant table to the active company', function () {
    report_converter_join_scope_fixture();

    $result = (new ReportQueryConverter(report_converter_join_scope_registry(), [
        'table'   => ['name' => 'widgets'],
        'columns' => [
            ['name' => 'name'],
            ['name' => 'gadgets.label', 'alias' => 'gadget_label'],
        ],
        'joins' => [
            [
                'table'      => 'gadgets',
                'name'       => 'gadgets',
                'localTable' => 'widgets',
                'localKey'   => 'uuid',
                'foreignKey' => 'widget_uuid',
            ],
        ],
    ]))->execute();

    $labels = collect($result['data'])->pluck('gadget_label')->all();

    expect($result['success'])->toBeTrue()
        // Only the active company's gadget is joined; the foreign-tenant row that shares
        // the same widget_uuid must NOT leak through the join.
        ->and($labels)->toContain('Gadget Mine')
        ->and($labels)->not->toContain('Gadget Theirs')
        // The company scope must live in the JOIN ON clause and be bound (not inlined).
        ->and($result['meta']['query_bindings'])->toContain('company-1');
});
