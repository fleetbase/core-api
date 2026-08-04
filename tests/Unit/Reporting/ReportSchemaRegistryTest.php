<?php

use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

function reporting_registry_fixture(): ReportSchemaRegistry
{
    $registry = new ReportSchemaRegistry();
    $registry->setCacheEnabled(false);

    $pickup = Relationship::hasAutoJoin('pickup', 'locations')
        ->label('Pickup Location')
        ->localKey('pickup_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('city')->label('City'),
            Column::make('street1')->label('Street 1'),
        ]);

    $payload = Relationship::hasAutoJoin('payload', 'payloads')
        ->label('Order Payload')
        ->localKey('payload_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('description')->label('Description'),
        ])
        ->with([$pickup]);

    $customer = Relationship::belongsTo('customer', 'customers')
        ->label('Customer')
        ->localKey('customer_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('name')->label('Name'),
        ]);

    $registry->registerTable(
        Table::make('orders')
            ->label('Orders')
            ->description('Order report table')
            ->extension('fleetops')
            ->category('operations')
            ->maxRows(2500)
            ->columns([
                Column::make('uuid')->label('UUID')->hidden(),
                Column::make('public_id')->label('Public ID')->sortable(),
                Column::make('tracking_number')->label('Tracking Number')->sortable(),
                Column::make('status')->label('Status')->filterable(),
                Column::make('total', 'decimal')->label('Total')->aggregatable(),
                Column::computed('age_days', 'DATEDIFF(NOW(), created_at)', 'integer'),
            ])
            ->relationships([$payload, $customer])
            ->excludeColumns(['uuid'])
    );

    $registry->registerTable(
        Table::make('customers')
            ->label('Customers')
            ->extension('fleetops')
            ->category('crm')
            ->columns([
                Column::make('uuid'),
                Column::make('name'),
            ])
    );

    return $registry;
}

test('report schema registry exposes filtered table metadata and flattened auto join columns', function () {
    $registry = reporting_registry_fixture();

    $tables = $registry->getAvailableTables('fleetops', 'operations');

    expect($tables)->toHaveCount(1)
        ->and($tables[0]['name'])->toBe('orders')
        ->and($tables[0]['has_auto_joins'])->toBeTrue()
        ->and($tables[0]['has_manual_joins'])->toBeTrue()
        ->and($tables[0]['max_rows'])->toBe(2500);

    $columnNames = array_column($tables[0]['columns'], 'name');

    expect($columnNames)->toContain('tracking_number')
        ->and($columnNames)->toContain('status')
        ->and($columnNames)->toContain('payload.description')
        ->and($columnNames)->toContain('payload.pickup.city')
        ->and($columnNames)->not->toContain('uuid');
});

test('report schema registry validates direct and nested auto join column paths', function () {
    $registry = reporting_registry_fixture();

    expect($registry->isColumnAllowed('orders', 'tracking_number'))->toBeTrue()
        ->and($registry->isColumnAllowed('orders', 'uuid'))->toBeFalse()
        ->and($registry->isColumnAllowed('orders', 'payload.description'))->toBeTrue()
        ->and($registry->isColumnAllowed('orders', 'payload.pickup.city'))->toBeTrue()
        ->and($registry->isColumnAllowed('orders', 'payload.dropoff.city'))->toBeFalse()
        ->and($registry->isColumnAllowed('orders', 'customer.name'))->toBeFalse()
        ->and($registry->isColumnAllowed('orders', 'payload.pickup.missing'))->toBeFalse()
        ->and($registry->isColumnAllowed('orders', 'payload.pickup.city.extra'))->toBeFalse()
        ->and($registry->isColumnAllowed('missing', 'payload.description'))->toBeFalse()
        ->and($registry->isColumnAllowed('missing', 'public_id'))->toBeFalse();
});

test('report schema registry returns schema, relationships, auto join paths, and cache controls', function () {
    bind_test_container();
    Facade::clearResolvedInstance('cache');

    $registry = reporting_registry_fixture();

    $schema = $registry->getTableSchema('orders');
    $path   = $registry->resolveAutoJoinPath('orders', 'payload.description');

    expect($schema['table']['name'])->toBe('orders')
        ->and(array_column($schema['relationships'], 'name'))->toContain('payload')
        ->and(array_column($schema['auto_join_columns'], 'name'))->toContain('payload.description')
        ->and($path)->toHaveCount(1)
        ->and($path[0]['relationship'])->toBe('payload')
        ->and($registry->getTableSchema('unknown'))->toBe([])
        ->and($registry->resolveAutoJoinPath('orders', 'public_id'))->toBeNull()
        ->and($registry->resolveAutoJoinPath('missing', 'payload.description'))->toBeNull()
        ->and($registry->resolveAutoJoinPath('orders', 'customer.name'))->toBeNull()
        ->and($registry->getTableColumns('missing'))->toBe([])
        ->and($registry->getTableRelationships('missing'))->toBe([])
        ->and($registry->getAutoJoinColumns('missing'))->toBe([])
        ->and($registry->getRegisteredTableNames())->toContain('orders', 'customers');

    $registry->setCacheEnabled(true);
    $registry->setCacheTtl(30);
    $registry->clearTableCache('orders');
    $registry->clearTableCache('unknown');
    $registry->clearAllCache();

    expect($registry->hasTable('orders'))->toBeTrue();
});

