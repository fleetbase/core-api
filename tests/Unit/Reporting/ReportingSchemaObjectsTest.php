<?php

use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;

it('builds reporting columns with computed aggregate transformer and copy contracts', function () {
    $column = Column::make('delivery_total', 'decimal')
        ->label('Delivery Total')
        ->description('Total delivery amount')
        ->format('currency')
        ->nullable(false)
        ->searchable(false)
        ->sortable()
        ->filterable()
        ->hidden()
        ->transformer(fn ($value) => '$' . number_format($value, 2))
        ->meta('currency', 'USD')
        ->setMeta(['precision' => 2]);

    $copy = $column->copyWith([
        'name'        => 'delivery_total_usd',
        'label'       => 'Delivery Total USD',
        'type'        => 'string',
        'description' => null,
    ]);

    expect($column->getName())->toBe('delivery_total')
        ->and($column->getLabel())->toBe('Delivery Total')
        ->and($column->getType())->toBe('decimal')
        ->and($column->getDescription())->toBe('Total delivery amount')
        ->and($column->getFormat())->toBe('currency')
        ->and($column->isNullable())->toBeFalse()
        ->and($column->isSearchable())->toBeFalse()
        ->and($column->isSortable())->toBeTrue()
        ->and($column->isFilterable())->toBeTrue()
        ->and($column->isAggregatable())->toBeTrue()
        ->and($column->isHidden())->toBeTrue()
        ->and($column->isForeignKey())->toBeFalse()
        ->and(Column::make('company_uuid')->isForeignKey())->toBeTrue()
        ->and($column->hasTransformer())->toBeTrue()
        ->and($column->transformValue(12.5))->toBe('$12.50')
        ->and($column->getMeta())->toBe(['currency' => 'USD', 'precision' => 2])
        ->and($column->getMeta('precision'))->toBe(2)
        ->and($column->toArray()['transformer'])->toBeTrue()
        ->and(json_decode($column->toJson(), true)['name'])->toBe('delivery_total')
        ->and($copy->getName())->toBe('delivery_total_usd')
        ->and($copy->getLabel())->toBe('Delivery Total USD')
        ->and($copy->getType())->toBe('string')
        ->and($copy->isAggregatable())->toBeFalse()
        ->and($copy->getDescription())->toBeNull();

    expect(Column::count('orders_count')->getComputation())->toBe('COUNT(*)')
        ->and(Column::sum('total_sum', 'total')->getComputation())->toBe('SUM(total)')
        ->and(Column::avg('total_avg', 'total')->getComputation())->toBe('AVG(total)')
        ->and(Column::max('latest_date', 'created_at')->getComputation())->toBe('MAX(created_at)')
        ->and(Column::min('first_date', 'created_at')->getComputation())->toBe('MIN(created_at)')
        ->and(Column::computed('computed_default', 'LOWER(status)')->isComputed())->toBeTrue()
        ->and(Column::computed('computed_default', 'LOWER(status)')->isAggregatable())->toBeFalse()
        ->and(Column::computed('computed_default', 'LOWER(status)')->isSortable())->toBeFalse()
        ->and(Column::computed('computed_default', 'LOWER(status)')->isSearchable())->toBeFalse()
        ->and(Column::computed('safe_total', 'COALESCE(total, 0)', 'decimal', [
            'aggregatable' => true,
            'sortable'     => true,
            'searchable'   => false,
        ])->toArray())->toMatchArray([
            'computed'     => true,
            'computation'  => 'COALESCE(total, 0)',
            'aggregatable' => true,
            'sortable'     => true,
            'searchable'   => false,
        ]);
});

it('builds reporting columns from callable transformers and leaves untransformed values unchanged', function () {
    $callableTransformer = [new class {
        public function format(mixed $value): string
        {
            return 'formatted:' . $value;
        }
    }, 'format'];

    $column = Column::make('status')->transformer($callableTransformer);

    expect($column->getTransformer())->toBeInstanceOf(Closure::class)
        ->and($column->transformValue('pending'))->toBe('formatted:pending')
        ->and(Column::make('raw_status')->transformValue('pending'))->toBe('pending');
});

it('rejects unsupported reporting column copy overrides', function () {
    Column::make('status')->copyWith(['hidden' => true]);
})->throws(InvalidArgumentException::class, 'Cannot set property: hidden');

