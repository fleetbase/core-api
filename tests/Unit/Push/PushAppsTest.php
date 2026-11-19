<?php

use Fleetbase\Models\PushApp;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Push\ApnClientFactory;
use Fleetbase\Push\Concerns\PushesToApps;
use Fleetbase\Push\Contracts\SendsToPushApps;
use Fleetbase\Push\FirebaseMessagingFactory;
use Fleetbase\Push\PushAppChannel;
use Fleetbase\Push\PushConfigurationException;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;
use Fleetbase\Push\Transports\ApnTransport;
use Fleetbase\Push\Transports\FcmTransport;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\SendReport;

/**
 * Push apps: an organization's own apps with their own APNs and FCM credentials, and the
 * PushAppChannel that sends to the devices registered with them.
 */
function push_apps_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();
    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $container  = bind_test_container(['database.default' => 'mysql', 'database.connections.mysql' => $connection, 'fleetbase.connection.db' => 'mysql', 'api.cache.enabled' => false]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher($container));
    $container->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    $container->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    EloquentModel::encryptUsing($container->make('encrypter'));
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('push_apps', function ($table) {
        $table->increments('id');
        $table->uuid('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->uuid('company_uuid')->nullable();
        $table->uuid('created_by_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->text('apn')->nullable();
        $table->text('fcm')->nullable();
        $table->string('status')->nullable();
        $table->uuid('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('user_devices', function ($table) {
        $table->increments('id');
        $table->uuid('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->uuid('user_uuid')->nullable();
        $table->uuid('company_uuid')->nullable();
        $table->uuid('push_app_uuid')->nullable();
        $table->string('platform')->nullable();
        $table->string('app_identifier')->nullable();
        $table->string('environment')->nullable();
        $table->string('token')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

function push_apps_container(): void
{
    $container = bind_test_container();
    EloquentModel::encryptUsing($container->make('encrypter'));
}

function push_apps_apn(array $overrides = []): array
{
    return array_replace(['key_id' => 'KEY123', 'team_id' => 'TEAM123', 'app_bundle_id' => 'com.operator.passenger', 'private_key_content' => '-----BEGIN PRIVATE KEY-----\\nabc\\n-----END PRIVATE KEY-----'], $overrides);
}

function push_apps_fcm(array $overrides = []): array
{
    return array_replace(['credentials' => json_encode(['project_id' => 'operator-app', 'client_email' => 'push@operator-app.iam.gserviceaccount.com', 'private_key' => 'secret\\nkey'])], $overrides);
}

class PushAppsFakeApnTransport extends ApnTransport
{
    public array $calls = [];

    /** @var array<string, array<string, PushOutcome>> outcomes per environment, keyed by token */
    public array $outcomes = [];

    public function __construct()
    {
    }

    public function send(PushCredentials $channel, string $environment, PushMessage $message, array $tokens): array
    {
        $this->calls[] = [$channel->id, $environment, $tokens];

        return array_intersect_key($this->outcomes[$environment] ?? [], array_flip($tokens)) + array_fill_keys($tokens, PushOutcome::sent());
    }
}

class PushAppsFakeFcmTransport extends FcmTransport
{
    public array $calls      = [];
    public ?Throwable $throw = null;

    public function __construct()
    {
    }

    public function send(PushCredentials $channel, PushMessage $message, array $tokens): array
    {
        $this->calls[] = [$channel->id, $tokens, $message->title];
        if ($this->throw) {
            throw $this->throw;
        }

        return array_fill_keys($tokens, PushOutcome::sent());
    }
}

class PushAppsTestNotification extends Notification implements SendsToPushApps
{
    use PushesToApps;

    public array $data = ['id' => 'order_1', 'type' => 'order_update'];

    public function __construct(public string $title = 'Your driver has arrived', public string $message = 'Sam is waiting.')
    {
    }
}

test('a push app makes credentials only for the platforms it has keys for', function () {
    push_apps_container();
    $app = new PushApp();
    $app->setRawAttributes(['uuid' => 'app-uuid', 'public_id' => 'push_app_1', 'name' => 'Bayline Rides']);
    $app->apn = push_apps_apn();

    expect($app->credentials('apn'))->toBeInstanceOf(PushCredentials::class)
        ->and($app->credentials('apn')->config['app_bundle_id'])->toBe('com.operator.passenger')
        ->and($app->credentials('apn')->id)->toBe('push_app_1')
        ->and($app->credentials('fcm'))->toBeNull()
        ->and($app->credentials('sms'))->toBeNull()
        ->and(ApnClientFactory::credentials($app->credentials('apn'))['private_key_content'])->toContain("\nabc\n");

    $app->fcm = push_apps_fcm();
    expect(FirebaseMessagingFactory::serviceAccount($app->credentials('fcm')))->toMatchArray(['project_id' => 'operator-app', 'private_key' => "secret\nkey", 'type' => 'service_account']);
});

test('the summaries show the settings but never the keys', function () {
    push_apps_container();
    $app      = new PushApp();
    $app->apn = push_apps_apn(['environment' => 'sandbox']);
    $app->fcm = push_apps_fcm(['android_channel_id' => 'trips']);

    expect($app->apn_summary)->toBe(['key_id' => 'KEY123', 'team_id' => 'TEAM123', 'app_bundle_id' => 'com.operator.passenger', 'environment' => 'sandbox', 'has_key' => true])
        ->and($app->fcm_summary)->toBe(['project_id' => 'operator-app', 'client_email' => 'push@operator-app.iam.gserviceaccount.com', 'android_channel_id' => 'trips', 'has_credentials' => true])
        ->and(json_encode($app->toArray()))->not->toContain('PRIVATE KEY')->not->toContain('secret');

    expect((new PushApp())->apn_summary)->toBeNull()->and((new PushApp())->fcm_summary)->toBeNull();
});

test('editing an app without its keys keeps the keys it has', function () {
    expect(PushApp::keepSecrets(['key_id' => 'NEW', 'private_key_content' => ''], ['key_id' => 'OLD', 'private_key_content' => 'p8'], ['private_key_content']))->toBe(['key_id' => 'NEW', 'private_key_content' => 'p8'])
        ->and(PushApp::keepSecrets(['credentials' => 'new'], ['credentials' => 'old'], ['credentials']))->toBe(['credentials' => 'new'])
        ->and(PushApp::keepSecrets(null, ['credentials' => 'old'], ['credentials']))->toBeNull();

    push_apps_database();
    $app = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn(), 'fcm' => push_apps_fcm()]);
    expect($app->key)->toStartWith(PushApp::KEY_PREFIX)->and(strlen($app->key))->toBe(strlen(PushApp::KEY_PREFIX) + 32)->and($app->status)->toBe('active');

    $app->update(['apn' => ['key_id' => 'KEY999', 'team_id' => 'TEAM123', 'app_bundle_id' => 'com.operator.passenger', 'private_key_content' => ''], 'fcm' => ['credentials' => null]]);
    $fresh = PushApp::where('uuid', $app->uuid)->first();

    expect($fresh->apn['key_id'])->toBe('KEY999')
        ->and($fresh->apn['private_key_content'])->toContain('BEGIN PRIVATE KEY')
        ->and($fresh->fcm['credentials'])->toBe(push_apps_fcm()['credentials']);
});

test('apns environments follow the device, then the app, then production before sandbox', function () {
    $credentials = new PushCredentials('app', 'App', 'apn', push_apps_apn());

    expect(ApnClientFactory::environments($credentials))->toBe(['production', 'sandbox'])
        ->and(ApnClientFactory::environments($credentials, 'sandbox'))->toBe(['sandbox'])
        ->and(ApnClientFactory::environments(new PushCredentials('app', 'App', 'apn', push_apps_apn(['environment' => 'sandbox']))))->toBe(['sandbox']);

    expect(fn () => ApnClientFactory::credentials(new PushCredentials('app', 'App', 'apn', ['key_id' => 'K'])))->toThrow(PushConfigurationException::class, 'missing "team_id"')
        ->and(fn () => FirebaseMessagingFactory::serviceAccount(new PushCredentials('app', 'App', 'fcm', ['credentials' => '{bad'])))->toThrow(PushConfigurationException::class)
        ->and(fn () => FirebaseMessagingFactory::serviceAccount(new PushCredentials('app', 'App', 'fcm', ['credentials' => ['project_id' => 'p']])))->toThrow(PushConfigurationException::class, 'missing "client_email"');
});

test('the install-wide channels leave out devices that registered with a push app', function () {
    push_apps_container();
    $devices = collect([
        new UserDevice(['platform' => 'ios', 'token' => 'navigator-ios']),
        new UserDevice(['platform' => 'ios', 'token' => 'passenger-ios', 'push_app_uuid' => 'app-uuid']),
        new UserDevice(['platform' => 'android', 'token' => 'navigator-android']),
        new UserDevice(['platform' => 'android', 'token' => 'passenger-android', 'push_app_uuid' => 'app-uuid']),
    ]);

    $user = new User();
    $user->setRelation('devices', $devices);

    expect($user->routeNotificationForApn())->toBe(['navigator-ios'])
        ->and($user->routeNotificationForFcm())->toBe(['navigator-android'])
        ->and($user->routeNotificationForPushApp()->pluck('token')->all())->toBe(['passenger-ios', 'passenger-android'])
        ->and(UserDevice::normalizePlatform('iPhone'))->toBe('ios')
        ->and(UserDevice::normalizePlatform('FCM'))->toBe('android')
        ->and(UserDevice::normalizePlatform('web'))->toBeNull();
});

test('the channel sends each device with its own app and retries a bad ios token in sandbox', function () {
    push_apps_database();
    $bayline = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn(), 'fcm' => push_apps_fcm()]);
    $harbour = PushApp::create(['name' => 'Harbour Cabs', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn(['app_bundle_id' => 'com.harbour.cabs'])]);
    $off     = PushApp::create(['name' => 'Retired', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn(), 'status' => 'inactive']);

    $user = new User();
    $user->setRelation('devices', collect([
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'bayline-ios', 'push_app_uuid' => $bayline->uuid]),
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'bayline-dev', 'push_app_uuid' => $bayline->uuid]),
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'android', 'token' => 'bayline-android', 'push_app_uuid' => $bayline->uuid]),
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'harbour-ios', 'push_app_uuid' => $harbour->uuid, 'environment' => 'sandbox']),
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'android', 'token' => 'harbour-android', 'push_app_uuid' => $harbour->uuid]),
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'retired-ios', 'push_app_uuid' => $off->uuid]),
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'navigator-ios']),
        UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'gone', 'push_app_uuid' => $bayline->uuid, 'status' => 'invalid']),
    ]));

    $apn                       = new PushAppsFakeApnTransport();
    $apn->outcomes             = ['production' => ['bayline-dev' => PushOutcome::wrongEnvironment('BadDeviceToken')]];
    $fcm                       = new PushAppsFakeFcmTransport();
    $outcomes                  = (new PushAppChannel($fcm, $apn))->send($user, new PushAppsTestNotification());

    expect($apn->calls)->toBe([
        [$bayline->public_id, 'production', ['bayline-ios', 'bayline-dev']],
        [$bayline->public_id, 'sandbox', ['bayline-dev']],
        [$harbour->public_id, 'sandbox', ['harbour-ios']],
    ])
        ->and($fcm->calls)->toBe([[$bayline->public_id, ['bayline-android'], 'Your driver has arrived']])
        ->and(array_map(fn ($outcome) => $outcome->status, $outcomes))->toBe(['bayline-ios' => 'sent', 'bayline-dev' => 'sent', 'bayline-android' => 'sent', 'harbour-ios' => 'sent']);
});

