<?php

use Fleetbase\Http\Middleware\AuthenticateApiOrPlatformToken;
use Fleetbase\Http\Middleware\AuthenticateOnceWithBasicAuth;
use Fleetbase\Http\Middleware\AuthenticatePlatformApiToken;
use Fleetbase\Support\PlatformApi;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

if (!function_exists('cache')) {
    function cache($key = null, $default = null)
    {
        $cache = app('cache');

        if ($key === null) {
            return $cache;
        }

        return $cache->get($key, $default);
    }
}

class PlatformApiCacheStore
{
    public array $values    = [];
    public array $forgotten = [];
    public string $prefix   = 'fleetbase_cache:';

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

    public function increment(string $key): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;

        return $this->values[$key];
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function tags(array $tags): PlatformApiTaggedCacheStore
    {
        return new PlatformApiTaggedCacheStore($this, $tags);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}

class PlatformApiTaggedCacheStore
{
    public function __construct(private PlatformApiCacheStore $store, private array $tags)
    {
    }

    public function flush(): bool
    {
        return true;
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }
}

class PlatformApiRedisFake
{
    public array $patterns = [];

    public function __construct(private PlatformApiCacheStore $cache)
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

class PlatformApiHashFake
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

function platform_api_fixture(): PlatformApiCacheStore
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

    $container->instance('hash', new PlatformApiHashFake());
    Facade::clearResolvedInstance('hash');

    $cache = new PlatformApiCacheStore();
    $container->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');

    $container->instance('redis', new PlatformApiRedisFake($cache));
    Facade::clearResolvedInstance('redis');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });

    return $cache;
}

test('platform api rotates validates reports and revokes platform tokens', function () {
    $cache = platform_api_fixture();
    Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

    expect(PlatformApi::isConfigured())->toBeFalse()
        ->and(PlatformApi::status())->toBe([
            'configured'   => false,
            'rotated_at'   => null,
            'last_used_at' => null,
        ]);

    $token = PlatformApi::rotateToken();

    expect($token)->toStartWith('flb_platform_')
        ->and(strlen($token))->toBe(strlen('flb_platform_') + 64)
        ->and(PlatformApi::isConfigured())->toBeTrue()
        ->and(PlatformApi::tokenHash())->toBe('hashed:' . $token)
        ->and(PlatformApi::validateToken($token))->toBeTrue()
        ->and(PlatformApi::validateToken('wrong-token'))->toBeFalse()
        ->and(PlatformApi::validateToken(''))->toBeFalse()
        ->and(PlatformApi::validateToken(null))->toBeFalse()
        ->and(PlatformApi::status())->toBe([
            'configured'   => true,
            'rotated_at'   => '2026-07-17T10:00:00.000000Z',
            'last_used_at' => null,
        ]);

    Carbon::setTestNow(Carbon::parse('2026-07-17 10:05:00'));
    PlatformApi::markUsed();

    expect(PlatformApi::status()['last_used_at'])->toBe('2026-07-17T10:05:00.000000Z')
        ->and($cache->forgotten)->toContain('system_settings.system.platform_api.token_last_used_at');

    PlatformApi::revokeToken();

    expect(PlatformApi::isConfigured())->toBeFalse()
        ->and(PlatformApi::tokenHash())->toBeNull()
        ->and(PlatformApi::validateToken($token))->toBeFalse()
        ->and(PlatformApi::status())->toBe([
            'configured'   => false,
            'rotated_at'   => null,
            'last_used_at' => null,
        ]);

    Carbon::setTestNow();
});

test('platform api middleware rejects missing invalid tokens and marks valid tokens as used', function () {
    platform_api_fixture();
    Carbon::setTestNow(Carbon::parse('2026-07-17 11:00:00'));
    $token      = PlatformApi::rotateToken();
    $middleware = new AuthenticatePlatformApiToken();

    $missing = $middleware->handle(Request::create('/platform/orders'), fn () => new JsonResponse(['ok' => true]));
    expect($missing->getStatusCode())->toBe(401)
        ->and($missing->getData(true)['errors'])->toBe(['Invalid platform API token.']);

    $invalidRequest = Request::create('/platform/orders');
    $invalidRequest->headers->set('Authorization', 'Bearer wrong-token');
    $invalid = $middleware->handle($invalidRequest, fn () => new JsonResponse(['ok' => true]));

    expect($invalid->getStatusCode())->toBe(401)
        ->and($invalid->getData(true)['errors'])->toBe(['Invalid platform API token.']);

    Carbon::setTestNow(Carbon::parse('2026-07-17 11:15:00'));
    $validRequest = Request::create('/platform/orders');
    $validRequest->headers->set('Authorization', 'Bearer ' . $token);
    $valid = $middleware->handle($validRequest, fn () => new JsonResponse(['ok' => true]));

    expect($valid->getStatusCode())->toBe(200)
        ->and($valid->getData(true))->toBe(['ok' => true])
        ->and(PlatformApi::status()['last_used_at'])->toBe('2026-07-17T11:15:00.000000Z');

    Carbon::setTestNow();
});