test('report schema registry ignores invalid batch registrations and supports defensive relationship branches', function () {
    $registry = reporting_registry_fixture();
    $registry->registerTables([
        'not-a-table',
        Table::make('categories')
            ->label('Categories')
            ->extension('core')
            ->columns([
                Column::make('name')->label('Name'),
            ]),
    ]);

    $childResolver = new ReflectionMethod($registry, 'getChildRelationship');
    $childResolver->setAccessible(true);

    expect($registry->hasTable('categories'))->toBeTrue()
        ->and($registry->hasTable('not-a-table'))->toBeFalse()
        ->and($registry->getAvailableTables('fleetops', 'missing'))->toBe([])
        ->and($registry->isColumnAllowed('categories', 'name'))->toBeTrue()
        ->and($childResolver->invoke($registry, new stdClass(), 'missing'))->toBeNull();
});

test('report schema registry accepts relationship available column fallback matches', function () {
    $registry     = new ReportSchemaRegistry();
    $relationship = new class('shadow', 'shadow_records') extends Relationship {
        public function __construct(string $name, string $table)
        {
            parent::__construct($name, $table);

            $enabler = new ReflectionMethod(Relationship::class, 'setAutoJoin');
            $enabler->setAccessible(true);
            $enabler->invoke($this, true);
        }

        public function getColumns(): array
        {
            return [];
        }

        public function getAllAvailableColumns(): array
        {
            return [Column::make('shadow_code')->label('Shadow Code')];
        }
    };

    $registry->registerTable(
        Table::make('orders')
            ->columns([
                Column::make('public_id'),
            ])
            ->relationships([$relationship])
    );

    expect($registry->isColumnAllowed('orders', 'shadow.shadow_code'))->toBeTrue();
});

test('report schema registry preserves label fallback and no category cache clearing contracts', function () {
    bind_test_container();
    Facade::clearResolvedInstance('cache');

    $registry = new ReportSchemaRegistry();
    $registry->setCacheEnabled(true);

    $blankLabel = Relationship::hasAutoJoin('metadata', 'metadata')
        ->label('')
        ->localKey('metadata_uuid')
        ->foreignKey('uuid')
        ->columns([
            Column::make('code')->label('Code'),
        ]);

    $registry->registerTable(
        Table::make('assets')
            ->label('Assets')
            ->extension('core')
            ->columns([
                Column::make('name')->label('Name'),
            ])
            ->relationships([$blankLabel])
    );

    $columns = $registry->getTableColumns('assets');

    Cache::put('report_tables_core_all', [['name' => 'assets']]);
    Cache::put('report_tables_core_uncategorized', [['name' => 'uncategorized']]);
    $registry->clearTableCache('assets');

    expect(array_column($columns, 'name'))->toContain('metadata.code')
        ->and(collect($columns)->firstWhere('name', 'metadata.code')['label'])->toBe('Code')
        ->and(Cache::get('report_tables_core_all'))->toBeNull()
        ->and(Cache::get('report_tables_core_uncategorized'))->toBe([['name' => 'uncategorized']]);
});

test('report schema registry caches table column and relationship metadata and clears scoped keys', function () {
    bind_test_container();
    Facade::clearResolvedInstance('cache');

    $registry = reporting_registry_fixture();
    $registry->setCacheEnabled(true);
    $registry->setCacheTtl(15);

    Cache::put('report_tables_fleetops_operations', [['name' => 'cached-operations']]);
    expect($registry->getAvailableTables('fleetops', 'operations'))->toBe([['name' => 'cached-operations']]);

    Cache::forget('report_tables_fleetops_operations');
    $tables = $registry->getAvailableTables('fleetops', 'operations');

    expect($tables[0]['name'])->toBe('orders')
        ->and(Cache::get('report_tables_fleetops_operations'))->toBe($tables);

    Cache::put('report_columns_orders', [['name' => 'cached-column']]);
    expect($registry->getTableColumns('orders'))->toBe([['name' => 'cached-column']]);

    Cache::forget('report_columns_orders');
    $columns = $registry->getTableColumns('orders');

    expect(array_column($columns, 'name'))->toContain('tracking_number')
        ->and(Cache::get('report_columns_orders'))->toBe($columns);

    Cache::put('report_relationships_orders', [['name' => 'cached-relationship']]);
    expect($registry->getTableRelationships('orders'))->toBe([['name' => 'cached-relationship']]);

    Cache::forget('report_relationships_orders');
    $relationships = $registry->getTableRelationships('orders');

    expect(array_column($relationships, 'name'))->toContain('payload')
        ->and(Cache::get('report_relationships_orders'))->toBe($relationships);

    Cache::put('report_tables_fleetops_all', [['name' => 'all']]);
    $registry->clearTableCache('orders');

    expect(Cache::get('report_columns_orders'))->toBeNull()
        ->and(Cache::get('report_relationships_orders'))->toBeNull()
        ->and(Cache::get('report_tables_fleetops_all'))->toBeNull()
        ->and(Cache::get('report_tables_fleetops_operations'))->toBeNull();

    $registry->setCacheEnabled(false);
    Cache::put('report_columns_orders', [['name' => 'preserved']]);
    $registry->clearTableCache('orders');
    $registry->clearAllCache();

    expect(Cache::get('report_columns_orders'))->toBe([['name' => 'preserved']]);
});
