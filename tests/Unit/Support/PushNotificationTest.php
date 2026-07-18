<?php

use Fleetbase\Support\PushNotification;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;

function push_notification_file_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();

    $storageRoot = storage_path('push-notification');
    $connection  = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'filesystems.default'        => 'testing',
        'filesystems.disks.testing'  => [
            'driver' => 'local',
            'root'   => $storageRoot,
            'url'    => 'http://fleetbase.test/storage',
        ],
        'fleetbase.connection.db' => 'mysql',
    ]);

    $filesystem = new FilesystemManager($container);
    $container->instance('filesystem', $filesystem);
    $container->instance(FilesystemFactory::class, $filesystem);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable()->unique();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('disk')->nullable();
        $table->longText('path')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    return $capsule;
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('configures fcm client options and removes file path credentials from runtime config', function () {
    bind_test_container([
        'firebase.projects.app' => [
            'project_id'          => 'fleetbase-test',
            'credentials'         => ['client_email' => 'firebase@test.invalid'],
            'credentials_file'    => '/tmp/firebase.json',
            'credentials_file_id' => 'not-a-file-uuid',
        ],
    ]);

    $config = PushNotification::configureFcmClient();

    expect($config)->toBe([
        'project_id'          => 'fleetbase-test',
        'credentials'         => ['client_email' => 'firebase@test.invalid'],
        'credentials_file_id' => 'not-a-file-uuid',
    ])->and(config('firebase.projects.app'))->toBe($config);
});

it('loads fcm credentials from stored file records and normalizes private key newlines', function () {
    $capsule = push_notification_file_database();

    $fileId = '11111111-1111-4111-8111-111111111111';
    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid'         => $fileId,
        'public_id'    => 'file_firebase',
        'company_uuid' => 'company-1',
        'disk'         => 'testing',
        'path'         => 'credentials/firebase.json',
    ]);
    Storage::disk('testing')->put('credentials/firebase.json', json_encode([
        'type'         => 'service_account',
        'project_id'   => 'fleetbase-test',
        'client_email' => 'firebase@test.invalid',
        'private_key'  => '-----BEGIN PRIVATE KEY-----\\nline-one\\n-----END PRIVATE KEY-----\\n',
    ]));
    config([
        'firebase.projects.app' => [
            'project_id'           => 'fleetbase-test',
            'credentials_file'     => '/tmp/old-firebase.json',
            'credentials_file_id'  => $fileId,
        ],
    ]);

    $config = PushNotification::configureFcmClient();

    expect($config)->toHaveKey('credentials')
        ->and($config)->not->toHaveKey('credentials_file')
        ->and($config['credentials_file_id'])->toBe($fileId)
        ->and($config['credentials']['client_email'])->toBe('firebase@test.invalid')
        ->and($config['credentials']['private_key'])->toBe("-----BEGIN PRIVATE KEY-----\nline-one\n-----END PRIVATE KEY-----\n")
        ->and(config('firebase.projects.app'))->toBe($config);
});
