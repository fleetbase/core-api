<?php

use Fleetbase\Support\PushNotification;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AppInstance;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\Messages;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\RegistrationToken;
use Kreait\Firebase\Messaging\RegistrationTokens;
use Kreait\Firebase\Messaging\Topic;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\FcmMessage;
use Pushok\Client as PushOkClient;

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

function push_notification_apn_private_key(): string
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);

    openssl_pkey_export($key, $privateKey);

    return trim($privateKey);
}

function push_notification_reflect_property(object $object, string $property): mixed
{
    $reflectionProperty = new ReflectionProperty($object, $property);
    $reflectionProperty->setAccessible(true);

    return $reflectionProperty->getValue($object);
}

class PushNotificationMessagingFake implements Messaging
{
    public function send(Message|array $message, bool $validateOnly = false): array
    {
        return [];
    }

    public function sendMulticast(Message|array $message, RegistrationTokens|RegistrationToken|array|string $registrationTokens, bool $validateOnly = false): MulticastSendReport
    {
        throw new BadMethodCallException('sendMulticast should not be called by this test.');
    }

    public function sendAll(array|Messages $messages, bool $validateOnly = false): MulticastSendReport
    {
        throw new BadMethodCallException('sendAll should not be called by this test.');
    }

    public function validate(Message|array $message): array
    {
        return [];
    }

    public function validateRegistrationTokens(RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        return [
            'valid'   => [],
            'unknown' => [],
            'invalid' => [],
        ];
    }

    public function subscribeToTopic(string|Topic $topic, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        return [];
    }

    public function subscribeToTopics(iterable $topics, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        return [];
    }

    public function unsubscribeFromTopic(string|Topic $topic, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        return [];
    }

    public function unsubscribeFromTopics(array $topics, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        return [];
    }

    public function unsubscribeFromAllTopics(RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        return [];
    }

    public function getAppInstance(RegistrationToken|string $registrationToken): AppInstance
    {
        throw new BadMethodCallException('getAppInstance should not be called by this test.');
    }
}

class PushNotificationFcmTestDouble extends PushNotification
{
    public static Messaging $messaging;

    protected static function getFcmMessagingClient(): Messaging
    {
        return static::$messaging;
    }
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

it('loads apn key content from stored files and configures a sandbox client', function () {
    $capsule = push_notification_file_database();

    $fileId     = '22222222-2222-4222-8222-222222222222';
    $privateKey = push_notification_apn_private_key();
    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid'         => $fileId,
        'public_id'    => 'file_apn',
        'company_uuid' => 'company-1',
        'disk'         => 'testing',
        'path'         => 'credentials/apn.p8',
    ]);
    Storage::disk('testing')->put('credentials/apn.p8', str_replace("\n", '\\n', $privateKey));
    config([
        'broadcasting.connections.apn' => [
            'key_id'              => 'ABC123DEFG',
            'team_id'             => 'TEAM123456',
            'app_bundle_id'       => 'com.fleetbase.test',
            'private_key_path'    => '/tmp/old-apn.p8',
            'private_key_file'    => 'old-apn.p8',
            'private_key_file_id' => $fileId,
            'production'          => 'false',
        ],
    ]);

    $client       = PushNotification::getApnClient();
    $authProvider = push_notification_reflect_property($client, 'authProvider');

    expect($client)->toBeInstanceOf(PushOkClient::class)
        ->and(push_notification_reflect_property($client, 'isProductionEnv'))->toBeFalse()
        ->and(push_notification_reflect_property($authProvider, 'keyId'))->toBe('ABC123DEFG')
        ->and(push_notification_reflect_property($authProvider, 'teamId'))->toBe('TEAM123456')
        ->and(push_notification_reflect_property($authProvider, 'appBundleId'))->toBe('com.fleetbase.test')
        ->and(push_notification_reflect_property($authProvider, 'privateKeyPath'))->toBeNull()
        ->and(push_notification_reflect_property($authProvider, 'privateKeyContent'))->toBe($privateKey)
        ->and($authProvider->generateApnsTopic('alert'))->toBe('com.fleetbase.test')
        ->and($authProvider->generateApnsTopic('voip'))->toBe('com.fleetbase.test.voip');
});

