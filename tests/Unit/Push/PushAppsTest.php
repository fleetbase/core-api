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
    return array_replace(['key_id' => 'KEY123', 'team_id' => 'TEAM123', 'app_bundle_id' => 'com.operator.passenger', 'private_key_content' => "-----BEGIN PRIVATE KEY-----\\nabc\\n-----END PRIVATE KEY-----"], $overrides);
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
    public array $calls = [];
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
    $app = new PushApp();
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
