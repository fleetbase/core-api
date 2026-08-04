<?php

use Fleetbase\Support\Telemetry;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = sys_get_temp_dir() . '/fleetbase-core-api-test-base';

        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
    }
}

class TelemetryTestContainer extends FleetbaseTestContainer
{
    public function environment(array|string|null $environments = null): bool|string
    {
        if ($environments === null) {
            return $this->make('config')->get('app.env', 'testing');
        }

        return parent::environment($environments);
    }

    public function version(): string
    {
        return '10.48.0';
    }
}

class TelemetryCacheFake
{
    public array $values = [];

    public function remember(string $key, mixed $ttl, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }
}

class TelemetryThrowsOnSend extends Telemetry
{
    public static function send(array $payload = []): bool
    {
        throw new RuntimeException('telemetry send failed');
    }
}

class TelemetryFilesystemFake
{
    public function __construct(private array $files = [])
    {
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function get(string $path): string
    {
        return $this->files[$path] ?? '';
    }
}

function telemetry_reset_state(): void
{
    $reflection = new ReflectionClass(Telemetry::class);
    $property   = $reflection->getProperty('ipInfo');
    $property->setAccessible(true);
    $property->setValue(null, null);
}

function telemetry_fixtures(): Capsule
{
    Container::setInstance(new TelemetryTestContainer());

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.name'                   => 'Fleetbase Test',
        'app.url'                    => 'https://api.fleetbase.test',
        'app.env'                    => 'production',
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        'fleetbase.console.host'     => 'https://console.fleetbase.test',
        'fleetbase.instance_id'      => 'instance-123',
        'fleetbase.version'          => '1.6.55',
    ]);
    $container->instance(HttpFactory::class, new HttpFactory());
    $container->instance('cache', new TelemetryCacheFake());
    $container->instance('files', new Filesystem());
    $container->instance('log', new NullLogger());
    Facade::clearResolvedInstances();

    $request = Request::create('https://api.fleetbase.test/int/v1/telemetry', 'GET', [], [], [], [
        'REMOTE_ADDR' => '8.8.8.8',
    ]);
    $container->instance('request', $request);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach (['users', 'companies', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('name')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable()->unique();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->text('options')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
    });

    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-1', 'name' => 'Ron'],
        ['uuid' => 'user-2', 'name' => 'Ada'],
    ]);
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_1234567890', 'name' => 'Fleetbase HQ'],
        ['uuid' => 'company-2', 'public_id' => 'company_0987654321', 'name' => 'Fleetbase Remote'],
    ]);
    $capsule->getConnection('mysql')->table('orders')->insert([
        ['uuid' => 'order-1'],
        ['uuid' => 'order-2'],
        ['uuid' => 'order-3'],
    ]);

    session()->flush();
    session(['company' => 'company-1']);
    if (!is_dir(base_path())) {
        mkdir(base_path(), 0777, true);
    }
    telemetry_reset_state();

    return $capsule;
}

function telemetry_fake_successful_dependencies(): void
{
    Http::fake([
        'https://json.geoiplookup.io/8.8.8.8' => Http::response([
            'time_zone'    => ['name' => 'Asia/Ulaanbaatar'],
            'region'       => 'Ulaanbaatar',
            'country_name' => 'Mongolia',
            'country_code' => 'MN',
        ], 200),
        'https://api.github.com/repos/fleetbase/fleetbase/commits/main' => Http::response([
            'sha' => 'official-main-sha',
        ], 200),
        'https://telemetry.fleetbase.io/' => Http::response(['ok' => true], 202),
    ]);
}

afterEach(function () {
    putenv('TELEMETRY_DISABLED');
    session()->flush();
    telemetry_reset_state();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('telemetry sends instance tags with database counts and configured metadata', function () {
    telemetry_fixtures();
    telemetry_fake_successful_dependencies();

    expect(Telemetry::send(['alert_type' => 'success', 'custom' => 'value']))->toBeTrue();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://telemetry.fleetbase.io/') {
            return false;
        }

        $tags = $request['tags'];

        return $request['title'] === 'Fleetbase Instance Telemetry'
            && $request['alert_type'] === 'success'
            && $request['custom'] === 'value'
            && in_array('fleetbase.instance_id:instance-123', $tags, true)
            && in_array('fleetbase.company:Fleetbase HQ', $tags, true)
            && in_array('fleetbase.domain:api.fleetbase.test', $tags, true)
            && in_array('fleetbase.api:https://api.fleetbase.test', $tags, true)
            && in_array('fleetbase.console:https://console.fleetbase.test', $tags, true)
            && in_array('fleetbase.app_name:Fleetbase Test', $tags, true)
            && in_array('fleetbase.version:1.6.55', $tags, true)
            && in_array('laravel.version:10.48.0', $tags, true)
            && in_array('env:production', $tags, true)
            && in_array('timezone:Asia/Ulaanbaatar', $tags, true)
            && in_array('region:Ulaanbaatar', $tags, true)
            && in_array('country:Mongolia', $tags, true)
            && in_array('country_code:MN', $tags, true)
            && in_array('users.count:2', $tags, true)
            && in_array('companies.count:2', $tags, true)
            && in_array('orders.count:3', $tags, true)
            && in_array('source.modified:false', $tags, true)
            && in_array('source.commit_hash:', $tags, true)
            && in_array('source.main_hash:official-main-sha', $tags, true);
    });
});

