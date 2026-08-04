<?php

use Fleetbase\Models\Model;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class BaseModelContractsCacheFake
{
    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return true;
    }

    public function increment(string $key, mixed $value = 1): int
    {
        return (int) $value;
    }
}

class BaseModelContractsResponseCacheFake
{
    public function clear(): bool
    {
        return true;
    }
}

class BaseModelContractsRecord extends Model
{
    protected $table = 'base_model_contract_records';

    protected $guarded = [];

    public $timestamps = false;

    protected $httpResource = 'HttpResourceContract';

    protected $resource = 'ResourceContract';

    protected $httpRequest = 'HttpRequestContract';

    protected $request = 'RequestContract';

    protected $httpFilter = 'HttpFilterContract';

    protected $filter = 'FilterContract';
}

class BaseModelContractsSearchableRecord extends BaseModelContractsRecord
{
    use Searchable;
}

function base_model_contracts_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetConnectionResolver();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new BaseModelContractsCacheFake());
    $container->instance('responsecache', new BaseModelContractsResponseCacheFake());
    Cache::swap($container->make('cache'));
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');
    session()->flush();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('mysql')->getSchemaBuilder()->create('base_model_contract_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

afterEach(function () {
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('exposes base model connection searchability queue and http class contracts', function () {
    base_model_contracts_database();

    $record = new BaseModelContractsRecord([
        'uuid'      => '018f2fa0-7d62-73fd-8cc2-7b51d21221c1',
        'public_id' => 'record_saved',
        'name'      => 'Saved record',
    ]);

    expect($record->getConnectionName())->toBe('mysql')
        ->and(BaseModelContractsRecord::isSearchable())->toBeFalse()
        ->and(BaseModelContractsSearchableRecord::isSearchable())->toBeTrue()
        ->and($record->saveInstance())->toBe($record)
        ->and($record->exists)->toBeTrue()
        ->and($record->getQueueableRelations())->toBe([])
        ->and($record->resolveChildRouteBinding('child', 'value', null))->toBeNull()
        ->and($record->getResource())->toBe('ResourceContract')
        ->and($record->getRequest())->toBe('RequestContract')
        ->and($record->getFilter())->toBe('FilterContract');
});

it('finds records by uuid public id existing model and soft deleted scope options', function () {
    $capsule = base_model_contracts_database();
    $uuid    = '018f2fa0-7d62-73fd-8cc2-7b51d21221c2';

    $capsule->getConnection('mysql')->table('base_model_contract_records')->insert([
        [
            'uuid'       => $uuid,
            'public_id'  => 'record_live',
            'name'       => 'Live record',
            'deleted_at' => null,
        ],
        [
            'uuid'       => '018f2fa0-7d62-73fd-8cc2-7b51d21221c3',
            'public_id'  => 'record_deleted',
            'name'       => 'Deleted record',
            'deleted_at' => '2026-07-18 11:00:00',
        ],
    ]);

    $byUuid      = BaseModelContractsRecord::findById($uuid);
    $byPublicId  = BaseModelContractsRecord::findById('record_live', columns: ['uuid', 'public_id']);
    $softMissing = BaseModelContractsRecord::findById('record_deleted');
    $softFound   = BaseModelContractsRecord::findById('record_deleted', withTrashed: true);

    expect(BaseModelContractsRecord::findById($byUuid))->toBe($byUuid)
        ->and(BaseModelContractsRecord::findById(null))->toBeNull()
        ->and(BaseModelContractsRecord::findById(''))->toBeNull()
        ->and($byUuid)->toBeInstanceOf(BaseModelContractsRecord::class)
        ->and($byUuid->public_id)->toBe('record_live')
        ->and($byPublicId->uuid)->toBe($uuid)
        ->and($byPublicId->getAttributes())->toHaveKeys(['uuid', 'public_id'])
        ->and($softMissing)->toBeNull()
        ->and($softFound)->toBeInstanceOf(BaseModelContractsRecord::class)
        ->and($softFound->public_id)->toBe('record_deleted');
});

it('finds records or throws model not found exceptions with requested identifiers', function () {
    $capsule = base_model_contracts_database();
    $uuid    = '018f2fa0-7d62-73fd-8cc2-7b51d21221c4';

    $capsule->getConnection('mysql')->table('base_model_contract_records')->insert([
        'uuid'       => $uuid,
        'public_id'  => 'record_fail_fast',
        'name'       => 'Fail fast record',
        'deleted_at' => null,
    ]);

    $record = BaseModelContractsRecord::findByIdOrFail($uuid);

    expect(BaseModelContractsRecord::findByIdOrFail($record))->toBe($record)
        ->and($record->public_id)->toBe('record_fail_fast');

    try {
        BaseModelContractsRecord::findByIdOrFail('missing_record');
    } catch (ModelNotFoundException $exception) {
        $missing = $exception;
    }

    expect($missing)->toBeInstanceOf(ModelNotFoundException::class)
        ->and($missing->getModel())->toBe(BaseModelContractsRecord::class)
        ->and($missing->getIds())->toBe(['missing_record']);
});
