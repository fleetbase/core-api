<?php

use Fleetbase\Support\QueryOptimizer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class QueryOptimizerOrder extends Model
{
    protected $table      = 'orders';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;
    protected $guarded    = [];
}

class QueryOptimizerProbe extends QueryOptimizer
{
    public static function bindingCount(array $where): int
    {
        return static::getBindingCount($where);
    }

    public static function signature(array $where, array $bindings): string
    {
        return static::createWhereSignature($where, $bindings);
    }

    public static function valid(array $originalWheres, array $originalBindings, array $uniqueWheres, array $uniqueBindings): bool
    {
        return static::validateOptimization($originalWheres, $originalBindings, $uniqueWheres, $uniqueBindings);
    }
}

function query_optimizer_database(): void
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => $connectionConfig,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
}

test('query optimizer removes duplicate wheres while preserving binding order', function () {
    query_optimizer_database();

    $query = QueryOptimizerOrder::query()
        ->where('company_uuid', 'company-1')
        ->where('company_uuid', 'company-1')
        ->whereIn('status', ['pending', 'ready'])
        ->whereIn('status', ['pending', 'ready'])
        ->whereNull('deleted_at')
        ->whereNull('deleted_at');

    QueryOptimizer::removeDuplicateWheres($query);

    expect($query->getQuery()->wheres)->toHaveCount(3)
        ->and($query->getBindings())->toBe(['company-1', 'pending', 'ready'])
        ->and($query->toSql())->toBe('select * from "orders" where "company_uuid" = ? and "status" in (?, ?) and "deleted_at" is null');
});

test('query optimizer keeps matching columns with distinct binding values', function () {
    query_optimizer_database();

    $query = QueryOptimizerOrder::query()
        ->where('status', 'pending')
        ->where('status', 'ready')
        ->whereBetween('created_at', ['2026-07-01', '2026-07-31'])
        ->whereBetween('created_at', ['2026-08-01', '2026-08-31']);

    QueryOptimizer::removeDuplicateWheres($query);

    expect($query->getQuery()->wheres)->toHaveCount(4)
        ->and($query->getBindings())->toBe([
            'pending',
            'ready',
            '2026-07-01',
            '2026-07-31',
            '2026-08-01',
            '2026-08-31',
        ]);
});

test('query optimizer deduplicates nested wheres with matching structure and bindings', function () {
    query_optimizer_database();

    $query = QueryOptimizerOrder::query()
        ->where(function ($nested) {
            $nested->where('status', 'pending')->whereNull('deleted_at');
        })
        ->where(function ($nested) {
            $nested->where('status', 'pending')->whereNull('deleted_at');
        });

    QueryOptimizer::removeDuplicateWheres($query);

    expect($query->getQuery()->wheres)->toHaveCount(1)
        ->and($query->getBindings())->toBe(['pending'])
        ->and($query->toSql())->toBe('select * from "orders" where ("status" = ? and "deleted_at" is null)');
});

test('query optimizer leaves raw where queries unchanged', function () {
    query_optimizer_database();

    $query = QueryOptimizerOrder::query()
        ->where('company_uuid', 'company-1')
        ->whereRaw('json_extract(meta, "$.priority") = ?', ['high'])
        ->where('company_uuid', 'company-1');

    $beforeWheres   = $query->getQuery()->wheres;
    $beforeBindings = $query->getBindings();

    QueryOptimizer::removeDuplicateWheres($query);

    expect($query->getQuery()->wheres)->toBe($beforeWheres)
        ->and($query->getBindings())->toBe($beforeBindings);
});

test('query optimizer preserves expression backed wheres without consuming bindings', function () {
    query_optimizer_database();

    $query = QueryOptimizerOrder::query()
        ->where('created_at', '>=', new Expression('CURRENT_DATE'))
        ->where('created_at', '>=', new Expression('CURRENT_DATE'))
        ->whereBetween('available_at', [new Expression('CURRENT_DATE'), '2026-07-31'])
        ->whereBetween('available_at', [new Expression('CURRENT_DATE'), '2026-07-31']);

    QueryOptimizer::removeDuplicateWheres($query);

    expect($query->getQuery()->wheres)->toHaveCount(2)
        ->and($query->getBindings())->toBe(['2026-07-31'])
        ->and($query->toSql())->toBe('select * from "orders" where "created_at" >= CURRENT_DATE and "available_at" between CURRENT_DATE and ?');
});