test('telemetry returns false and logs when downstream responses fail', function () {
    telemetry_fixtures();
    Http::fake([
        'https://json.geoiplookup.io/8.8.8.8'                           => Http::response(['message' => 'geo failed'], 500),
        'https://api.github.com/repos/fleetbase/fleetbase/commits/main' => Http::response(['message' => 'rate limited'], 403),
        'https://telemetry.fleetbase.io/'                               => Http::response('nope', 500),
    ]);

    expect(Telemetry::send())->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === 'https://telemetry.fleetbase.io/');
});

test('telemetry returns false without outbound requests when disabled', function () {
    telemetry_fixtures();
    putenv('TELEMETRY_DISABLED=true');
    Http::fake();

    expect(Telemetry::send())->toBeFalse();

    Http::assertNothingSent();
});

test('telemetry handles outbound exceptions after building payload tags', function () {
    telemetry_fixtures();
    session()->flush();
    $tags = [];
    Http::fake([
        'https://json.geoiplookup.io/8.8.8.8' => Http::response([
            'time_zone'    => ['name' => 'Asia/Ulaanbaatar'],
            'region'       => 'Ulaanbaatar',
            'country_name' => 'Mongolia',
            'country_code' => 'MN',
        ], 200),
        'https://api.github.com/repos/fleetbase/fleetbase/commits/main' => Http::response([
            'sha' => 'official-main-sha',
        ], 200),
        'https://telemetry.fleetbase.io/' => function ($request) use (&$tags) {
            $tags = $request['tags'];

            throw new RuntimeException('telemetry endpoint unavailable');
        },
    ]);

    expect(Telemetry::send())->toBeFalse();

    expect($tags)->toContain('fleetbase.company:Fleetbase HQ');
});

test('telemetry ping sends at most once for the cache window', function () {
    telemetry_fixtures();
    telemetry_fake_successful_dependencies();

    Telemetry::ping();
    Telemetry::ping();

    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => $request->url() === 'https://telemetry.fleetbase.io/');
});

test('telemetry ping caches timestamp even when send throws', function () {
    telemetry_fixtures();

    TelemetryThrowsOnSend::ping();

    $cache = app('cache');

    expect($cache->values)->toHaveKey('telemetry:last_ping')
        ->and($cache->values['telemetry:last_ping'])->toBeString();
});

test('telemetry source lookup handles client exceptions and continues sending', function () {
    telemetry_fixtures();
    Http::fake([
        'https://json.geoiplookup.io/8.8.8.8' => Http::response([
            'time_zone'    => ['name' => 'Asia/Ulaanbaatar'],
            'region'       => 'Ulaanbaatar',
            'country_name' => 'Mongolia',
            'country_code' => 'MN',
        ], 200),
        'https://api.github.com/repos/fleetbase/fleetbase/commits/main' => function () {
            throw new RuntimeException('github unavailable');
        },
        'https://telemetry.fleetbase.io/' => Http::response(['ok' => true], 202),
    ]);

    expect(Telemetry::send())->toBeTrue();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://telemetry.fleetbase.io/') {
            return false;
        }

        return in_array('source.modified:false', $request['tags'], true)
            && in_array('source.main_hash:', $request['tags'], true);
    });
});

test('telemetry installation type detects docker host markers', function () {
    telemetry_fixtures();

    FileFacade::swap(new TelemetryFilesystemFake([
        '/.dockerenv' => '',
    ]));

    expect(Telemetry::getInstallationType())->toBe('docker');
});

// Driven through the fake rather than the real filesystem so the fall-through is exercised on any
// host: on Ubuntu (CI) /etc/lsb-release exists and returns early, on macOS it does not.
test('telemetry installation type falls back to unknown without host markers', function () {
    telemetry_fixtures();

    FileFacade::swap(new TelemetryFilesystemFake());

    expect(Telemetry::getInstallationType())->toBe('unknown');
});

test('telemetry caches ip metadata between sends', function () {
    telemetry_fixtures();
    telemetry_fake_successful_dependencies();

    expect(Telemetry::send())->toBeTrue()
        ->and(Telemetry::send(['custom' => 'second']))->toBeTrue();

    Http::assertSentCount(7);
});

test('telemetry can generate and reuse a stable instance id file', function () {
    telemetry_fixtures();
    $file = base_path('.fleetbase-id');
    if (file_exists($file)) {
        unlink($file);
    }

    $first  = Telemetry::generateInstanceId();
    $second = Telemetry::generateInstanceId();

    expect($first)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($second)->toBe($first)
        ->and(file_get_contents($file))->toBe($first);
});
