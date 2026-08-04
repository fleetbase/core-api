<?php

use Fleetbase\Models\User;
use Fleetbase\Services\UserCacheService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

class UserCacheServiceCacheFake
{
    public array $values       = [];
    public array $putCalls     = [];
    public array $forgotten    = [];
    public array $redisKeys    = [];
    public bool $throwOnGet    = false;
    public bool $throwOnPut    = false;
    public bool $throwOnForget = false;
    public bool $throwOnKeys   = false;

    public function get(string $key): mixed
    {
        if ($this->throwOnGet) {
            throw new RuntimeException('cache get failed');
        }

        return $this->values[$key] ?? null;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        if ($this->throwOnPut) {
            throw new RuntimeException('cache put failed');
        }

        $this->values[$key] = $value;
        $this->putCalls[]   = compact('key', 'value', 'ttl');

        return true;
    }

    public function forget(string $key): bool
    {
        if ($this->throwOnForget) {
            throw new RuntimeException('cache forget failed');
        }

        $this->forgotten[] = $key;
        unset($this->values[$key]);

        return true;
    }

    public function getRedis(): self
    {
        return $this;
    }

    public function keys(string $pattern): array
    {
        if ($this->throwOnKeys) {
            throw new RuntimeException('redis keys failed');
        }

        return $this->redisKeys[$pattern] ?? [];
    }
}

class UserCacheServiceLogFake
{
    public array $entries = [];

    public function debug(string $message, array $context = []): void
    {
        $this->entries[] = ['debug', $message, $context];
    }

    public function info(string $message, array $context = []): void
    {
        $this->entries[] = ['info', $message, $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['warning', $message, $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['error', $message, $context];
    }
}

class UserCacheServiceUser extends User
{
}

function user_cache_service_fixtures(array $config = []): array
{
    $container = bind_test_container(array_merge([
        'database.redis.options.prefix' => 'fleetbase_cache:',
        'fleetbase.user_cache.enabled'  => true,
    ], $config));

    $cache = new UserCacheServiceCacheFake();
    $log   = new UserCacheServiceLogFake();
    $container->instance('cache', $cache);
    $container->instance('log', $log);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('log');

    session()->flush();

    user_cache_service_database();

    $user = new UserCacheServiceUser([
        'uuid'    => 'user-1',
        'meta'    => ['theme' => 'dark'],
        'options' => ['dense' => true],
    ]);
    $user->id         = 99;
    $user->updated_at = Carbon::parse('2026-07-17 10:00:00');

    return [$user, $cache, $log];
}

function user_cache_service_database(): void
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = app();
    config([
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

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->integer('id')->nullable();
        $table->string('uuid')->primary();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->timestamp('deleted_at')->nullable();
    });

    $connection = $capsule->getConnection('mysql');
    $connection->table('users')->insert([
        'id'         => 99,
        'uuid'       => 'user-1',
        'updated_at' => '2026-07-17 10:00:00',
    ]);
    $connection->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Company One'],
        ['uuid' => 'company-2', 'name' => 'Company Two'],
    ]);
    $connection->table('company_users')->insert([
        ['uuid' => 'company-user-1', 'company_uuid' => 'user-1', 'user_uuid' => 'company-1'],
        ['uuid' => 'company-user-2', 'company_uuid' => 'user-1', 'user_uuid' => 'company-2'],
    ]);
}

test('user cache service builds keys etags and ttl configuration from user state', function () {
    [$user] = user_cache_service_fixtures([
        'fleetbase.user_cache.browser_ttl' => 123,
        'fleetbase.user_cache.server_ttl'  => 456,
        'fleetbase.user_cache.enabled'     => false,
    ]);

    expect(UserCacheService::getCacheKey($user, 'company-1'))->toBe('user:current:user-1:company-1:1784282400')
        ->and(UserCacheService::generateETag($user))->toBe('"user-user-1-1784282400-16-14"')
        ->and(UserCacheService::getBrowserCacheTTL())->toBe(123)
        ->and(UserCacheService::getServerCacheTTL())->toBe(456)
        ->and(UserCacheService::isEnabled())->toBeFalse();
});

