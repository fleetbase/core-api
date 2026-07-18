<?php

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Controllers\Internal\v1\UserDeviceController;
use Fleetbase\Http\Controllers\Internal\v1\WebhookEndpointController;
use Fleetbase\Http\Middleware\TrackPresence;
use Fleetbase\Models\UserDevice;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LightweightHttpPresenceUser
{
    public int $remembered = 0;

    public function rememberPresence(): void
    {
        $this->remembered++;
    }
}

class LightweightHttpTaggedCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $callback();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function decrement(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) - $value;

        return $this->values[$key];
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class LightweightHttpResponseCacheFake
{
    public function clear(): void
    {
    }
}

function lightweight_http_user_device_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new LightweightHttpTaggedCacheFake());
    $container->instance('responsecache', new LightweightHttpResponseCacheFake());

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('user_devices', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('platform')->nullable();
        $table->string('token')->unique();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

afterEach(function () {
    session()->flush();
    EloquentModel::clearBootedModels();
});

test('base controller exposes stable health response contracts', function () {
    bind_test_container([
        'fleetbase.api.version' => 'v1',
        'fleetbase.version'     => '1.6.55',
    ]);

    $controller = new Controller();
    $request    = Request::create('/hello', 'GET');
    $request->attributes->set('request_start_time', microtime(true) - 0.05);

    $hello = $controller->hello($request);
    $time  = $controller->time($request);
    $test  = $controller->test($request);

    expect($hello)->toBeInstanceOf(JsonResponse::class)
        ->and($hello->getData(true))->toHaveKeys(['message', 'version', 'fleetbase', 'ms'])
        ->and($hello->getData(true)['message'])->toBe('Fleetbase API')
        ->and($hello->getData(true)['version'])->toBe('v1')
        ->and($hello->getData(true)['fleetbase'])->toBe('1.6.55')
        ->and($hello->getData(true)['ms'])->toBeGreaterThan(0)
        ->and($time->getData(true))->toHaveKey('ms')
        ->and($time->getData(true)['ms'])->toBeGreaterThan(0)
        ->and($test->getData(true))->toHaveKey('status', 'ok')
        ->and($test->getData(true))->toHaveKey('ms');
});

test('webhook endpoint metadata and missing id responses stay stable', function () {
    bind_test_container([
        'api.events'   => ['order.created', 'file.uploaded'],
        'api.versions' => ['v1', 'v2'],
    ]);

    $controller = new WebhookEndpointController();

    $events         = WebhookEndpointController::events();
    $versions       = WebhookEndpointController::versions();
    $missingEnable  = $controller->enable('');
    $missingDisable = $controller->disable('');

    expect($events->getData(true))->toBe(['order.created', 'file.uploaded'])
        ->and($versions->getData(true))->toBe(['v1', 'v2'])
        ->and($missingEnable->getStatusCode())->toBe(401)
        ->and($missingEnable->getData(true))->toBe(['errors' => ['No webhook to enable']])
        ->and($missingDisable->getStatusCode())->toBe(401)
        ->and($missingDisable->getData(true))->toBe(['errors' => ['No webhook to disable']]);
});

test('track presence records authenticated users and passes anonymous requests through', function () {
    bind_test_container();
    $middleware = new TrackPresence();
    $user       = new LightweightHttpPresenceUser();

    $authenticated = Request::create('/int/v1/me', 'GET');
    $authenticated->setUserResolver(fn () => $user);

    $anonymous = Request::create('/int/v1/me', 'GET');

    $authResponse = $middleware->handle($authenticated, fn () => new JsonResponse(['status' => 'auth']));
    $anonResponse = $middleware->handle($anonymous, fn () => new JsonResponse(['status' => 'anon']));

    expect($authResponse->getData(true))->toBe(['status' => 'auth'])
        ->and($anonResponse->getData(true))->toBe(['status' => 'anon'])
        ->and($user->remembered)->toBe(1);
});

test('user device controller registers new devices and reuses existing tokens', function () {
    lightweight_http_user_device_database();

    $controller = new UserDeviceController();
    $first      = $controller->register(Request::create('/int/v1/user-devices/register', 'POST', [
        'user_uuid' => 'user-1',
        'platform'  => 'ios',
        'token'     => 'device-token-1',
        'status'    => 'active',
    ]));
    $second     = $controller->register(Request::create('/int/v1/user-devices/register', 'POST', [
        'user_uuid' => 'user-2',
        'platform'  => 'android',
        'token'     => 'device-token-1',
        'status'    => 'inactive',
    ]));

    expect($first->getData(true)['status'])->toBe('OK')
        ->and($first->getData(true)['device'])->toBe($second->getData(true)['device'])
        ->and(UserDevice::query()->count())->toBe(1)
        ->and(UserDevice::query()->first()->user_uuid)->toBe('user-1')
        ->and(UserDevice::query()->first()->platform)->toBe('ios');
});