it('defaults apn production mode from the application environment when config omits it', function () {
    bind_test_container([
        'app.env'                      => 'production',
        'broadcasting.connections.apn' => [
            'key_id'              => 'ABC123DEFG',
            'team_id'             => 'TEAM123456',
            'app_bundle_id'       => 'com.fleetbase.test',
            'private_key_content' => push_notification_apn_private_key(),
        ],
    ]);

    $client = PushNotification::getApnClient();

    expect(push_notification_reflect_property($client, 'isProductionEnv'))->toBeTrue();
});

it('creates apn messages with title body custom data action and configured client', function () {
    bind_test_container([
        'broadcasting.connections.apn' => [
            'key_id'              => 'ABC123DEFG',
            'team_id'             => 'TEAM123456',
            'app_bundle_id'       => 'com.fleetbase.test',
            'private_key_content' => push_notification_apn_private_key(),
            'private_key_path'    => '/tmp/old-apn.p8',
            'private_key_file'    => 'old-apn.p8',
            'production'          => true,
        ],
    ]);

    $message = PushNotification::createApnMessage('Dispatch assigned', 'Order ABC is ready', [
        'order_uuid' => 'order-1',
        'screen'     => 'orders.show',
    ], 'open_order');

    expect($message)->toBeInstanceOf(ApnMessage::class)
        ->and($message->title)->toBe('Dispatch assigned')
        ->and($message->body)->toBe('Order ABC is ready')
        ->and($message->badge)->toBe(1)
        ->and($message->custom)->toBe([
            'order_uuid' => 'order-1',
            'screen'     => 'orders.show',
            'action'     => [
                'action' => 'open_order',
                'params' => [
                    'order_uuid' => 'order-1',
                    'screen'     => 'orders.show',
                ],
            ],
        ])
        ->and($message->client)->toBeInstanceOf(PushOkClient::class)
        ->and(push_notification_reflect_property($message->client, 'isProductionEnv'))->toBeTrue();
});

it('creates fcm messages with notification data custom options and configured client', function () {
    $messaging                                = new PushNotificationMessagingFake();
    PushNotificationFcmTestDouble::$messaging = $messaging;
    bind_test_container([
        'firebase.projects.app' => [
            'project_id'          => 'fleetbase-test',
            'credentials'         => ['client_email' => 'firebase@test.invalid'],
            'credentials_file'    => '/tmp/firebase.json',
            'credentials_file_id' => 'not-a-file-uuid',
        ],
    ]);

    $message = PushNotificationFcmTestDouble::createFcmMessage('Dispatch assigned', 'Order ABC is ready', [
        'order_uuid' => 'order-1',
        'screen'     => 'orders.show',
    ]);

    expect($message)->toBeInstanceOf(FcmMessage::class)
        ->and($message->notification->title)->toBe('Dispatch assigned')
        ->and($message->notification->body)->toBe('Order ABC is ready')
        ->and($message->data)->toBe([
            'order_uuid' => 'order-1',
            'screen'     => 'orders.show',
        ])
        ->and($message->client)->toBe($messaging)
        ->and($message->toArray())->toMatchArray([
            'notification' => [
                'title' => 'Dispatch assigned',
                'body'  => 'Order ABC is ready',
            ],
            'data' => [
                'order_uuid' => 'order-1',
                'screen'     => 'orders.show',
            ],
            'android' => [
                'notification' => [
                    'color' => '#4391EA',
                    'sound' => 'default',
                ],
                'fcm_options' => [
                    'analytics_label' => 'analytics',
                ],
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                    ],
                ],
                'fcm_options' => [
                    'analytics_label' => 'analytics',
                ],
            ],
        ])
        ->and(config('firebase.projects.app'))->not->toHaveKey('credentials_file');
});
