<?php

use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class TracksApiCredentialRecord extends EloquentModel
{
    use TracksApiCredential;

    protected $connection = 'mysql';
    protected $table      = 'tracks_api_credential_records';
    protected $fillable   = ['name', '_key', 'company_uuid'];
    public $timestamps    = false;
}

class TracksApiCredentialUnfillableRecord extends TracksApiCredentialRecord
{
    protected $fillable = ['name'];
}

function tracks_api_credential_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('mysql')->getSchemaBuilder()->create('tracks_api_credential_records', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
    });

    session()->flush();

    return $capsule;
}

afterEach(function () {
    session()->flush();
    EloquentModel::unsetEventDispatcher();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('tracks api credential sets console key when no api key session exists', function () {
    tracks_api_credential_database();

    $record = TracksApiCredentialRecord::create(['name' => 'Console record']);

    expect($record->_key)->toBe('console')
        ->and($record->company_uuid)->toBeNull();
});

test('tracks api credential uses api key and company session for fillable models', function () {
    tracks_api_credential_database();
    session(['api_key' => 'api-key-1', 'company' => 'company-1']);

    $record = TracksApiCredentialRecord::create(['name' => 'API record']);

    expect($record->_key)->toBe('api-key-1')
        ->and($record->company_uuid)->toBe('company-1');
});

test('tracks api credential preserves explicit keys and skips unfillable key columns', function () {
    tracks_api_credential_database();
    session(['api_key' => 'api-key-1', 'company' => 'company-1']);

    $explicit   = TracksApiCredentialRecord::create(['name' => 'Explicit record', '_key' => 'existing-key']);
    $unfillable = TracksApiCredentialUnfillableRecord::create(['name' => 'Unfillable record']);

    expect($explicit->_key)->toBe('existing-key')
        ->and($explicit->company_uuid)->toBeNull()
        ->and($unfillable->_key)->toBeNull()
        ->and($unfillable->company_uuid)->toBeNull();
});