test('query optimizer helper contracts count nested exists and fallback where bindings', function () {
    query_optimizer_database();

    $nested = QueryOptimizerOrder::query()
        ->where('status', 'pending')
        ->where('company_uuid', 'company-1')
        ->getQuery();

    $exists = QueryOptimizerOrder::query()
        ->where('dispatchable', true)
        ->getQuery();

    expect(QueryOptimizerProbe::bindingCount(['type' => 'Nested', 'query' => $nested]))->toBe(2)
        ->and(QueryOptimizerProbe::bindingCount(['type' => 'Nested', 'query' => new stdClass()]))->toBe(0)
        ->and(QueryOptimizerProbe::bindingCount(['type' => 'Exists', 'query' => $exists]))->toBe(1)
        ->and(QueryOptimizerProbe::bindingCount(['type' => 'NotExists', 'query' => new stdClass()]))->toBe(0)
        ->and(QueryOptimizerProbe::bindingCount(['type' => 'Basic', 'value' => new Expression('CURRENT_DATE')]))->toBe(0)
        ->and(QueryOptimizerProbe::bindingCount(['type' => 'Between', 'values' => []]))->toBe(2);
});

test('query optimizer signatures include nested exists raw unknown and expression details', function () {
    query_optimizer_database();

    $nested = QueryOptimizerOrder::query()
        ->where('status', 'pending')
        ->whereNull('deleted_at')
        ->getQuery();
    $exists = QueryOptimizerOrder::query()
        ->where('dispatchable', true)
        ->getQuery();

    $basicExpression = json_decode(QueryOptimizerProbe::signature([
        'type'     => 'Basic',
        'column'   => 'created_at',
        'operator' => '>=',
        'value'    => new Expression('CURRENT_DATE'),
    ], []), true);
    $nestedSignature = json_decode(QueryOptimizerProbe::signature(['type' => 'Nested', 'query' => $nested], ['pending']), true);
    $existsSignature = json_decode(QueryOptimizerProbe::signature(['type' => 'Exists', 'query' => $exists], [true]), true);
    $rawSignature    = json_decode(QueryOptimizerProbe::signature(['type' => 'Raw', 'sql' => 'deleted_at is null'], []), true);
    $unknown         = json_decode(QueryOptimizerProbe::signature(['type' => 'JsonContains', 'column' => 'meta->tags'], ['vip']), true);

    expect($basicExpression)->toMatchArray([
        'type'     => 'basic',
        'column'   => 'created_at',
        'operator' => '>=',
        'value'    => 'CURRENT_DATE',
    ])->and($nestedSignature['nested'])->toBe([
        ['type' => 'basic', 'column' => 'status', 'operator' => '=', 'boolean' => 'and'],
        ['type' => 'null', 'column' => 'deleted_at', 'operator' => null, 'boolean' => 'and'],
    ])->and($nestedSignature['bindings'])->toBe(['pending'])
        ->and($existsSignature['nested'])->toBe([
            ['type' => 'basic', 'column' => 'dispatchable', 'operator' => '=', 'boolean' => 'and'],
        ])
        ->and($existsSignature['bindings'])->toBe([true])
        ->and($rawSignature)->toMatchArray(['type' => 'raw', 'sql' => 'deleted_at is null'])
        ->and($unknown)->toMatchArray([
            'type'     => 'jsoncontains',
            'where'    => ['type' => 'JsonContains', 'column' => 'meta->tags'],
            'bindings' => ['vip'],
        ]);
});

test('query optimizer validation rejects expanded missing and mismatched optimizations', function () {
    $originalWheres = [
        ['type' => 'Basic', 'column' => 'status', 'operator' => '=', 'value' => 'pending'],
    ];
    $expandedWheres = [
        ['type' => 'Basic', 'column' => 'status', 'operator' => '=', 'value' => 'pending'],
        ['type' => 'Basic', 'column' => 'company_uuid', 'operator' => '=', 'value' => 'company-1'],
    ];

    expect(QueryOptimizerProbe::valid($originalWheres, ['pending'], $expandedWheres, ['pending', 'company-1']))->toBeFalse()
        ->and(QueryOptimizerProbe::valid($originalWheres, ['pending'], $originalWheres, ['pending', 'extra']))->toBeFalse()
        ->and(QueryOptimizerProbe::valid($originalWheres, ['pending'], [], []))->toBeFalse()
        ->and(QueryOptimizerProbe::valid($originalWheres, ['pending'], $originalWheres, []))->toBeFalse();
});