it('builds reporting relationships with nested auto join metadata and prefixed columns', function () {
    $city    = Column::make('city')->label('City');
    $address = Relationship::hasAutoJoin('address', 'addresses')
        ->columns([$city])
        ->meta('scope', 'tenant');

    $customer = Relationship::belongsTo('customer', 'customers')
        ->label('Customer')
        ->description('Order customer')
        ->localKey('customer_uuid')
        ->foreignKey('uuid')
        ->joinType('inner')
        ->enabled()
        ->columns([
            'name',
            ['name' => 'email', 'type' => 'string', 'label' => 'Email', 'description' => 'Customer email'],
        ])
        ->with([$address]);

    $columns               = $customer->getAllAvailableColumns();
    $prefixedAddressColumn = $columns[2];

    expect($customer->getName())->toBe('customer')
        ->and($customer->getTable())->toBe('customers')
        ->and($customer->getLabel())->toBe('Customer')
        ->and($customer->getType())->toBe('inner')
        ->and($customer->getLocalKey())->toBe('customer_uuid')
        ->and($customer->getForeignKey())->toBe('uuid')
        ->and($customer->getDescription())->toBe('Order customer')
        ->and($customer->isEnabled())->toBeTrue()
        ->and($customer->isAutoJoin())->toBeFalse()
        ->and($customer->hasNestedRelationships())->toBeTrue()
        ->and($customer->getNestedRelationship('address'))->toBe($address)
        ->and($customer->getNestedRelationship('missing'))->toBeNull()
        ->and($customer->getAutoJoinRelationships())->toHaveCount(1)
        ->and($customer->getManualJoinRelationships())->toHaveCount(0)
        ->and($prefixedAddressColumn->getName())->toBe('customer.city')
        ->and($prefixedAddressColumn->getLabel())->toBe('Customer - City')
        ->and($address->getMeta('scope'))->toBe('tenant')
        ->and($customer->toArray())->toMatchArray([
            'name'        => 'customer',
            'table'       => 'customers',
            'type'        => 'inner',
            'local_key'   => 'customer_uuid',
            'foreign_key' => 'uuid',
            'enabled'     => true,
            'auto_join'   => false,
        ]);
});

it('builds reporting relationships through alternate factories and incremental mutators', function () {
    $hasMany = Relationship::hasMany('events', 'events')
        ->autoJoin(false)
        ->columns([
            ['name' => 'event_name', 'type' => 'string', 'label' => 'Event Name', 'description' => 'Lifecycle event'],
            'occurred_at',
        ])
        ->addColumn(Column::make('status'))
        ->meta('audited', true);

    $hasOne = Relationship::hasOne('latestEvent', 'events')
        ->addNestedRelationship($hasMany);

    expect($hasMany->getType())->toBe('left')
        ->and($hasMany->isAutoJoin())->toBeFalse()
        ->and(array_map(fn (Column $column) => $column->getName(), $hasMany->getColumns()))->toBe(['event_name', 'occurred_at', 'status'])
        ->and($hasMany->getColumns()[0]->getLabel())->toBe('Event Name')
        ->and($hasMany->getColumns()[0]->getDescription())->toBe('Lifecycle event')
        ->and($hasMany->getMeta())->toBe(['audited' => true])
        ->and($hasOne->getType())->toBe('left')
        ->and($hasOne->getNestedRelationships())->toBe([$hasMany]);
});

