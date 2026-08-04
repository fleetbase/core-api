<?php

use Fleetbase\Support\EnvironmentMapper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

if (!function_exists('cache')) {
    function cache(mixed $key = null, mixed $default = null): mixed
    {
        $cache = app('cache');

        if ($key === null) {
            return $cache;
        }

        return $cache->get($key, $default);
    }
}

class EnvironmentMapperCacheFake
{
    public array $values    = [];
    public array $forgotten = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
        unset($this->values[$key]);

        return true;
    }

    public function getPrefix(): string
    {
        return 'fleetbase_cache:';
    }
}

class EnvironmentMapperRedisFake
{
    public function connection(): self
    {
        return $this;
    }

    public function keys(string $pattern): array
    {
        return [];
    }
}

function environment_mapper_fixtures(bool $createSettingsTable = true): void
{
    $container = bind_test_container([
        'app.timezone'         => 'UTC',
        'filesystems.default'  => 'local',
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key'    => 'existing-key',
            'region' => 'us-east-1',
            'bucket' => 'existing-bucket',
        ],
        'mail.default'      => 'log',
        'mail.from.address' => null,
        'mail.from.name'    => 'Fleetbase',
        'mail.mailers.smtp' => [
            'transport' => 'smtp',
            'host'      => 'localhost',
            'port'      => 1025,
        ],
        'queue.default'         => 'sync',
        'queue.connections.sqs' => [
            'driver' => 'sqs',
            'key'    => 'existing-queue-key',
            'secret' => 'existing-queue-secret',
            'region' => 'us-east-1',
        ],
        'services.aws' => [
            'key'    => 'existing-service-key',
            'secret' => 'existing-service-secret',
            'region' => 'us-east-1',
        ],
        'services.sms.providers.vonage' => [
            'api_key'    => 'existing-vonage-key',
            'api_secret' => 'existing-vonage-secret',
            'from'       => 'Fleetbase',
        ],
        'sms.default_provider' => 'twilio',
    ]);

    $container->instance('cache', new EnvironmentMapperCacheFake());
    $container->instance('redis', new EnvironmentMapperRedisFake());
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('redis');

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    config([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connectionConfig,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');

    if ($createSettingsTable) {
        $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
        $schema->dropIfExists('settings');
        $schema->create('settings', function ($table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });
    }
}

function environment_mapper_insert_settings(array $settings): void
{
    foreach ($settings as $key => $value) {
        app('db')->table('settings')->insert([
            'key'   => $key,
            'value' => is_array($value) ? json_encode($value) : $value,
        ]);
    }
}

beforeEach(function () {
    foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'VONAGE_API_KEY'] as $name) {
        putenv($name);
    }
});

afterEach(function () {
    foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'VONAGE_API_KEY'] as $name) {
        putenv($name);
    }

    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('environment mapper skips config mutation when settings table is unavailable', function () {
    environment_mapper_fixtures(createSettingsTable: false);

    config([
        'filesystems.default' => 'local',
        'mail.from.address'   => null,
    ]);

    EnvironmentMapper::mergeConfigFromSettingsOptimized();

    expect(config('filesystems.default'))->toBe('local')
        ->and(config('mail.from.address'))->toBeNull();
});

test('environment mapper merges database settings into config and preserves existing array values', function () {
    environment_mapper_fixtures();
    environment_mapper_insert_settings([
        'system.filesystem.driver' => 's3',
        'system.filesystem.s3'     => [
            'key'        => 'setting-s3-key',
            'bucket'     => 'setting-bucket',
            'visibility' => 'private',
            'secret'     => '',
        ],
        'system.mail.mailers.smtp' => [
            'host' => 'smtp.example.com',
            'port' => 2525,
        ],
        'system.services.aws' => [
            'key'    => 'setting-aws-key',
            'secret' => 'setting-aws-secret',
            'region' => 'ap-southeast-1',
        ],
        'system.sms.default_provider'   => 'vonage',
        'system.services.sms.providers' => [
            'vonage' => [
                'api_key' => 'vonage-key',
                'from'    => 'Fleetbase',
            ],
        ],
        'system.services.sms.providers.vonage.api_key' => 'vonage-key',
    ]);

    EnvironmentMapper::mergeConfigFromSettingsOptimized();

    expect(config('filesystems.default'))->toBe('s3')
        ->and(config('filesystems.disks.s3'))->toMatchArray([
            'driver'     => 's3',
            'key'        => 'setting-aws-key',
            'region'     => 'ap-southeast-1',
            'bucket'     => 'setting-bucket',
            'visibility' => 'private',
        ])
        ->and(config('filesystems.disks.s3.secret'))->toBe('setting-aws-secret')
        ->and(config('mail.mailers.smtp'))->toMatchArray([
            'transport' => 'smtp',
            'host'      => 'smtp.example.com',
            'port'      => 2525,
        ])
        ->and(config('queue.connections.sqs'))->toMatchArray([
            'driver' => 'sqs',
            'key'    => 'setting-aws-key',
            'secret' => 'setting-aws-secret',
            'region' => 'ap-southeast-1',
        ])
        ->and(config('sms.default_provider'))->toBe('vonage')
        ->and(config('sms.providers.vonage.api_key'))->toBe('vonage-key')
        ->and(config('mail.from.address'))->toContain('@');
});

test('environment mapper sets missing environment variables from nested settings without overriding existing ones', function () {
    environment_mapper_fixtures();
    putenv('AWS_SECRET_ACCESS_KEY=already-set');
    environment_mapper_insert_settings([
        'system.services.aws' => [
            'key'    => 'env-aws-key',
            'secret' => 'env-aws-secret',
            'region' => 'eu-west-1',
        ],
        'system.services.sms.providers.vonage.api_key' => 'env-vonage-key',
    ]);

    EnvironmentMapper::mergeConfigFromSettingsOptimized();

    expect(getenv('AWS_ACCESS_KEY_ID'))->toBe('"env-aws-key"')
        ->and(getenv('AWS_SECRET_ACCESS_KEY'))->toBe('already-set')
        ->and(getenv('AWS_DEFAULT_REGION'))->toBe('"eu-west-1"')
        ->and(getenv('VONAGE_API_KEY'))->toBe('"env-vonage-key"');
});
