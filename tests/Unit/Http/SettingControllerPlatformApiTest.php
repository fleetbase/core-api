<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Support\PlatformApi;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class SettingControllerPlatformApiCacheStore
{
    public array $values = [];

    public array $forgotten = [];

    public string $prefix = 'fleetbase_cache:';

    public function rememberForever(string $key, Closure $callback): mixed
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
        return $this->prefix;
    }

    public function tags(array $tags): SettingControllerPlatformApiTaggedCacheStore
    {
        return new SettingControllerPlatformApiTaggedCacheStore($this);
    }
}

class SettingControllerPlatformApiTaggedCacheStore
{
    public function __construct(private SettingControllerPlatformApiCacheStore $store)
    {
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function flush(): bool
    {
        return true;
    }
}

class SettingControllerPlatformApiRedisFake
{
    public array $patterns = [];

    public function __construct(private SettingControllerPlatformApiCacheStore $cache)
    {
    }

    public function connection(): self
    {
        return $this;
    }

    public function keys(string $pattern): array
    {
        $this->patterns[] = $pattern;

        return array_values(array_filter(array_map(
            fn (string $key) => $this->cache->getPrefix() . $key,
            array_keys($this->cache->values)
        ), fn (string $key) => fnmatch($pattern, $key)));
    }
}

class SettingControllerPlatformApiHashFake
{
    public function make(string $value): string
    {
        return 'hashed:' . $value;
    }

    public function check(string $value, string $hash): bool
    {
        return hash_equals('hashed:' . $value, $hash);
    }
}

function setting_controller_platform_api_fixtures(): SettingControllerPlatformApiCacheStore
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connectionConfig,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $container->instance('hash', new SettingControllerPlatformApiHashFake());
    Facade::clearResolvedInstance('hash');

    $cache = new SettingControllerPlatformApiCacheStore();
    $container->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');

    $container->instance('redis', new SettingControllerPlatformApiRedisFake($cache));
    Facade::clearResolvedInstance('redis');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });

    return $cache;
}

function setting_controller_platform_api_request(): AdminRequest
{
    return AdminRequest::create('/int/v1/settings/platform-api-token', 'POST');
}

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

test('platform api token status endpoint returns configured state without exposing a token', function () {
    setting_controller_platform_api_fixtures();

    $response = (new SettingController())->getPlatformApiToken(setting_controller_platform_api_request());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'configured'   => false,
            'rotated_at'   => null,
            'last_used_at' => null,
        ])
        ->and($response->getData(true))->not->toHaveKey('token');
});

test('rotate platform api token returns the one time token with updated status', function () {
    setting_controller_platform_api_fixtures();
    Carbon::setTestNow(Carbon::parse('2026-07-18 01:30:00'));

    $response = (new SettingController())->rotatePlatformApiToken(setting_controller_platform_api_request());
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['token'])->toStartWith('flb_platform_')
        ->and(strlen($payload['token']))->toBe(strlen('flb_platform_') + 64)
        ->and($payload)->toMatchArray([
            'configured'   => true,
            'rotated_at'   => '2026-07-18T01:30:00.000000Z',
            'last_used_at' => null,
        ])
        ->and(PlatformApi::tokenHash())->toBe('hashed:' . $payload['token'])
        ->and(PlatformApi::validateToken($payload['token']))->toBeTrue();
});

test('revoke platform api token clears token settings and does not expose the old token', function () {
    setting_controller_platform_api_fixtures();
    Carbon::setTestNow(Carbon::parse('2026-07-18 02:00:00'));

    $rotateResponse = (new SettingController())->rotatePlatformApiToken(setting_controller_platform_api_request());
    $token          = $rotateResponse->getData(true)['token'];

    $response = (new SettingController())->revokePlatformApiToken(setting_controller_platform_api_request());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'configured'   => false,
            'rotated_at'   => null,
            'last_used_at' => null,
        ])
        ->and($response->getData(true))->not->toHaveKey('token')
        ->and(PlatformApi::tokenHash())->toBeNull()
        ->and(PlatformApi::validateToken($token))->toBeFalse();
});
