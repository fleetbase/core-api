<?php

use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;

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
        ->and($registry->isColumnAllowed('missing', 'public_id'))->toBeFalse();
});

test('report schema registry returns schema, relationships, auto join paths, and cache controls', function () {
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
        ->and($registry->getRegisteredTableNames())->toContain('orders', 'customers');

    $registry->setCacheEnabled(true);
    $registry->setCacheTtl(30);
    $registry->clearTableCache('orders');
    $registry->clearAllCache();

    expect($registry->hasTable('orders'))->toBeTrue();
});