test('user cache service stores retrieves invalidates and logs current user payloads', function () {
    [$user, $cache, $log] = user_cache_service_fixtures();

    $payload = ['uuid' => 'user-1', 'company_uuid' => 'company-1'];

    expect(UserCacheService::put($user, 'company-1', $payload, 60))->toBeTrue();

    $cacheKey = UserCacheService::getCacheKey($user, 'company-1');

    expect($cache->putCalls[0])->toBe([
        'key'   => $cacheKey,
        'value' => $payload,
        'ttl'   => 60,
    ])
        ->and(UserCacheService::get($user, 'company-1'))->toBe($payload);

    UserCacheService::invalidate($user, 'company-1');

    expect($cache->forgotten)->toContain($cacheKey)
        ->and($log->entries)->toContain(['debug', 'User cache stored', [
            'user_id'    => 'user-1',
            'company_id' => 'company-1',
            'cache_key'  => $cacheKey,
            'ttl'        => 60,
        ]]);
});

test('user cache service handles cache failures without leaking exceptions', function () {
    [$user, $cache, $log] = user_cache_service_fixtures();

    $cache->throwOnGet = true;
    expect(UserCacheService::get($user, 'company-1'))->toBeNull();

    $cache->throwOnPut = true;
    expect(UserCacheService::put($user, 'company-1', ['uuid' => 'user-1']))->toBeFalse();

    expect($log->entries[0][0])->toBe('error')
        ->and($log->entries[0][1])->toBe('Failed to get user cache')
        ->and($log->entries[1][0])->toBe('error')
        ->and($log->entries[1][1])->toBe('Failed to store user cache');
});

test('user cache service handles user invalidation failures and clears redis prefixed keys', function () {
    [$user, $cache, $log] = user_cache_service_fixtures();
    session(['company' => 'company-3']);
    $cache->throwOnForget = true;

    UserCacheService::invalidateUser($user);

    expect(collect($log->entries)->contains(fn ($entry) => $entry[0] === 'error' && $entry[1] === 'Failed to invalidate user cache'))->toBeTrue();

    $cache->throwOnForget                         = false;
    $cache->forgotten                             = [];
    $cache->redisKeys['user:current:*:company-1'] = [
        'fleetbase_cache:user:current:user-1:company-1:1784282400',
        'fleetbase_cache:user:current:user-2:company-1:1784282400',
    ];

    UserCacheService::invalidateCompany('company-1');

    expect($cache->forgotten)->toBe([
        'user:current:user-1:company-1:1784282400',
        'user:current:user-2:company-1:1784282400',
    ]);

    $cache->forgotten                   = [];
    $cache->redisKeys['user:current:*'] = [
        'fleetbase_cache:user:current:user-1:company-1:1784282400',
    ];

    UserCacheService::flush();

    expect($cache->forgotten)->toBe([
        'user:current:user-1:company-1:1784282400',
    ]);
});

test('user cache service invalidates every related company and extra session company', function () {
    [$user, $cache, $log] = user_cache_service_fixtures();
    $connection           = DB::connection('mysql');
    $connection->table('company_users')->delete();
    $connection->table('company_users')->insert([
        ['uuid' => 'company-user-valid-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1'],
        ['uuid' => 'company-user-valid-2', 'company_uuid' => 'company-2', 'user_uuid' => 'user-1'],
    ]);
    session(['company' => 'company-3']);

    UserCacheService::invalidateUser($user);

    expect($cache->forgotten)->toBe([
        'user:current:user-1:company-1:1784282400',
        'user:current:user-1:company-2:1784282400',
        'user:current:user-1:company-3:1784282400',
    ])
        ->and($log->entries)->toContain(['debug', 'User cache invalidated for session company', [
            'user_id'    => 99,
            'company_id' => 'company-3',
            'cache_key'  => 'user:current:user-1:company-3:1784282400',
        ]]);
});

test('user cache service logs invalidate and redis scan failures without leaking exceptions', function () {
    [$user, $cache, $log] = user_cache_service_fixtures();
    $cache->throwOnForget = true;

    UserCacheService::invalidate($user, 'company-1');

    expect($log->entries[0])->toBe(['error', 'Failed to invalidate user cache', [
        'error'      => 'cache forget failed',
        'user_id'    => 'user-1',
        'company_id' => 'company-1',
    ]]);

    $cache->throwOnForget = false;
    $cache->throwOnKeys   = true;

    UserCacheService::invalidateCompany('company-1');
    UserCacheService::flush();

    expect($log->entries)->toContain(['error', 'Failed to invalidate company cache', [
        'error'      => 'redis keys failed',
        'company_id' => 'company-1',
    ]])
        ->and($log->entries)->toContain(['error', 'Failed to flush user cache', [
            'error' => 'redis keys failed',
        ]]);
});
