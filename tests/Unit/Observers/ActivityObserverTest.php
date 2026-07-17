<?php

use Fleetbase\Observers\ActivityObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function activity_observer_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'                                => 'testing',
        'database.connections.testing'                    => $connection,
        'activitylog.table_name'                          => 'activity',
        'activitylog.database_connection'                 => 'testing',
        'activitylog.subject_returns_soft_deleted_models' => false,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

    $capsule->getConnection('testing')->getSchemaBuilder()->create('activity', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable()->unique();
        $table->string('company_id')->nullable();
        $table->string('log_name')->nullable();
        $table->text('description')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('properties')->nullable();
        $table->string('event')->nullable();
        $table->string('batch_uuid')->nullable();
        $table->timestamps();
    });

    return $capsule;
}

afterEach(function () {
    Str::createUuidsNormally();
    session()->flush();
    if (app()->bound('config')) {
        config([
            'database.default'                => 'mysql',
            'activitylog.database_connection' => null,
            'activitylog.table_name'          => 'activity',
        ]);
    }
    Facade::clearResolvedInstances();
});

it('assigns the active company and a generated uuid before activity rows are created', function () {
    activity_observer_database();
    session(['company' => 'company-activity-1']);

    Str::createUuidsUsingSequence([
        '11111111-1111-4111-8111-111111111111',
    ]);

    $activity = new Activity(['description' => 'User updated report']);

    (new ActivityObserver())->creating($activity);

    expect($activity->company_id)->toBe('company-activity-1')
        ->and($activity->uuid)->toBe('11111111-1111-4111-8111-111111111111');
});

it('retries generated activity uuids when the first candidate already exists', function () {
    $capsule = activity_observer_database();
    $capsule->getConnection('testing')->table('activity')->insert([
        'uuid'        => '22222222-2222-4222-8222-222222222222',
        'company_id'  => 'company-existing',
        'description' => 'Existing activity',
        'created_at'  => '2026-07-18 12:00:00',
        'updated_at'  => '2026-07-18 12:00:00',
    ]);

    Str::createUuidsUsingSequence([
        '22222222-2222-4222-8222-222222222222',
        '33333333-3333-4333-8333-333333333333',
    ]);

    expect(ActivityObserver::generateUuidForActivity())->toBe('33333333-3333-4333-8333-333333333333');
});
