<?php

use Fleetbase\Support\Reporting\ReportQueryConverter;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

/*
 * Order reporting as a storefront merchant needs it: orders -> payload -> entities (the line
 * items), totals kept in JSON meta, soft-deleted rows, and a second tenant that must never leak.
 */

function order_reporting_registry(): ReportSchemaRegistry
{
    $registry = new ReportSchemaRegistry();
    $registry->setCacheEnabled(false);

    $registry->registerTable(
        Table::make('orders')
            ->softDeletes()
            ->excludeColumns(['uuid', 'deleted_at'])
            ->columns([
                Column::make('id', 'integer'),
                Column::make('public_id'),
                Column::make('internal_id'),
                Column::make('payload_uuid'),
                Column::make('status'),
                Column::make('type'),
                Column::make('time', 'integer'),
                Column::make('meta', 'json'),
                Column::make('created_at', 'datetime'),
                Column::expression('order_total', "CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.total')) AS DECIMAL(15,2)) / 100.0", 'decimal'),
            ])
            ->computedColumns([
                Column::count('total_orders', 'id'),
                Column::sum('sum_order_total', 'order_total'),
            ])
            ->relationships([
                Relationship::hasAutoJoin('payload', 'payloads')
                    ->softDeletes()
                    ->localKey('payload_uuid')
                    ->foreignKey('uuid')
                    ->columns([Column::make('public_id')])
                    ->with([
                        Relationship::hasAutoJoin('entities', 'entities')
                            ->softDeletes()
                            ->localKey('uuid')
                            ->foreignKey('payload_uuid')
                            ->columns([
                                Column::make('name'),
                                Column::make('price', 'decimal'),
                                Column::make('meta', 'json'),
                                Column::expression('quantity', "CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.quantity')) AS DECIMAL(15,2))", 'decimal'),
                                Column::expression('line_total', 'price * quantity', 'decimal'),
                            ]),
                    ]),
                Relationship::hasAutoJoin('tracking_number', 'tracking_numbers')
                    ->localKey('tracking_number_uuid')
                    ->foreignKey('uuid')
                    ->columns([Column::make('tracking_number')]),
            ])
    );

    return $registry;
}