test('a token rejected everywhere is removed, and provider errors are kept and logged', function () {
    push_apps_database();
    $app  = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn(), 'fcm' => push_apps_fcm()]);
    $dead = UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'dead-ios', 'push_app_uuid' => $app->uuid]);
    $user = new User();
    $user->setRelation('devices', collect([$dead, UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'android', 'token' => 'android', 'push_app_uuid' => $app->uuid])]));

    $apn           = new PushAppsFakeApnTransport();
    $apn->outcomes = ['production' => ['dead-ios' => PushOutcome::wrongEnvironment('BadDeviceToken')], 'sandbox' => ['dead-ios' => PushOutcome::wrongEnvironment('BadDeviceToken')]];
    $fcm           = new PushAppsFakeFcmTransport();
    $fcm->throw    = new RuntimeException('quota exceeded');

    $outcomes = (new PushAppChannel($fcm, $apn))->send($user, new PushAppsTestNotification());

    expect($outcomes['dead-ios']->status)->toBe(PushOutcome::DEAD)
        ->and($outcomes['android']->status)->toBe(PushOutcome::ERROR)
        ->and(UserDevice::withTrashed()->where('uuid', $dead->uuid)->first()->status)->toBe('invalid')
        ->and(UserDevice::where('uuid', $dead->uuid)->first())->toBeNull()
        ->and(collect(app('log')->entries)->pluck(1)->all())->toContain('[Push apps] The push provider request failed.');
});

