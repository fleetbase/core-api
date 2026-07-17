<?php

use Fleetbase\Console\Commands\TelemetryPing;
use Fleetbase\Support\Telemetry;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = sys_get_temp_dir() . '/fleetbase-core-api-test-base';

        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
    }
}

class TelemetryPingCommandOutputFake extends TelemetryPing
{
    public array $messages = [];

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = ['error', $string];
    }
}

class TelemetryPingCommandContainer extends FleetbaseTestContainer
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

function telemetry_ping_command_fixtures(): Capsule
{
    Container::setInstance(new TelemetryPingCommandContainer());

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

    $capsule->getConnection('mysql')->table('users')->insert(['uuid' => 'user-1']);
    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'      => 'company-1',
        'public_id' => 'company_1234567890',
        'name'      => 'Fleetbase HQ',
    ]);
    $capsule->getConnection('mysql')->table('orders')->insert(['uuid' => 'order-1']);

    session()->flush();
    session(['company' => 'company-1']);

    $reflection = new ReflectionClass(Telemetry::class);
    $property   = $reflection->getProperty('ipInfo');
    $property->setAccessible(true);
    $property->setValue(null, null);

    return $capsule;
}

afterEach(function () {
    putenv('TELEMETRY_DISABLED');
    session()->flush();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('prints successful telemetry ping output when telemetry sends', function () {
    telemetry_ping_command_fixtures();
    Http::fake([
        'https://json.geoiplookup.io/8.8.8.8'                           => Http::response(['country_name' => 'Mongolia'], 200),
        'https://api.github.com/repos/fleetbase/fleetbase/commits/main' => Http::response(['sha' => 'official-main-sha'], 200),
        'https://telemetry.fleetbase.io/'                               => Http::response(['ok' => true], 202),
    ]);

    $command = new TelemetryPingCommandOutputFake();

    expect($command->handle())->toBeNull()
        ->and($command->messages)->toBe([
            ['info', 'Sending telemetry...'],
            ['info', 'Telemetry sent.'],
        ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://telemetry.fleetbase.io/');
});

it('prints failure telemetry ping output when telemetry is disabled', function () {
    putenv('TELEMETRY_DISABLED=true');

    $command = new TelemetryPingCommandOutputFake();

    expect($command->handle())->toBeNull()
        ->and($command->messages)->toBe([
            ['info', 'Sending telemetry...'],
            ['error', 'Telemetry failed to send, check logs for details...'],
        ]);
});