function order_reporting_database(): void
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

    config(['database.default' => 'testing', 'database.connections.testing' => $connectionConfig]);

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $connection = $capsule->getConnection('testing');
    // SQLite has JSON_EXTRACT but not MySQL's JSON_UNQUOTE; its JSON_EXTRACT already unquotes.
    $connection->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value, 1);

    $schema = $connection->getSchemaBuilder();
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('internal_id')->nullable();
        $table->string('company_uuid');
        $table->string('payload_uuid')->nullable();
        $table->string('tracking_number_uuid')->nullable();
        $table->string('status');
        $table->string('type')->nullable();
        $table->integer('time')->nullable();
        $table->json('meta')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('payloads', function ($table) {
        $table->string('uuid');
        $table->string('public_id');
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('entities', function ($table) {
        $table->string('uuid');
        $table->string('payload_uuid');
        $table->string('name');
        $table->string('price')->nullable();
        $table->json('meta')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('tracking_numbers', function ($table) {
        $table->string('uuid');
        $table->string('tracking_number');
    });

    $connection->table('tracking_numbers')->insert([
        ['uuid' => 'tn-1', 'tracking_number' => 'TRK-0001'],
    ]);
    $connection->table('payloads')->insert([
        ['uuid' => 'payload-1', 'public_id' => 'payload_1', 'deleted_at' => null],
        ['uuid' => 'payload-2', 'public_id' => 'payload_2', 'deleted_at' => null],
        ['uuid' => 'payload-3', 'public_id' => 'payload_3', 'deleted_at' => null],
        ['uuid' => 'payload-4', 'public_id' => 'payload_4', 'deleted_at' => null],
        ['uuid' => 'payload-5', 'public_id' => 'payload_5', 'deleted_at' => null],
    ]);
    $connection->table('entities')->insert([
        // September order 1: 3 burgers + 1 fries
        ['uuid' => 'e-1', 'payload_uuid' => 'payload-1', 'name' => 'Burger', 'price' => '8.50', 'meta' => json_encode(['quantity' => 3]), 'deleted_at' => null],
        ['uuid' => 'e-2', 'payload_uuid' => 'payload-1', 'name' => 'Fries', 'price' => '3.00', 'meta' => json_encode(['quantity' => 1]), 'deleted_at' => null],
        // September order 2: 4 fries, 1 burger, and a removed line that must not count
        ['uuid' => 'e-3', 'payload_uuid' => 'payload-2', 'name' => 'Fries', 'price' => '3.00', 'meta' => json_encode(['quantity' => 4]), 'deleted_at' => null],
        ['uuid' => 'e-4', 'payload_uuid' => 'payload-2', 'name' => 'Burger', 'price' => '8.50', 'meta' => json_encode(['quantity' => 1]), 'deleted_at' => null],
        ['uuid' => 'e-5', 'payload_uuid' => 'payload-2', 'name' => 'Milkshake', 'price' => '5.00', 'meta' => json_encode(['quantity' => 50]), 'deleted_at' => '2026-09-10 00:00:00'],
        // August order
        ['uuid' => 'e-6', 'payload_uuid' => 'payload-3', 'name' => 'Milkshake', 'price' => '5.00', 'meta' => json_encode(['quantity' => 9]), 'deleted_at' => null],
        // Deleted order and another tenant's order
        ['uuid' => 'e-7', 'payload_uuid' => 'payload-4', 'name' => 'Burger', 'price' => '8.50', 'meta' => json_encode(['quantity' => 100]), 'deleted_at' => null],
        ['uuid' => 'e-8', 'payload_uuid' => 'payload-5', 'name' => 'Fries', 'price' => '3.00', 'meta' => json_encode(['quantity' => 100]), 'deleted_at' => null],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_1', 'internal_id' => 'INT-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'tracking_number_uuid' => 'tn-1', 'status' => 'completed', 'type' => 'storefront', 'time' => 30, 'meta' => json_encode(['total' => '2850']), 'created_at' => '2026-09-03 10:00:00', 'deleted_at' => null],
        ['uuid' => 'order-2', 'public_id' => 'order_2', 'internal_id' => 'INT-2', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-2', 'tracking_number_uuid' => null, 'status' => 'created', 'type' => 'storefront', 'time' => 45, 'meta' => json_encode(['total' => '2050']), 'created_at' => '2026-09-20 18:30:00', 'deleted_at' => null],
        ['uuid' => 'order-3', 'public_id' => 'order_3', 'internal_id' => 'INT-3', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-3', 'tracking_number_uuid' => null, 'status' => 'completed', 'type' => 'storefront', 'time' => 20, 'meta' => json_encode(['total' => '4500']), 'created_at' => '2026-08-15 09:00:00', 'deleted_at' => null],
        ['uuid' => 'order-4', 'public_id' => 'order_4', 'internal_id' => 'INT-4', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-4', 'tracking_number_uuid' => null, 'status' => 'canceled', 'type' => 'storefront', 'time' => 10, 'meta' => json_encode(['total' => '85000']), 'created_at' => '2026-09-05 12:00:00', 'deleted_at' => '2026-09-06 00:00:00'],
        ['uuid' => 'order-5', 'public_id' => 'order_5', 'internal_id' => 'INT-5', 'company_uuid' => 'company-2', 'payload_uuid' => 'payload-5', 'tracking_number_uuid' => null, 'status' => 'completed', 'type' => 'storefront', 'time' => 10, 'meta' => json_encode(['total' => '30000']), 'created_at' => '2026-09-07 12:00:00', 'deleted_at' => null],
    ]);
}

function order_reporting_run(array $config): array
{
    order_reporting_database();

    return (new ReportQueryConverter(order_reporting_registry(), ['table' => ['name' => 'orders']] + $config))->execute();
}

function order_reporting_call(object $target, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($target, $method);

    return $reflection->invoke($target, ...$arguments);
}

$septemberCondition = [
    'field'    => ['name' => 'created_at'],
    'operator' => ['value' => 'between'],
    'value'    => ['2026-09-01 00:00:00', '2026-09-30 23:59:59'], // date-drift-ok: filters fixture rows by their own created_at, never compared to now()
];

test('order reporting ranks the products sold this month through payload entities', function () use ($septemberCondition) {
    $result = order_reporting_run([
        'columns' => [
            ['name' => 'payload.entities.name', 'label' => 'Item Name'],
            ['name' => 'payload.entities.quantity', 'label' => 'Item Quantity'],
        ],
        'conditions' => [$septemberCondition],
        'groupBy'    => [[
            'groupBy'     => ['name' => 'payload.entities.name', 'label' => 'Item Name'],
            'aggregateFn' => ['value' => 'sum'],
            'aggregateBy' => ['name' => 'payload.entities.quantity', 'label' => 'Item Quantity', 'computed' => true, 'computation' => 'client supplied SQL is ignored'],
        ]],
        'sortBy' => [[
            'column'    => ['name' => 'sum_payload_entities_quantity'],
            'direction' => ['value' => 'desc'],
        ]],
    ]);

    expect($result['success'])->toBeTrue()
        // Fries 1 + 4, Burger 3 + 1; the removed milkshake line, the deleted order, the August
        // order and the other tenant's order are all left out.
        ->and(array_map(fn ($row) => [(array) $row][0], $result['data']))->toEqual([
            ['payload_entities_name' => 'Fries', 'sum_payload_entities_quantity' => 5],
            ['payload_entities_name' => 'Burger', 'sum_payload_entities_quantity' => 4],
        ])
        ->and($result['meta']['query_sql'])->toContain('orders_payload_entities.meta')
        ->and($result['meta']['query_sql'])->not->toContain('client supplied')
        ->and(collect($result['columns'])->firstWhere('name', 'sum_payload_entities_quantity')['label'])->toBe('Sum (Item Quantity)');
});

test('order reporting totals this month in a single summary row', function () use ($septemberCondition) {
    $result = order_reporting_run([
        'columns' => [
            ['name' => 'total_orders', 'computed' => true],
            ['name' => 'sum_order_total', 'computed' => true],
        ],
        'conditions' => [$septemberCondition],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveCount(1)
        ->and((array) $result['data'][0])->toEqual(['total_orders' => 2, 'sum_order_total' => 49]);
});

test('order reporting lists orders with identifiers tracking numbers and json totals', function () {
    $result = order_reporting_run([
        'columns' => [
            ['name' => 'public_id'],
            ['name' => 'internal_id'],
            ['name' => 'tracking_number.tracking_number'],
            ['name' => 'order_total'],
        ],
        'computed_columns' => [[
            'name'       => 'total_with_tax',
            'expression' => "ROUND(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.total')) AS DECIMAL(15,2)) / 100.0 * 1.1, 2)",
        ]],
        'conditions' => [[
            'field'    => ['name' => 'order_total'],
            'operator' => ['value' => 'gt'],
            'value'    => 25,
        ]],
        'sortBy' => [[
            'column'    => ['name' => 'total_with_tax'],
            'direction' => ['value' => 'DESC'],
        ]],
    ]);

    expect($result['success'])->toBeTrue()
        ->and(array_map(fn ($row) => (array) $row, $result['data']))->toEqual([
            ['public_id' => 'order_3', 'internal_id' => 'INT-3', 'tracking_number_tracking_number' => null, 'order_total' => 45, 'total_with_tax' => 49.5],
            ['public_id' => 'order_1', 'internal_id' => 'INT-1', 'tracking_number_tracking_number' => 'TRK-0001', 'order_total' => 28.5, 'total_with_tax' => 31.35],
        ]);
});

test('order reporting groups by a computed month bucket and counts distinct orders across line items', function () {
    $result = order_reporting_run([
        'columns'          => [['name' => 'payload.entities.name']],
        'computed_columns' => [
            ['name' => 'order_month', 'expression' => 'SUBSTR(created_at, 1, 7)'],
        ],
        'groupBy' => [
            [
                'groupBy'     => ['name' => 'order_month', 'computed' => true],
                'aggregateFn' => ['value' => 'count_distinct'],
                'aggregateBy' => ['name' => 'public_id'],
            ],
            [
                'groupBy'     => ['name' => 'order_month', 'computed' => true],
                'aggregateFn' => ['value' => 'count'],
                'aggregateBy' => ['name' => 'payload.entities.name'],
            ],
            [
                'groupBy'     => ['name' => 'order_month', 'computed' => true],
                'aggregateFn' => ['value' => 'sum'],
                'aggregateBy' => ['name' => 'payload.entities.line_total'],
            ],
        ],
        'sortBy' => [[
            'column'    => ['name' => 'order_month'],
            'direction' => ['value' => 'asc'],
        ]],
    ]);

    expect($result['success'])->toBeTrue()
        ->and(array_map(fn ($row) => (array) $row, $result['data']))->toEqual([
            ['order_month' => '2026-08', 'count_distinct_public_id' => 1, 'count_payload_entities_name' => 1, 'sum_payload_entities_line_total' => 45],
            ['order_month' => '2026-09', 'count_distinct_public_id' => 2, 'count_payload_entities_name' => 4, 'sum_payload_entities_line_total' => 49],
        ])
        ->and($result['meta']['query_sql'])->toContain('COUNT(DISTINCT orders.public_id)')
        ->and(collect($result['columns'])->firstWhere('name', 'count_distinct_public_id')['label'])->toBe('Distinct Count (public_id)');
});

test('order reporting keeps summary columns beside group keys and sorts by them', function () {
    $result = order_reporting_run([
        'columns' => [
            ['name' => 'status'],
            ['name' => 'total_orders'],
        ],
        'groupBy' => [[
            'groupBy'     => ['name' => 'status'],
            'aggregateFn' => ['value' => 'sum'],
            'aggregateBy' => ['name' => 'order_total'],
        ]],
        'sortBy' => [
            ['column' => ['name' => 'total_orders'], 'direction' => ['value' => 'desc']],
            ['column' => ['name' => 'not_selected'], 'direction' => ['value' => 'asc']],
        ],
    ]);

    expect($result['success'])->toBeTrue()
        ->and(array_map(fn ($row) => (array) $row, $result['data']))->toEqual([
            ['status' => 'completed', 'total_orders' => 2, 'sum_order_total' => 73.5],
            ['status' => 'created', 'total_orders' => 1, 'sum_order_total' => 20.5],
        ])
        ->and($result['meta']['query_sql'])->not->toContain('not_selected');
});

test('order reporting keeps an order whose line items were all removed on a left join', function () {
    order_reporting_database();
    Capsule::table('entities')->where('payload_uuid', 'payload-3')->update(['deleted_at' => '2026-09-01 00:00:00']);

    $result = (new ReportQueryConverter(order_reporting_registry(), [
        'table'   => ['name' => 'orders'],
        'columns' => [['name' => 'public_id'], ['name' => 'payload.entities.name']],
        'sortBy'  => [['column' => ['name' => 'public_id'], 'direction' => ['value' => 'asc']]],
    ]))->execute();

    expect($result['success'])->toBeTrue()
        ->and(collect($result['data'])->where('public_id', 'order_3')->pluck('payload_entities_name')->all())->toBe([null]);
});

test('order reporting refuses report shapes that cannot produce valid sql', function (array $config, string $message) {
    $result = order_reporting_run($config);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain($message);
})->with([
    'summary beside per-row columns' => [
        ['columns' => [['name' => 'public_id'], ['name' => 'total_orders']]],
        'Summary columns (total_orders) can only be combined',
    ],
    'aggregating a summary column' => [
        ['columns' => [['name' => 'status']], 'groupBy' => [['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'sum'], 'aggregateBy' => ['name' => 'total_orders']]]],
        "Column 'total_orders' is already a summary value",
    ],
    'grouping by a summary column' => [
        ['columns' => [['name' => 'total_orders']], 'groupBy' => [['groupBy' => ['name' => 'total_orders'], 'aggregateFn' => ['value' => 'count'], 'aggregateBy' => ['name' => '*']]]],
        'cannot be grouped by',
    ],
    'filtering on a summary column' => [
        ['columns' => [['name' => 'total_orders']], 'conditions' => [['field' => ['name' => 'total_orders'], 'operator' => ['value' => 'gt'], 'value' => 1]]],
        'cannot be used as a filter',
    ],
    'an ungrouped column' => [
        ['columns' => [['name' => 'status'], ['name' => 'type']], 'groupBy' => [['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'count'], 'aggregateBy' => ['name' => '*']]]],
        "Column 'type' must be grouped or aggregated",
    ],
    'an unsupported aggregate' => [
        ['columns' => [['name' => 'status']], 'groupBy' => [['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'median'], 'aggregateBy' => ['name' => 'time']]]],
        "Aggregate function 'median' is not supported",
    ],
    'an unknown group key' => [
        ['columns' => [['name' => 'status']], 'groupBy' => [['groupBy' => ['name' => 'status`) --'], 'aggregateFn' => ['value' => 'count'], 'aggregateBy' => ['name' => '*']]]],
        'Group by column',
    ],
    'an unknown aggregate column' => [
        ['columns' => [['name' => 'status']], 'groupBy' => [['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'sum'], 'aggregateBy' => ['name' => 'password']]]],
        "Aggregate column 'password'",
    ],
    'an unknown sort column' => [
        ['columns' => [['name' => 'status']], 'sortBy' => [['column' => ['name' => 'secret'], 'direction' => ['value' => 'asc']]]],
        "Sort column 'secret'",
    ],
    'an unsafe grouped sort alias' => [
        ['columns' => [['name' => 'status']], 'groupBy' => [['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'count'], 'aggregateBy' => ['name' => '*']]], 'sortBy' => [['column' => ['name' => 'x` desc; --'], 'direction' => ['value' => 'asc']]]],
        'Invalid sort column',
    ],
    'an unknown nested condition column' => [
        ['columns' => [['name' => 'status']], 'conditions' => [['conditions' => [['field' => ['name' => 'secret'], 'operator' => ['value' => 'eq'], 'value' => 1]]]]],
        "Condition column 'secret'",
    ],
    'an unsafe computed column name' => [
        ['columns' => [['name' => 'status']], 'computed_columns' => [['name' => 'x` from users --', 'expression' => 'time * 2']]],
        'Invalid computed column name',
    ],
    'a computed column subquery' => [
        ['columns' => [['name' => 'status']], 'computed_columns' => [['name' => 'leak', 'expression' => '(SELECT 1)']]],
        'forbidden SQL keyword: SELECT',
    ],
    'a computed column hidden in a grouped aggregate' => [
        ['columns' => [['name' => 'status']], 'groupBy' => [['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'sum'], 'aggregateBy' => ['name' => 'evil', 'computed' => true, 'computation' => 'SLEEP(5)']]]],
        'forbidden SQL keyword: SLEEP',
    ],
    'circular computed columns' => [
        ['columns' => [['name' => 'status']], 'computed_columns' => [['name' => 'loop_a', 'expression' => 'loop_b + 1'], ['name' => 'loop_b', 'expression' => 'loop_a + 1']]],
        'circular or nested too deeply',
    ],
]);

test('order reporting resolves keywords json arrows and relationship prefixes in expressions', function () {
    order_reporting_database();

    $converter = new ReportQueryConverter(order_reporting_registry(), [
        'table'            => ['name' => 'orders'],
        'columns'          => [['name' => 'public_id'], ['name' => 'payload.entities.name']],
        'computed_columns' => [['name' => 'doubled', 'expression' => 'time * 2']],
    ]);
    // Building the query records the join aliases that relationship columns resolve against.
    order_reporting_call($converter, 'buildQuery');

    $resolve = fn (string $expression) => order_reporting_call($converter, 'resolveComputedColumnReferences', $expression, 'orders');

    expect($resolve("CAST(JSON_EXTRACT(meta, '$.total') AS SIGNED)"))->toBe("CAST(JSON_EXTRACT(orders.meta, '$.total') AS SIGNED)")
        ->and($resolve('CAST(time AS CHAR)'))->toBe('CAST(orders.time AS CHAR)')
        ->and($resolve('DATE_ADD(created_at, INTERVAL 7 DAY)'))->toBe('DATE_ADD(orders.created_at, INTERVAL 7 DAY)')
        ->and($resolve('DATE_ADD(created_at, INTERVAL time MINUTE)'))->toBe('DATE_ADD(orders.created_at, INTERVAL orders.time MINUTE)')
        ->and($resolve("meta->>'$.total' + doubled"))->toBe("orders.meta->>'$.total' + (orders.time * 2)")
        ->and($resolve("COUNT(DISTINCT public_id) + LENGTH('status')"))->toBe("COUNT(DISTINCT orders.public_id) + LENGTH('status')")
        ->and($resolve("GROUP_CONCAT(status ORDER BY created_at DESC SEPARATOR ', ')"))->toBe("GROUP_CONCAT(orders.status ORDER BY orders.created_at DESC SEPARATOR ', ')")
        ->and($resolve('time + DAY + year'))->toBe('orders.time + DAY + year')
        ->and($resolve('payload.entities.line_total'))->toBe('(orders_payload_entities.price * (CAST(JSON_UNQUOTE(JSON_EXTRACT(orders_payload_entities.meta, \'$.quantity\')) AS DECIMAL(15,2))))');

    $paths = order_reporting_call($converter, 'extractRelationshipPathsFromExpression', 'payload.entities.line_total + tracking_number.tracking_number', 'orders');
    expect($paths)->toEqualCanonicalizing(['payload.entities', 'tracking_number']);

    $deepPaths = [];
    (new ReflectionMethod($converter, 'collectJoinPathsForReference'))->invokeArgs($converter, ['doubled', &$deepPaths, 99]);
    expect($deepPaths)->toBe([]);
});

test('report schema columns separate identifiers from foreign keys and expressions from aggregates', function () {
    $table = order_reporting_registry()->getTable('orders');

    $visible = array_map(fn ($column) => $column->getName(), array_values($table->getVisibleColumns()));
    expect($visible)->toContain('public_id', 'internal_id', 'order_total', 'total_orders')
        ->not->toContain('payload_uuid', 'uuid')
        ->and(Column::make('vendor_id')->isForeignKey())->toBeTrue()
        ->and(Column::make('public_id')->isForeignKey())->toBeFalse();

    $expression = Column::expression('order_total', "JSON_EXTRACT(meta, '$.total')", 'decimal');
    expect($expression->isComputed())->toBeTrue()
        ->and($expression->isExpression())->toBeTrue()
        ->and($expression->isAggregate())->toBeFalse()
        ->and($expression->isAggregatable())->toBeTrue()
        ->and($expression->toArray())->toMatchArray(['computed' => true, 'aggregate' => false, 'computation' => "JSON_EXTRACT(meta, '$.total')"])
        ->and(Column::expression('label', "CONCAT(name, '!')")->isAggregatable())->toBeFalse();

    $count = Column::count('total_orders', 'id');
    expect($count->isAggregate())->toBeTrue()
        ->and($count->isExpression())->toBeFalse()
        ->and($count->toArray()['aggregate'])->toBeTrue()
        ->and(Column::computed('share', ' (SUM(total) / 2)')->isAggregate())->toBeTrue()
        ->and(Column::computed('flag', 'IF(total > 2, 1, 0)')->isAggregate())->toBeFalse()
        ->and(Column::computed('forced', 'total', 'decimal', ['aggregate' => true])->isAggregate())->toBeTrue()
        ->and(Column::make('plain')->isAggregate())->toBeFalse()
        ->and(Column::isAggregateExpression('group_concat(name)'))->toBeTrue();

    $payload = $table->getRelationship('payload');
    expect($payload->usesSoftDeletes())->toBeTrue()
        ->and($payload->toArray()['soft_deletes'])->toBeTrue()
        ->and($payload->getColumn('public_id'))->toBeInstanceOf(Column::class)
        ->and($payload->getColumn('missing'))->toBeNull()
        ->and($table->usesSoftDeletes())->toBeTrue()
        ->and($table->toArray()['soft_deletes'])->toBeTrue()
        ->and(Table::make('plain')->usesSoftDeletes())->toBeFalse()
        ->and(Relationship::make('plain', 'plain')->usesSoftDeletes())->toBeFalse();
});

test('report schema registry exposes nested relationship and expression columns for the report builder', function () {
    $registry = order_reporting_registry();
    $columns  = collect($registry->getTableColumns('orders'))->keyBy('name');

    expect($columns->keys()->all())->toContain('public_id', 'payload.public_id', 'payload.entities.name', 'payload.entities.quantity', 'tracking_number.tracking_number')
        ->and($columns['payload.entities.quantity']['auto_join_path'])->toBe('payload.entities')
        ->and($columns['payload.entities.quantity']['computed'])->toBeTrue()
        ->and($columns['total_orders']['aggregate'])->toBeTrue()
        ->and($registry->isColumnAllowed('orders', 'payload.entities.line_total'))->toBeTrue()
        ->and($registry->isColumnAllowed('orders', 'payload.entities.uuid'))->toBeFalse();
});

test('order reporting emits a repeated aggregate once and reports an unregistered table', function () {
    $result = order_reporting_run([
        'columns' => [['name' => 'status']],
        'groupBy' => [
            ['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'count'], 'aggregateBy' => ['name' => '*']],
            ['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'count'], 'aggregateBy' => ['name' => '*']],
        ],
    ]);

    expect($result['success'])->toBeTrue()
        ->and(substr_count($result['meta']['query_sql'], 'COUNT(*)'))->toBe(1);

    $missing = (new ReportQueryConverter(order_reporting_registry(), [
        'table'   => ['name' => 'invoices'],
        'columns' => [['name' => 'status']],
        'groupBy' => [['groupBy' => ['name' => 'status'], 'aggregateFn' => ['value' => 'sum'], 'aggregateBy' => ['name' => 'amount', 'computed' => true, 'computation' => 'total']]],
    ]))->execute();

    expect($missing['success'])->toBeFalse()
        ->and($missing['error'])->toBe("Table 'invoices' is not registered");
});