test('the channel sends nothing for other notifications, empty messages or no app devices', function () {
    push_apps_database();
    $apn  = new PushAppsFakeApnTransport();
    $fcm  = new PushAppsFakeFcmTransport();
    $user = new User();
    $user->setRelation('devices', collect([new UserDevice(['platform' => 'ios', 'token' => 'navigator-ios'])]));

    expect((new PushAppChannel($fcm, $apn))->send($user, new class extends Notification {}))->toBe([])
        ->and((new PushAppChannel($fcm, $apn))->send($user, new PushAppsTestNotification()))->toBe([])
        ->and((new PushAppChannel($fcm, $apn))->send(new stdClass(), new PushAppsTestNotification()))->toBe([])
        ->and((new PushAppsTestNotification('', ''))->toPushApp($user))->toBeNull()
        ->and((new PushAppsTestNotification())->toPushApp($user)->category)->toBe('view_order')
        ->and($apn->calls)->toBe([]);
});

test('a device finds its push app by key, in its company, only while the app is on', function () {
    push_apps_database();
    $app = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn()]);
    PushApp::create(['name' => 'Retired', 'company_uuid' => 'company-uuid', 'status' => 'inactive']);

    expect(PushApp::findByKey($app->key)?->uuid)->toBe($app->uuid)
        ->and(PushApp::findByKey(' ' . $app->key . ' ', 'company-uuid')?->uuid)->toBe($app->uuid)
        ->and(PushApp::findByKey($app->key, 'other-company'))->toBeNull()
        ->and(PushApp::findByKey(PushApp::where('name', 'Retired')->first()->key))->toBeNull()
        ->and(PushApp::findByKey('flb_live_123'))->toBeNull()
        ->and(PushApp::findByKey(null))->toBeNull()
        ->and($app->deviceAttributes())->toBe(['push_app_uuid' => $app->uuid, 'company_uuid' => 'company-uuid', 'app_identifier' => $app->uuid]);
});

