<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class SettingControllerSaveConfigCacheFake
{
    public array $forgotten   = [];
    public array $flushedTags = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return true;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $callback();
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;

        return true;
    }

    public function getPrefix(): string
    {
        return 'fleetbase_cache:';
    }

    public function increment(string $key, int $value = 1): int
    {
        return $value;
    }

    public function tags(array $tags): self
    {
        $this->flushedTags[] = $tags;

        return $this;
    }

    public function flush(): bool
    {
        return true;
    }
}

class SettingControllerSaveConfigRedisFake
{
    public function connection(): self
    {
        return $this;
    }

    public function keys(string $pattern): array
    {
        return ['fleetbase_cache:system_settings.system.previous'];
    }
}

class SettingControllerSaveConfigArtisanFake implements ConsoleKernel
{
    public array $calls = [];

    public function bootstrap(): void
    {
    }

    public function handle($input, $output = null): int
    {
        return 0;
    }

    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->calls[] = [$command, $parameters];

        return 0;
    }

    public function queue($command, array $parameters = []): mixed
    {
        return null;
    }

    public function all(): array
    {
        return [];
    }

    public function output(): string
    {
        return '';
    }

    public function terminate($input, $status): void
    {
    }
}

class SettingControllerSaveConfigFilesystemFake
{
    public array $disks = [];

    public function disk(string $name): SettingControllerSaveConfigDiskFake
    {
        return $this->disks[$name] ??= new SettingControllerSaveConfigDiskFake();
    }
}

class SettingControllerSaveConfigDiskFake
{
    public array $files = [];

    public function get(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }
}

function setting_controller_save_config_database(array $config = []): array
{
    EloquentModel::clearBootedModels();

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container(array_replace_recursive([
        'database.default'                 => 'mysql',
        'database.connections.mysql'       => $connection,
        'fleetbase.connection.db'          => 'mysql',
        'filesystems.default'              => 'local',
        'filesystems.disks.local'          => ['driver' => 'local'],
        'filesystems.disks.s3'             => [
            'driver'   => 's3',
            'bucket'   => 'existing-bucket',
            'url'      => 'https://existing-cdn.example.test',
            'endpoint' => 'https://existing-s3.example.test',
        ],
        'filesystems.disks.gcs'            => [
            'driver'      => 'gcs',
            'bucket'      => 'existing-gcs',
            'key_file_id' => 'old-file-id',
            'key_file'    => ['old' => true],
            'project_id'  => 'old-project',
        ],
        'mail.mailers.smtp'                => ['transport' => 'smtp', 'host' => 'smtp.current.test'],
        'mail.mailers.microsoft-graph'     => ['transport' => 'microsoft-graph', 'tenant' => 'current-tenant'],
        'services.mailgun'                 => ['domain' => 'current.mailgun.test'],
        'services.postmark'                => ['token' => 'current-postmark'],
        'services.sendgrid'                => ['key' => 'current-sendgrid'],
        'services.resend'                  => ['key' => 'current-resend'],
        'queue.connections.sqs'            => ['driver' => 'sqs', 'queue' => 'current-sqs'],
        'queue.connections.beanstalkd'     => ['driver' => 'beanstalkd', 'queue' => 'current-beanstalkd'],
        'services.aws'                     => ['key' => 'current-aws-key'],
        'services.ipinfo'                  => ['api_key' => 'current-ipinfo-key'],
        'services.google_maps'             => ['api_key' => 'current-google-key'],
        'services.twilio'                  => ['sid' => 'current-twilio-sid'],
        'services.sms'                     => ['providers' => ['twilio' => ['sid' => 'current-twilio-sid']]],
        'sms.default_provider'             => 'twilio',
        'sms.routing_rules'                => [],
        'sentry'                           => ['dsn' => 'https://current-sentry.example.test/1'],
        'broadcasting.connections.apn'     => ['key_id' => 'current-apn-key', 'private_key_path' => '/tmp/key.p8'],
        'firebase.projects.app'            => ['project_id' => 'current-firebase', 'credentials_file' => '/tmp/firebase.json'],
    ], $config));

    $cache      = new SettingControllerSaveConfigCacheFake();
    $redis      = new SettingControllerSaveConfigRedisFake();
    $artisan    = new SettingControllerSaveConfigArtisanFake();
    $filesystem = new SettingControllerSaveConfigFilesystemFake();

    $container->instance('cache', $cache);
    $container->instance('redis', $redis);
    $container->instance(ConsoleKernel::class, $artisan);
    $container->instance('filesystem', $filesystem);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('disk')->nullable();
        $table->string('path')->nullable();
        $table->softDeletes();
    });

    return [$capsule, $cache, $artisan, $filesystem];
}

