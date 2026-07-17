<?php

use Fleetbase\Support\QueryOptimizer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class QueryOptimizerOrder extends Model
{
    protected $table = 'orders';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
}

function query_optimizer_database(): void
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default' => 'testing',
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

    $beforeWheres = $query->getQuery()->wheres;
    $beforeBindings = $query->getBindings();

    QueryOptimizer::removeDuplicateWheres($query);

    expect($query->getQuery()->wheres)->toBe($beforeWheres)
        ->and($query->getBindings())->toBe($beforeBindings);
});