/*
 * The push layer under PushAppChannel: the message both providers render, the APNs and FCM
 * transports and client factories, and the channel's failure paths.
 */ function push_layer_service_account(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $privateKey);

    return [
        'type'         => 'service_account',
        'project_id'   => 'push-app-tests',
        'client_email' => 'firebase-admin@push-app-tests.iam.gserviceaccount.com',
        'client_id'    => '1234567890',
        'private_key'  => $privateKey,
    ];
}

test('push messages render high priority FCM payloads with string data', function () {
    $message = PushMessage::create('Driver arrived', 'Sam is waiting', [
        'id'       => 'order_public',
        'count'    => 2,
        'pickup'   => true,
        'meta'     => ['a' => 1],
        'optional' => null,
    ])->analyticsLabel('order_update');

    $payload = $message->toFcmArray(['android_channel_id' => 'trips']);

    expect($payload['notification'])->toBe(['title' => 'Driver arrived', 'body' => 'Sam is waiting'])
        ->and($payload['data'])->toBe(['id' => 'order_public', 'count' => '2', 'pickup' => 'true', 'meta' => '{"a":1}'])
        ->and($payload['android']['priority'])->toBe('high')
        ->and($payload['android']['notification'])->toMatchArray(['channel_id' => 'trips', 'sound' => 'default'])
        ->and($payload['apns']['headers'])->toBe(['apns-priority' => '10', 'apns-push-type' => 'alert'])
        ->and($payload['apns']['payload']['aps'])->toBe(['sound' => 'default', 'badge' => 1])
        ->and($payload['fcm_options'])->toBe(['analytics_label' => 'order_update'])
        ->and(Kreait\Firebase\Messaging\CloudMessage::fromArray($payload))->toBeInstanceOf(Kreait\Firebase\Messaging\CloudMessage::class);

    expect(PushMessage::create('Hi', 'There')->toFcmArray())->not->toHaveKey('data')
        ->and(PushMessage::create('Hi', 'There')->toFcmArray()['android']['notification'])->not->toHaveKey('channel_id');
});