function setting_controller_save_config_request(string $path, array $input = []): AdminRequest
{
    return AdminRequest::create($path, 'POST', $input);
}

function setting_controller_saved_value(string $key): mixed
{
    $value = Setting::query()->where('key', $key)->value('value');

    if (!is_string($value)) {
        return $value;
    }

    $decoded = json_decode($value, true);

    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function setting_controller_artisan_commands(SettingControllerSaveConfigArtisanFake $artisan): array
{
    return array_column($artisan->calls, 0);
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('save filesystem config persists merged disk settings and resolves gcs credential files', function () {
    [$capsule, $cache, $artisan, $filesystem] = setting_controller_save_config_database();
    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'disk' => 'local',
        'path' => 'gcs-service-account.json',
    ]);
    $filesystem->disk('local')->files['gcs-service-account.json'] = json_encode([
        'type'       => 'service_account',
        'project_id' => 'fleetbase-gcs-project',
    ]);

    $response = (new SettingController())->saveFilesystemConfig(setting_controller_save_config_request('/int/v1/settings/filesystem', [
        'driver'               => 'gcs',
        's3'                   => [
            'bucket' => 'new-bucket',
        ],
        'gcsBucket'            => 'new-gcs-bucket',
        'gcsCredentialsFileId' => '11111111-1111-4111-8111-111111111111',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'OK'])
        ->and(setting_controller_saved_value('system.filesystem.driver'))->toBe('gcs')
        ->and(setting_controller_saved_value('system.filesystem.s3'))->toMatchArray([
            'driver'   => 's3',
            'bucket'   => 'new-bucket',
            'url'      => 'https://existing-cdn.example.test',
            'endpoint' => 'https://existing-s3.example.test',
        ])
        ->and(setting_controller_saved_value('system.filesystem.gcs'))->toMatchArray([
            'driver'      => 'gcs',
            'bucket'      => 'new-gcs-bucket',
            'key_file_id' => '11111111-1111-4111-8111-111111111111',
            'key_file'    => [
                'type'       => 'service_account',
                'project_id' => 'fleetbase-gcs-project',
            ],
            'project_id'  => 'fleetbase-gcs-project',
        ])
        ->and(setting_controller_artisan_commands($artisan))->toBe(['config:clear', 'config:cache'])
        ->and($cache->forgotten)->toContain('system_settings.system.previous');
});

test('save mail config persists provider settings and refreshes config cache', function () {
    [, , $artisan] = setting_controller_save_config_database();

    $response = (new SettingController())->saveMailConfig(setting_controller_save_config_request('/int/v1/settings/mail', [
        'mailer'         => 'mailgun',
        'from'           => ['address' => 'ops@example.test', 'name' => 'Ops'],
        'smtp'           => ['host' => 'smtp.saved.test', 'port' => 2525],
        'microsoftGraph' => ['tenant' => 'saved-tenant'],
        'mailgun'        => ['domain' => 'saved.mailgun.test', 'secret' => 'saved-secret'],
        'postmark'       => ['token' => 'saved-postmark'],
        'sendgrid'       => ['key' => 'saved-sendgrid'],
        'resend'         => ['key' => 'saved-resend'],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'OK'])
        ->and(setting_controller_saved_value('system.mail.mailer'))->toBe('mailgun')
        ->and(setting_controller_saved_value('system.mail.from'))->toBe(['address' => 'ops@example.test', 'name' => 'Ops'])
        ->and(setting_controller_saved_value('system.mail.mailers.smtp'))->toBe(['transport' => 'smtp', 'host' => 'smtp.saved.test', 'port' => 2525])
        ->and(setting_controller_saved_value('system.mail.mailers.microsoft-graph'))->toBe(['transport' => 'microsoft-graph', 'tenant' => 'saved-tenant'])
        ->and(setting_controller_saved_value('system.services.mailgun'))->toBe(['domain' => 'saved.mailgun.test', 'secret' => 'saved-secret'])
        ->and(setting_controller_saved_value('system.services.postmark'))->toBe(['token' => 'saved-postmark'])
        ->and(setting_controller_saved_value('system.services.sendgrid'))->toBe(['key' => 'saved-sendgrid'])
        ->and(setting_controller_saved_value('system.services.resend'))->toBe(['key' => 'saved-resend'])
        ->and(setting_controller_artisan_commands($artisan))->toBe(['config:clear', 'config:cache']);
});

test('save queue config persists merged queue connection settings', function () {
    [, , $artisan] = setting_controller_save_config_database();

    $response = (new SettingController())->saveQueueConfig(setting_controller_save_config_request('/int/v1/settings/queue', [
        'driver'     => 'sqs',
        'sqs'        => ['queue' => 'saved-sqs', 'suffix' => '-blue'],
        'beanstalkd' => ['queue' => 'saved-beanstalkd'],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'OK'])
        ->and(setting_controller_saved_value('system.queue.driver'))->toBe('sqs')
        ->and(setting_controller_saved_value('system.queue.sqs'))->toBe(['driver' => 'sqs', 'queue' => 'saved-sqs', 'suffix' => '-blue'])
        ->and(setting_controller_saved_value('system.queue.beanstalkd'))->toBe(['driver' => 'beanstalkd', 'queue' => 'saved-beanstalkd'])
        ->and(setting_controller_artisan_commands($artisan))->toBe(['config:clear', 'config:cache']);
});

test('save services config persists provider credentials sms routing and sentry settings', function () {
    [, , $artisan] = setting_controller_save_config_database();

    $response = (new SettingController())->saveServicesConfig(setting_controller_save_config_request('/int/v1/settings/services', [
        'aws'        => ['secret' => 'saved-aws-secret'],
        'ipinfo'     => ['api_key' => 'saved-ipinfo-key'],
        'googleMaps' => ['api_key' => 'saved-google-key', 'locale' => 'mn'],
        'twilio'     => ['sid' => 'saved-twilio-sid', 'token' => 'saved-twilio-token'],
        'sms'        => [
            'providers'       => ['callpro' => ['api_key' => 'saved-callpro-key']],
            'defaultProvider' => 'callpro',
            'routingRules'    => ['+976' => 'callpro'],
        ],
        'sentry'     => ['dsn' => 'https://saved-sentry.example.test/1'],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'OK'])
        ->and(setting_controller_saved_value('system.services.aws'))->toBe(['key' => 'current-aws-key', 'secret' => 'saved-aws-secret'])
        ->and(setting_controller_saved_value('system.services.ipinfo'))->toBe(['api_key' => 'saved-ipinfo-key'])
        ->and(setting_controller_saved_value('system.services.google_maps'))->toBe(['api_key' => 'saved-google-key', 'locale' => 'mn'])
        ->and(setting_controller_saved_value('system.services.twilio'))->toBe(['sid' => 'saved-twilio-sid', 'token' => 'saved-twilio-token'])
        ->and(setting_controller_saved_value('system.services.sms'))->toBe([
            'providers' => ['callpro' => ['api_key' => 'saved-callpro-key']],
        ])
        ->and(setting_controller_saved_value('system.sms.default_provider'))->toBe('callpro')
        ->and(setting_controller_saved_value('system.sms.routing_rules'))->toBe(['+976' => 'callpro'])
        ->and(setting_controller_saved_value('system.services.sentry'))->toBe(['dsn' => 'https://saved-sentry.example.test/1'])
        ->and(setting_controller_artisan_commands($artisan))->toBe(['config:clear', 'config:cache']);
});

test('save notification channel config strips file-only keys and persists channel settings', function () {
    [, , $artisan] = setting_controller_save_config_database();

    $response = (new SettingController())->saveNotificationChannelsConfig(setting_controller_save_config_request('/int/v1/settings/notification-channels', [
        'apn'      => [
            'key_id'              => 'saved-apn-key',
            'team_id'             => 'saved-team',
            'private_key_path'    => '/tmp/should-not-persist.p8',
            'private_key_file'    => ['name' => 'should-not-persist'],
            'private_key_file_id' => 'not-a-uuid',
        ],
        'firebase' => [
            'project_id'          => 'saved-firebase',
            'credentials_file'    => '/tmp/should-not-persist.json',
            'credentials_file_id' => 'not-a-uuid',
        ],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'OK'])
        ->and(setting_controller_saved_value('system.broadcasting.apn'))->toMatchArray([
            'key_id'              => 'saved-apn-key',
            'private_key_file_id' => 'not-a-uuid',
            'team_id'             => 'saved-team',
        ])
        ->and(setting_controller_saved_value('system.broadcasting.apn'))->not->toHaveKeys(['private_key_path', 'private_key_file'])
        ->and(setting_controller_saved_value('system.firebase.app'))->toMatchArray([
            'project_id'          => 'saved-firebase',
            'credentials_file_id' => 'not-a-uuid',
        ])
        ->and(setting_controller_saved_value('system.firebase.app'))->not->toHaveKey('credentials_file')
        ->and(setting_controller_artisan_commands($artisan))->toBe(['config:clear', 'config:cache']);
});