it('builds reporting tables with visible columns joins lookup helpers and serialization', function () {
    $status      = Column::make('status')->label('Status');
    $companyUuid = Column::make('company_uuid')->label('Company UUID');
    $hiddenToken = Column::make('internal_token')->hidden();
    $computed    = Column::computed('orders_count', 'COUNT(*)', 'integer', ['aggregatable' => true]);

    $customer = Relationship::hasAutoJoin('customer', 'customers')
        ->columns([Column::make('name')->label('Customer Name')]);
    $payload = Relationship::belongsTo('payload', 'payloads');

    $table = Table::make('orders')
        ->label('Orders')
        ->description('Order reporting table')
        ->category('operations')
        ->extension('fleetops')
        ->columns([$status, $companyUuid, $hiddenToken])
        ->computedColumns([$computed])
        ->relationships([$customer, $payload])
        ->excludeColumns(['internal_token'])
        ->supportsAggregates(false)
        ->maxRows(500)
        ->cacheable(false)
        ->cacheTtl(120)
        ->permissions(['reports.view'])
        ->meta('owner', 'core-api');

    $availableColumns = $table->getAllAvailableColumns();
    $serialized       = $table->toArray();

    expect($table->getName())->toBe('orders')
        ->and($table->getLabel())->toBe('Orders')
        ->and($table->getDescription())->toBe('Order reporting table')
        ->and($table->getCategory())->toBe('operations')
        ->and($table->getExtension())->toBe('fleetops')
        ->and($table->getColumns())->toHaveCount(3)
        ->and($table->getComputedColumns())->toHaveCount(1)
        ->and($table->getAllColumns())->toHaveCount(4)
        ->and($table->getRelationships())->toHaveCount(2)
        ->and($table->getExcludedColumns())->toBe(['internal_token'])
        ->and($table->getSupportsAggregates())->toBeFalse()
        ->and($table->getMaxRows())->toBe(500)
        ->and($table->isCacheable())->toBeFalse()
        ->and($table->getCacheTtl())->toBe(120)
        ->and($table->getPermissions())->toBe(['reports.view'])
        ->and($table->getMeta('owner'))->toBe('core-api')
        ->and($table->getRelationship('customer'))->toBe($customer)
        ->and($table->hasRelationship('payload'))->toBeTrue()
        ->and($table->getColumn('status'))->toBe($status)
        ->and($table->hasColumn('orders_count'))->toBeTrue()
        ->and($table->isColumnAllowed('status'))->toBeTrue()
        ->and($table->isColumnAllowed('company_uuid'))->toBeTrue()
        ->and($table->isColumnAllowed('internal_token'))->toBeFalse()
        ->and(array_map(fn (Column $column) => $column->getName(), array_values($table->getVisibleColumns())))->toBe(['status', 'orders_count'])
        ->and($table->getAutoJoinRelationships())->toHaveCount(1)
        ->and($table->getManualJoinRelationships())->toHaveCount(1)
        ->and(array_map(fn (Column $column) => $column->getName(), array_values($availableColumns)))->toBe(['status', 'orders_count', 'name'])
        ->and(array_values($availableColumns)[2]->getMeta('auto_join_path'))->toBe('customer')
        ->and($serialized)->toMatchArray([
            'name'                => 'orders',
            'label'               => 'Orders',
            'category'            => 'operations',
            'extension'           => 'fleetops',
            'supports_aggregates' => false,
            'max_rows'            => 500,
            'cacheable'           => false,
            'cache_ttl'           => 120,
            'permissions'         => ['reports.view'],
            'meta'                => ['owner' => 'core-api'],
        ])
        ->and($serialized['columns'])->toHaveCount(2)
        ->and($serialized['computed_columns'])->toHaveCount(1)
        ->and($serialized['auto_join_relationships'])->toHaveCount(1)
        ->and($serialized['manual_join_relationships'])->toHaveCount(1);
});

it('builds reporting tables through array shorthand and incremental mutators', function () {
    $status = Column::make('status');
    $events = Relationship::hasMany('events', 'events');

    $table = Table::make('audit_logs')
        ->columns([
            ['name' => 'event_name', 'type' => 'string', 'label' => 'Event Name', 'description' => 'Lifecycle event'],
            'created_at',
        ])
        ->addColumn($status)
        ->computedColumns([Column::computed('events_count', 'COUNT(*)')])
        ->addComputedColumn(Column::computed('last_event_at', 'MAX(created_at)'))
        ->relationships([$events])
        ->addRelationship(Relationship::hasOne('actor', 'users'))
        ->meta('retention_days', 30);

    expect(array_map(fn (Column $column) => $column->getName(), $table->getColumns()))->toBe(['event_name', 'created_at', 'status'])
        ->and($table->getColumns()[0]->getLabel())->toBe('Event Name')
        ->and($table->getColumns()[0]->getDescription())->toBe('Lifecycle event')
        ->and(array_map(fn (Column $column) => $column->getName(), $table->getComputedColumns()))->toBe(['events_count', 'last_event_at'])
        ->and(array_map(fn (Relationship $relationship) => $relationship->getName(), $table->getRelationships()))->toBe(['events', 'actor'])
        ->and($table->getMeta())->toBe(['retention_days' => 30]);
});