test('push messages render APNs payloads with sound, badge and custom data', function () {
    $payload = json_decode(json_encode(
        PushMessage::create('Driver arrived', 'Sam is waiting', ['id' => 'order_public', 'type' => 'order_update', 'aps' => 'ignored'])->toApnPayload()
    ), true);

    expect($payload['aps'])->toMatchArray([
        'alert' => ['title' => 'Driver arrived', 'body' => 'Sam is waiting'],
        'sound' => 'default',
        'badge' => 1,
    ])->and($payload['id'])->toBe('order_public')
        ->and($payload['type'])->toBe('order_update');
});

test('push message fluent setters render into both provider payloads', function () {
    $message = PushMessage::create('Driver arrived', 'Sam is waiting')
        ->data(['id' => 'order_public'])
        ->data(['type' => 'order_update'])
        ->badge(3)
        ->sound('chime.caf')
        ->image('https://example.test/order.png')
        ->category('view_order')
        ->collapseKey('order_public');

    $fcm = $message->toFcmArray(['android_color' => '#4391EA']);
    $apn = json_decode(json_encode($message->toApnPayload()), true);

    expect($message->data)->toBe(['id' => 'order_public', 'type' => 'order_update'])
        ->and($fcm['notification']['image'])->toBe('https://example.test/order.png')
        ->and($fcm['android']['collapse_key'])->toBe('order_public')
        ->and($fcm['android']['notification'])->toMatchArray(['sound' => 'chime.caf', 'click_action' => 'view_order', 'tag' => 'order_public'])
        ->and($fcm['apns']['payload']['aps'])->toBe(['sound' => 'chime.caf', 'badge' => 3, 'category' => 'view_order'])
        ->and($apn['aps'])->toMatchArray(['sound' => 'chime.caf', 'badge' => 3, 'category' => 'view_order', 'mutable-content' => 1]);

    $silent = json_decode(json_encode(PushMessage::create('Hi', 'There')->sound(null)->badge(null)->toApnPayload()), true);

    expect($silent['aps'])->not->toHaveKey('sound')
        ->and($silent['aps'])->not->toHaveKey('badge');
});