test('platform api writes a use down at most once per interval', function () {
    $cache = platform_api_fixture();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));
    PlatformApi::rotateToken();

    PlatformApi::markUsed();
    expect(PlatformApi::status()['last_used_at'])->toBe('2026-07-17T12:00:00.000000Z');

    // Within the interval nothing is written, so the settings cache is left alone.
    $cache->forgotten = [];
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:04:59'));
    PlatformApi::markUsed();
    expect(PlatformApi::status()['last_used_at'])->toBe('2026-07-17T12:00:00.000000Z')
        ->and($cache->forgotten)->toBe([]);

    Carbon::setTestNow(Carbon::parse('2026-07-17 12:05:00'));
    PlatformApi::markUsed();
    expect(PlatformApi::status()['last_used_at'])->toBe('2026-07-17T12:05:00.000000Z');

    Carbon::setTestNow();
});

test('platform api recognises its own tokens and the requests they authenticated', function () {
    platform_api_fixture();
    $request = Request::create('/v1/drivers/login', 'POST');

    expect(PlatformApi::isPlatformToken(PlatformApi::generateToken()))->toBeTrue()
        ->and(PlatformApi::isPlatformToken('flb_platform_'))->toBeTrue()
        ->and(PlatformApi::isPlatformToken('flb_live_abc'))->toBeFalse()
        ->and(PlatformApi::isPlatformToken('12|plaintext'))->toBeFalse()
        ->and(PlatformApi::isPlatformToken(null))->toBeFalse()
        ->and(PlatformApi::authenticated($request))->toBeFalse();

    PlatformApi::markAuthenticated($request);

    expect(PlatformApi::authenticated($request))->toBeTrue()
        ->and(PlatformApi::authenticated(Request::create('/v1/drivers/login', 'POST')))->toBeFalse();
});

test('api or platform middleware accepts the platform token and hands every other credential to api authentication', function () {
    platform_api_fixture();
    Carbon::setTestNow(Carbon::parse('2026-07-17 13:00:00'));
    $token      = PlatformApi::rotateToken();
    $middleware = new AuthenticateApiOrPlatformToken();
    $send       = function (?string $bearer) use ($middleware) {
        $request = Request::create('/v1/drivers/login', 'POST');
        if ($bearer !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $bearer);
        }

        return [$middleware->handle($request, fn () => new JsonResponse(['ok' => true])), $request];
    };

    // Everything that isn't a platform token is the API middleware's to judge.
    $delegated = [];
    app()->instance(AuthenticateOnceWithBasicAuth::class, new class($delegated) {
        public function __construct(private array &$seen)
        {
        }

        public function handle($request, Closure $next)
        {
            $this->seen[] = $request->bearerToken();

            return new JsonResponse(['delegated' => true]);
        }
    });

    [$valid, $validRequest] = $send($token);
    expect($valid->getData(true))->toBe(['ok' => true])
        ->and(PlatformApi::authenticated($validRequest))->toBeTrue()
        ->and(PlatformApi::status()['last_used_at'])->toBe('2026-07-17T13:00:00.000000Z');

    // A platform-shaped token that is wrong is refused here, never tried as an API key.
    [$wrong, $wrongRequest] = $send('flb_platform_wrong');
    expect($wrong->getStatusCode())->toBe(401)
        ->and($wrong->getData(true)['errors'])->toBe(['Invalid platform API token.'])
        ->and(PlatformApi::authenticated($wrongRequest))->toBeFalse();

    [$apiKey, $apiKeyRequest] = $send('flb_live_key');
    [$none]                   = $send(null);
    expect($apiKey->getData(true))->toBe(['delegated' => true])
        ->and($none->getData(true))->toBe(['delegated' => true])
        ->and(PlatformApi::authenticated($apiKeyRequest))->toBeFalse()
        ->and($delegated)->toBe(['flb_live_key', null]);

    Carbon::setTestNow();
});
