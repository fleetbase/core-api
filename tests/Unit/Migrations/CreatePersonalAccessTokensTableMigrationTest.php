<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Facade;

function personal_access_tokens_migration(): object
{
    return require dirname(__DIR__, 3) . '/migrations/2023_04_25_094300_create_personal_access_tokens_table.php';
}

function personal_access_tokens_schema(string $default = 'mysql'): Builder
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'fleetbase.connection.sandbox' => 'sandbox',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container['config']->set('database.default', $default);
    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $container->instance('db', $databaseManager);
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstances();

    return $schema;
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('it sorts after the sanctum 3 migration and before the migration that needs the table', function () {
    $migrations = [
        '2023_04_25_094311_fix_personal_access_tokens',
        '2023_04_25_094300_create_personal_access_tokens_table',
        '2019_12_14_000001_create_personal_access_tokens_table',
    ];
    sort($migrations);

    expect($migrations)->toBe([
        '2019_12_14_000001_create_personal_access_tokens_table',
        '2023_04_25_094300_create_personal_access_tokens_table',
        '2023_04_25_094311_fix_personal_access_tokens',
    ])->and(file_exists(dirname(__DIR__, 3) . '/migrations/2023_04_25_094311_fix_personal_access_tokens.php'))->toBeTrue();
});

test('it creates the table when sanctum did not', function () {
    $schema = personal_access_tokens_schema();

    personal_access_tokens_migration()->up();

    expect($schema->hasColumns('personal_access_tokens', [
        'id',
        'tokenable_type',
        'tokenable_id',
        'name',
        'token',
        'abilities',
        'last_used_at',
        'expires_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('it leaves an existing table and its tokens alone', function () {
    $schema = personal_access_tokens_schema();
    $schema->create('personal_access_tokens', function ($table) {
        $table->string('marker');
    });

    $migration = personal_access_tokens_migration();
    $migration->up();
    $migration->down();

    expect($schema->getColumnListing('personal_access_tokens'))->toBe(['marker']);
});

test('it does not add the table to the sandbox database', function () {
    $schema = personal_access_tokens_schema('sandbox');

    personal_access_tokens_migration()->up();

    expect($schema->hasTable('personal_access_tokens'))->toBeFalse();
});