test('push outcomes name what happened to a token', function () {
    expect(PushOutcome::sent()->is(PushOutcome::SENT))->toBeTrue()
        ->and(PushOutcome::dead('Unregistered')->reason)->toBe('Unregistered')
        ->and(PushOutcome::wrongApp('DeviceTokenNotForTopic')->status)->toBe(PushOutcome::WRONG_APP)
        ->and(PushOutcome::wrongEnvironment()->status)->toBe(PushOutcome::WRONG_ENVIRONMENT)
        ->and(PushOutcome::error('500')->is(PushOutcome::SENT))->toBeFalse();
});

test('firebase messaging clients are built from the app service account and reused', function () {
    FirebaseMessagingFactory::flush();
    $credentials = new PushCredentials('push_app_1', 'Bayline Rides', 'fcm', ['credentials' => json_encode(push_layer_service_account())]);
    $factory     = new FirebaseMessagingFactory();
    $client      = $factory->make($credentials);

    expect($client)->toBeInstanceOf(Kreait\Firebase\Contract\Messaging::class)
        ->and($factory->make($credentials))->toBe($client);

    FirebaseMessagingFactory::flush();
    expect($factory->make($credentials))->not->toBe($client);
});

test('APNs clients are built from the app key', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privateKey);

    $client = (new ApnClientFactory())->make(new PushCredentials('push_app_1', 'Bayline Rides', 'apn', [
        'key_id'              => 'KEY123',
        'team_id'             => 'TEAM123',
        'app_bundle_id'       => 'com.operator.passenger',
        'private_key_content' => $privateKey,
    ]), 'sandbox');

    expect($client)->toBeInstanceOf(Pushok\Client::class);
});

test('APNs responses map to push outcomes', function ($status, $reason, $expected) {
    $response = new Pushok\Response($status, '', $reason ? json_encode(['reason' => $reason]) : '', 'token');

    expect(ApnTransport::outcomeFor($response)->status)->toBe($expected);
})->with([
    'sent'               => [200, null, PushOutcome::SENT],
    'unregistered'       => [410, 'Unregistered', PushOutcome::DEAD],
    'expired'            => [410, 'ExpiredToken', PushOutcome::DEAD],
    'bad device token'   => [400, 'BadDeviceToken', PushOutcome::WRONG_ENVIRONMENT],
    'wrong topic'        => [400, 'DeviceTokenNotForTopic', PushOutcome::WRONG_APP],
    'bad provider token' => [403, 'InvalidProviderToken', PushOutcome::ERROR],
]);

test('FCM send reports map to push outcomes', function ($error, $expected) {
    $target = MessageTarget::with(MessageTarget::TOKEN, 'token');
    $error  = $error instanceof Closure ? $error() : $error;
    $report = $error ? SendReport::failure($target, $error) : SendReport::success($target, ['name' => 'projects/x/messages/1']);

    expect(FcmTransport::outcomeFor($report)->status)->toBe($expected);
})->with([
    'sent'                 => [null, PushOutcome::SENT],
    'unregistered'         => [fn () => new NotFound('Requested entity was not found.'), PushOutcome::DEAD],
    'invalid token'        => [fn () => new InvalidMessage('The registration token is not a valid FCM registration token'), PushOutcome::DEAD],
    'invalid registration' => [fn () => new InvalidArgument('The registration token is not valid'), PushOutcome::DEAD],
    'sender mismatch'      => [fn () => new AuthenticationError('SenderId mismatch'), PushOutcome::WRONG_APP],
    'bad credentials'      => [fn () => new AuthenticationError('Request had invalid authentication credentials.'), PushOutcome::ERROR],
    'provider outage'      => [fn () => new ServerUnavailable('Service unavailable'), PushOutcome::ERROR],
]);

