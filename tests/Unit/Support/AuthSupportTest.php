<?php

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

class AuthSupportCacheFake
{
    public array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class AuthSupportHashFake
{
    public function check(string $value, string $hash): bool
    {
        return hash_equals('hashed:' . $value, $hash);
    }
}

class AuthSupportResponseCacheFake
{
    public int $clears = 0;

    public function clear(): void
    {
        $this->clears++;
    }
}

class AuthSupportApiCredential extends ApiCredential
{
    public function trackLastUsed()
    {
        $this->last_used_at = now();

        return app('db')->table($this->getTable())->where('uuid', $this->uuid)->update([
            'last_used_at' => $this->last_used_at->toDateTimeString(),
            'updated_at' => $this->last_used_at->toDateTimeString(),
        ]);
    }
}

function auth_support_fixtures(): array
{
    $connectionConfig = [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ];

    $container = bind_test_container([
        'app.timezone' => 'UTC',
        'database.default' => 'mysql',
        'database.connections.mysql' => $connectionConfig,
        'fleetbase.connection.db' => 'mysql',
    ]);
    $container->instance(\Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    $container->instance('cache', new AuthSupportCacheFake());
    $container->instance('hash', new AuthSupportHashFake());
    $container->instance('responsecache', new AuthSupportResponseCacheFake());
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('responsecache');

    session()->flush();

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
    foreach (['api_credentials', 'company_users', 'users', 'companies'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->string('secret')->nullable();
        $table->boolean('test_mode')->default(false);
        $table->string('api')->nullable();
        $table->text('browser_origins')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $now = '2026-07-17 10:00:00';
    app('db')->table('companies')->insert([
        [
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'public_id' => 'company_live',
            'name' => 'Primary Company',
            'timezone' => 'Asia/Ulaanbaatar',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'uuid' => '33333333-3333-4333-8333-333333333333',
            'public_id' => 'company_fallback',
            'name' => 'Fallback Company',
            'timezone' => 'Europe/Berlin',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);
    app('db')->table('users')->insert([
        [
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'company_uuid' => '22222222-2222-4222-8222-222222222222',
            'email' => 'admin@example.com',
            'phone' => '+15555550100',
            'username' => 'admin-user',
            'name' => 'Admin User',
            'type' => 'admin',
            'timezone' => 'Pacific/Auckland',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'company_uuid' => null,
            'email' => 'driver@example.com',
            'phone' => '+15555550101',
            'username' => 'driver-user',
            'name' => 'Driver User',
            'type' => 'driver',
            'timezone' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);
    app('db')->table('company_users')->insert([
        'uuid' => '55555555-5555-4555-8555-555555555555',
        'company_uuid' => '33333333-3333-4333-8333-333333333333',
        'user_uuid' => '44444444-4444-4444-8444-444444444444',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    app('db')->table('api_credentials')->insert([
        'uuid' => '66666666-6666-4666-8666-666666666666',
        '_key' => 'api-key-row',
        'user_uuid' => '11111111-1111-4111-8111-111111111111',
        'company_uuid' => '22222222-2222-4222-8222-222222222222',
        'name' => 'Test API Key',
        'key' => 'flb_test_key',
        'secret' => 'hashed-secret',
        'test_mode' => true,
        'api' => 'console',
        'browser_origins' => json_encode([]),
        'expires_at' => Carbon::now()->addHour()->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        User::where('uuid', '11111111-1111-4111-8111-111111111111')->first(),
        User::where('uuid', '44444444-4444-4444-8444-444444444444')->first(),
        AuthSupportApiCredential::where('uuid', '66666666-6666-4666-8666-666666666666')->first(),
    ];
}

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
    session()->flush();
});

test('auth support sets user sessions and checks passwords through the configured hash driver', function () {
    [$admin] = auth_support_fixtures();

    expect(Auth::setSession(null))->toBeFalse()
        ->and(Auth::setSession($admin))->toBeTrue()
        ->and(session('company'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and(session('user'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(session('is_admin'))->toBeTrue()
        ->and(session('is_customer'))->toBeFalse()
        ->and(session('is_driver'))->toBeFalse()
        ->and(Auth::checkPassword('secret', 'hashed:secret'))->toBeTrue()
        ->and(Auth::isInvalidPassword('wrong', 'hashed:secret'))->toBeTrue();
});

test('auth support stores api credential session context and tracks key usage', function () {
    [, , $credential] = auth_support_fixtures();
    Carbon::setTestNow(Carbon::parse('2026-07-17 11:30:00'));

    expect(Auth::setSession($credential))->toBeTrue()
        ->and(session('company'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and(session('user'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(session('is_admin'))->toBeTrue();

    $credential->refresh();
    expect($credential->last_used_at->toISOString())->toBe('2026-07-17T11:30:00.000000Z');

    expect(Auth::setApiKey($credential))->toBeTrue()
        ->and(session('api_credential'))->toBe($credential->uuid)
        ->and(session('api_key'))->toBe('flb_test_key')
        ->and(session('api_secret'))->toBe('hashed-secret')
        ->and(session('api_environment'))->toBe('test')
        ->and(session('api_test_mode'))->toBeTrue()
        ->and(Auth::getApiKey()->uuid)->toBe($credential->uuid);
});

test('auth support applies sandbox session from headers or api credential fallback', function () {
    [, , $credential] = auth_support_fixtures();

    $headerRequest = Request::create('/v1/orders', 'GET', [], [], [], [
        'HTTP_ACCESS_CONSOLE_SANDBOX' => '1',
        'HTTP_ACCESS_CONSOLE_SANDBOX_KEY' => 'header-key',
    ]);

    expect(Auth::setSandboxSession($headerRequest))->toBeTrue()
        ->and(config('database.default'))->toBe('sandbox')
        ->and(config('fleetbase.connection.db'))->toBe('sandbox')
        ->and(session('is_sandbox'))->toBeTrue()
        ->and(session('sandbox_api_credential'))->toBe('header-key');

    session()->flush();
    config(['database.default' => 'mysql', 'fleetbase.connection.db' => 'mysql']);
    expect(Auth::setSandboxSession(Request::create('/v1/orders'), $credential))->toBeTrue()
        ->and(config('database.default'))->toBe('sandbox')
        ->and(session('sandbox_api_credential'))->toBe($credential->uuid);
});

test('auth support resolves companies from session request params and user membership fallback', function () {
    [$admin, $driver] = auth_support_fixtures();

    session(['company' => '22222222-2222-4222-8222-222222222222']);
    expect(Auth::getCompany(['uuid', 'name'])->name)->toBe('Primary Company');

    session()->flush();
    app()->instance('request', Request::create('/test', 'GET', ['company' => 'company_fallback']));
    expect(Auth::getCompany()->uuid)->toBe('33333333-3333-4333-8333-333333333333');

    expect(Auth::getCompanySessionForUser($admin)->uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and(Auth::getCompanySessionForUser($driver)->uuid)->toBe('33333333-3333-4333-8333-333333333333');
});

test('auth support resolves user timezone before company and app fallbacks', function () {
    [$admin, $driver] = auth_support_fixtures();

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $admin);
    expect(Auth::getUserTimezone($request))->toBe('Pacific/Auckland');

    $requestWithoutUserTimezone = Request::create('/test');
    $requestWithoutUserTimezone->setUserResolver(fn () => $driver);
    expect(Auth::getUserTimezone($requestWithoutUserTimezone))->toBe('Europe/Berlin');

    $missingCompany = new User([
        'uuid' => '77777777-7777-4777-8777-777777777777',
        'timezone' => null,
        'company_uuid' => null,
    ]);
    $fallbackRequest = Request::create('/test');
    $fallbackRequest->setUserResolver(fn () => $missingCompany);

    expect(Auth::getUserTimezone($fallbackRequest))->toBe('UTC');
});