test('the FCM transport multicasts the rendered message and maps each token report', function () {
    $messaging = $this->createMock(Kreait\Firebase\Contract\Messaging::class);
    $messaging->expects($this->once())
        ->method('sendMulticast')
        ->with(
            $this->callback(fn ($message) => $message instanceof Kreait\Firebase\Messaging\CloudMessage
                && data_get($message->jsonSerialize(), 'android.notification.channel_id') === 'trips'),
            ['ok_token', 'dead_token']
        )
        ->willReturn(Kreait\Firebase\Messaging\MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with(MessageTarget::TOKEN, 'ok_token'), ['name' => 'projects/x/messages/1']),
            SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, 'dead_token'), new NotFound('Requested entity was not found.')),
        ]));
    $factory = new class($messaging) extends FirebaseMessagingFactory {
        public function __construct(public $messaging)
        {
        }

        public function make(PushCredentials $credentials): Kreait\Firebase\Contract\Messaging
        {
            return $this->messaging;
        }
    };
    $transport   = new FcmTransport($factory);
    $credentials = new PushCredentials('push_app_1', 'Bayline Rides', 'fcm', ['android_channel_id' => 'trips']);

    $outcomes = $transport->send($credentials, PushMessage::create('Driver arrived', 'Sam is waiting'), ['a' => 'ok_token', 'b' => 'dead_token']);

    expect(collect($outcomes)->map->status->all())->toBe(['ok_token' => PushOutcome::SENT, 'dead_token' => PushOutcome::DEAD])
        ->and($transport->send($credentials, PushMessage::create('Hi', 'There'), []))->toBe([]);
});

test('the APNs transport queues a high priority notification per token and maps each response', function () {
    $client = $this->createMock(Pushok\Client::class);
    $queued = [];
    $client->expects($this->exactly(3))
        ->method('addNotification')
        ->willReturnCallback(function (Pushok\Notification $notification) use (&$queued) {
            $queued[] = [$notification->getDeviceToken(), $notification->getPriority(), $notification->getCollapseId()];
        });
    $client->method('push')->willReturnOnConsecutiveCalls(
        [
            new Pushok\Response(200, '', '', 'ok_token'),
            new Pushok\Response(410, '', json_encode(['reason' => 'Unregistered']), 'gone_token'),
        ],
        [new Pushok\Response(200, '', '', 'plain_token')],
    );
    $factory = new class($client) extends ApnClientFactory {
        public ?string $environment = null;

        public function __construct(public $client)
        {
        }

        public function make(PushCredentials $credentials, string $environment): Pushok\Client
        {
            $this->environment = $environment;

            return $this->client;
        }
    };
    $transport   = new ApnTransport($factory);
    $credentials = new PushCredentials('push_app_1', 'Bayline Rides', 'apn', []);

    $outcomes = $transport->send($credentials, 'sandbox', PushMessage::create('Driver arrived', 'Sam is waiting')->collapseKey(str_repeat('k', 80)), ['ok_token', 'gone_token']);
    $plain    = $transport->send($credentials, 'production', PushMessage::create('Hi', 'There'), ['plain_token']);

    expect(collect($outcomes)->map->status->all())->toBe(['ok_token' => PushOutcome::SENT, 'gone_token' => PushOutcome::DEAD])
        ->and($plain['plain_token']->status)->toBe(PushOutcome::SENT)
        ->and($factory->environment)->toBe('production')
        ->and($queued)->toBe([
            ['ok_token', Pushok\Notification::PRIORITY_HIGH, str_repeat('k', 64)],
            ['gone_token', Pushok\Notification::PRIORITY_HIGH, str_repeat('k', 64)],
            ['plain_token', Pushok\Notification::PRIORITY_HIGH, null],
        ])
        ->and($transport->send($credentials, 'sandbox', PushMessage::create('Hi', 'There'), []))->toBe([]);
});

test('a push app and a device reach each other through their relations', function () {
    push_apps_database();
    $app    = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn()]);
    $device = UserDevice::create(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'bayline-ios', 'push_app_uuid' => $app->uuid]);

    expect($app->devices()->pluck('token')->all())->toBe(['bayline-ios'])
        ->and($device->pushApp?->uuid)->toBe($app->uuid)
        ->and($app->company())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('the channel reads devices from a plain devices relation, warns on undelivered pushes and stops once every token is sent', function () {
    push_apps_database();
    $app = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn(), 'fcm' => push_apps_fcm()]);

    $devicesOnly = new class(collect([(object) ['platform' => 'ios', 'token' => 'ios-ok', 'push_app_uuid' => $app->uuid, 'status' => 'active', 'environment' => null], (object) ['platform' => 'ios', 'token' => 'ios-wrong-app', 'push_app_uuid' => $app->uuid, 'status' => 'active', 'environment' => 'production'], (object) ['platform' => 'android', 'token' => 'android-dead', 'push_app_uuid' => $app->uuid, 'status' => 'active']])) {
        public function __construct(public $devices)
        {
        }

        public function devices()
        {
            return $this->devices;
        }
    };

    $apn           = new PushAppsFakeApnTransport();
    $apn->outcomes = ['production' => ['ios-wrong-app' => PushOutcome::wrongApp('DeviceTokenNotForTopic')]];
    $fcm           = new class extends PushAppsFakeFcmTransport {
        public function send(PushCredentials $channel, PushMessage $message, array $tokens): array
        {
            return array_fill_keys($tokens, PushOutcome::dead('Unregistered'));
        }
    };

    $outcomes = (new PushAppChannel($fcm, $apn))->send($devicesOnly, new PushAppsTestNotification());

    expect(array_map(fn ($outcome) => $outcome->status, $outcomes))->toBe(['ios-ok' => 'sent', 'ios-wrong-app' => 'wrong_app', 'android-dead' => 'dead'])
        ->and($apn->calls)->toBe([[$app->public_id, 'production', ['ios-ok']], [$app->public_id, 'production', ['ios-wrong-app']]])
        ->and(collect(app('log')->entries)->pluck(1)->all())->toContain('[Push apps] A push was not delivered.');
});

test('the channel survives a notification that fails, a device it cannot remove and a logger that throws', function () {
    push_apps_database();
    $app  = PushApp::create(['name' => 'Bayline Rides', 'company_uuid' => 'company-uuid', 'apn' => push_apps_apn()]);
    $dead = new class(['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'dead-ios', 'push_app_uuid' => $app->uuid, 'status' => 'active']) extends UserDevice {
        protected $table = 'user_devices';

        public function save(array $options = [])
        {
            throw new RuntimeException('database is read-only');
        }
    };
    $user = new User();
    $user->setRelation('devices', collect([$dead]));

    $failing = new class extends Notification implements SendsToPushApps {
        use PushesToApps;

        public function toPushApp($notifiable): ?PushMessage
        {
            throw new RuntimeException('cannot render');
        }
    };

    $apn           = new PushAppsFakeApnTransport();
    $apn->outcomes = ['production' => ['dead-ios' => PushOutcome::dead('Unregistered')]];
    $channel       = new PushAppChannel(new PushAppsFakeFcmTransport(), $apn);

    expect($channel->send($user, $failing))->toBe([])
        ->and(collect(app('log')->entries)->pluck(1)->all())->toContain('[Push apps] Unable to prepare the push.');

    expect($channel->send($user, new PushAppsTestNotification())['dead-ios']->status)->toBe(PushOutcome::DEAD)
        ->and(collect(app('log')->entries)->pluck(1)->all())->toContain('[Push apps] Unable to remove the device.');

    $logger = Illuminate\Support\Facades\Log::getFacadeRoot();
    Illuminate\Support\Facades\Log::swap(new class {
        public function __call($method, $arguments)
        {
            throw new RuntimeException('log is down');
        }
    });

    $fresh = new User();
    $fresh->setRelation('devices', collect([new UserDevice(['platform' => 'ios', 'token' => 'other-ios', 'push_app_uuid' => $app->uuid, 'status' => 'active'])]));

    try {
        expect($channel->send($fresh, $failing))->toBe([]);
    } finally {
        Illuminate\Support\Facades\Log::swap($logger);
    }
});
