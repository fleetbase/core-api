<?php

use Fleetbase\Console\Commands\RegisterHostedRoutingCommand;
use Fleetbase\Jobs\RegisterHostedRouting;
use Fleetbase\Models\Setting;
use Fleetbase\Support\HostedRouting;
use Fleetbase\Support\InstanceIdentity;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

const HOSTED_ROUTING_URL = 'https://api.fleetbase.test/__/licenses/register-instance';

class HostedRoutingTestLogger extends NullLogger
{
    public array $warnings = [];

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }
}

function hosted_routing_fixtures(array $config = []): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $container  = bind_test_container(array_merge([
        'app.url'                                   => 'https://fleet.acme.test',
        'database.default'                          => 'mysql',
        'database.connections.mysql'                => $connection,
        'fleetbase.connection.db'                   => 'mysql',
        'fleetbase.version'                         => '0.7.70',
        'fleetbase.hosted_routing.auto_register'    => true,
        'fleetbase.hosted_routing.registration_url' => HOSTED_ROUTING_URL,
        'fleetbase.hosted_routing.api_host'         => 'https://valhalla.routing.fleetbase.io',
        'fleetbase.hosted_routing.domain'           => 'routing.fleetbase.io',
        'fleetbase.hosted_routing.timeout'          => 10,
    ], $config));
    $container->instance(HttpFactory::class, new HttpFactory());
    $container->instance('log', new HostedRoutingTestLogger());
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('email')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

function hosted_routing_admin(Capsule $capsule, string $email = 'first@acme.test', string $createdAt = '2026-10-01 00:00:00'): void
{
    $capsule->getConnection('mysql')->table('users')->insert(['uuid' => 'user-' . md5($email), 'email' => $email, 'type' => 'admin', 'created_at' => $createdAt]);
}

function hosted_routing_registered(array $overrides = []): array
{
    return array_merge([
        'status'            => 'registered',
        'key'               => 'fbrl_' . str_repeat('k', 28) . 'ab12',
        'license'           => ['uuid' => 'license-1', 'owner' => 'instance'],
        'plan'              => 'routing_unverified',
        'email_verified'    => false,
        'verification_sent' => true,
        'claimed'           => false,
        'claim_code'        => 'code-1',
        'claim_url'         => 'https://console.fleetbase.io/~/routing?claim=code-1',
        'api_host'          => 'https://valhalla.routing.fleetbase.io',
    ], $overrides);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    config(['fleetbase.hosted_routing' => null, 'app.url' => null]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('the install identity is created once at runtime and kept in the database', function () {
    hosted_routing_fixtures();

    $first  = InstanceIdentity::get();
    $second = InstanceIdentity::get();

    expect($first['id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($first['secret'])->toHaveLength(64)
        ->and($first['created_at'])->toBe('2026-10-24T10:00:00+00:00')
        ->and($second)->toBe($first)
        ->and(InstanceIdentity::id())->toBe($first['id'])
        ->and(InstanceIdentity::secret())->toBe($first['secret'])
        ->and(Setting::where('key', 'system.instance')->count())->toBe(1);
});

test('a malformed install identity row is replaced', function () {
    hosted_routing_fixtures();
    Setting::configure('system.instance', ['id' => 'only-an-id']);

    $identity = InstanceIdentity::get();

    expect($identity['secret'])->toHaveLength(64)
        ->and(Setting::lookup('system.instance'))->toBe($identity);
});

test('hosted hosts are routing.fleetbase.io and its subdomains only', function () {
    hosted_routing_fixtures();

    expect(HostedRouting::isHostedHost('https://valhalla.routing.fleetbase.io'))->toBeTrue()
        ->and(HostedRouting::isHostedHost('https://routing.fleetbase.io/valhalla'))->toBeTrue()
        ->and(HostedRouting::isHostedHost('https://notrouting.fleetbase.io'))->toBeFalse()
        ->and(HostedRouting::isHostedHost('https://valhalla.example.com'))->toBeFalse()
        ->and(HostedRouting::isHostedHost(null))->toBeFalse();
});

test('registering stores the key in the valhalla setting and the registration for the panel', function () {
    $capsule = hosted_routing_fixtures();
    hosted_routing_admin($capsule, 'later@acme.test', '2026-10-05 00:00:00');
    hosted_routing_admin($capsule);
    Setting::configure('valhalla', ['api_host' => 'https://valhalla1.openstreetmap.de', 'extra' => 'kept']);
    Http::fake([HOSTED_ROUTING_URL => Http::response(hosted_routing_registered(), 201)]);

    expect(HostedRouting::isAvailable())->toBeTrue()
        ->and(HostedRouting::registration())->toBeNull();

    $result = HostedRouting::register();

    expect($result)->toBe([
        'status'         => 'registered',
        'message'        => 'Fleetbase Hosted Routing is on.',
        'plan'           => 'routing_unverified',
        'email'          => 'first@acme.test',
        'email_verified' => false,
        'claim_url'      => 'https://console.fleetbase.io/~/routing?claim=code-1',
    ])
        ->and(Setting::lookup('valhalla'))->toBe([
            'api_host' => 'https://valhalla.routing.fleetbase.io',
            'extra'    => 'kept',
            'api_key'  => hosted_routing_registered()['key'],
        ])
        ->and(HostedRouting::registration())->toBe([
            'license'        => 'license-1',
            'key_hint'       => 'ab12',
            'plan'           => 'routing_unverified',
            'email'          => 'first@acme.test',
            'email_verified' => false,
            'claimed'        => false,
            'claim_url'      => 'https://console.fleetbase.io/~/routing?claim=code-1',
            'api_host'       => 'https://valhalla.routing.fleetbase.io',
            'registered_at'  => '2026-10-24T10:00:00+00:00',
        ])
        ->and(json_encode(HostedRouting::registration()))->not->toContain(hosted_routing_registered()['key']);

    $identity = InstanceIdentity::get();
    Http::assertSent(fn (HttpRequest $request) => $request->url() === HOSTED_ROUTING_URL
        && $request->data() === [
            'product'         => 'routing',
            'instance_id'     => $identity['id'],
            'instance_secret' => $identity['secret'],
            'email'           => 'first@acme.test',
            'domain'          => 'https://fleet.acme.test',
            'version'         => '0.7.70',
        ]);

    // Idempotent: the install already holds its key.
    expect(HostedRouting::register())->toMatchArray(['status' => 'skipped', 'reason' => 'already_registered']);
    Http::assertSentCount(1);
});

test('forcing registration again replaces the key and falls back to the configured host', function () {
    hosted_routing_fixtures();
    Setting::configure('valhalla', ['api_host' => 'https://valhalla.routing.fleetbase.io', 'api_key' => 'fbrl_old']);
    Setting::configure('system.hosted-routing', ['key_hint' => '_old']);
    Http::fake([HOSTED_ROUTING_URL => Http::response(hosted_routing_registered(['api_host' => null, 'key' => 'fbrl_newkey9999', 'claimed' => true, 'claim_url' => null]))]);

    $result = HostedRouting::register('ops@acme.test', 'https://ops.acme.test', true);

    expect($result)->toMatchArray(['status' => 'registered', 'email' => 'ops@acme.test', 'claim_url' => null])
        ->and(Setting::lookup('valhalla'))->toBe(['api_host' => 'https://valhalla.routing.fleetbase.io', 'api_key' => 'fbrl_newkey9999'])
        ->and(HostedRouting::registration())->toMatchArray(['key_hint' => '9999', 'claimed' => true]);
    Http::assertSent(fn (HttpRequest $request) => $request['domain'] === 'https://ops.acme.test');
});

test('registration is skipped when turned off or when the admin runs their own server', function () {
    hosted_routing_fixtures(['fleetbase.hosted_routing.auto_register' => false]);
    Http::fake();

    expect(HostedRouting::isEnabled())->toBeFalse()
        ->and(HostedRouting::isAvailable())->toBeFalse()
        ->and(HostedRouting::register('a@acme.test'))->toBe([
            'status'  => 'skipped',
            'reason'  => 'disabled',
            'message' => 'Hosted routing registration is turned off (ROUTING_AUTO_REGISTER=false).',
        ]);

    config(['fleetbase.hosted_routing.auto_register' => true]);
    Setting::configure('valhalla', ['api_host' => 'https://valhalla.internal.acme.test', 'api_key' => 'own']);

    expect(HostedRouting::isAvailable())->toBeFalse()
        ->and(HostedRouting::register('a@acme.test'))->toMatchArray(['status' => 'skipped', 'reason' => 'own_server']);

    // A stored value that is not an object counts as nothing configured.
    Setting::configure('valhalla', 'garbage');
    expect(HostedRouting::isAvailable())->toBeTrue();

    Http::assertNothingSent();
});

test('registration needs an administrator email', function () {
    hosted_routing_fixtures();
    Http::fake();

    expect(HostedRouting::register())->toMatchArray(['status' => 'failed', 'reason' => 'no_email']);
    Http::assertNothingSent();
});

test('an unreachable or refusing Fleetbase Cloud fails without touching the settings', function () {
    hosted_routing_fixtures();
    $log = app('log');

    Http::fake([HOSTED_ROUTING_URL => fn () => throw new ConnectionException('timed out')]);
    expect(HostedRouting::register('a@acme.test'))->toMatchArray(['status' => 'failed', 'reason' => 'unreachable']);

    Http::swap(new HttpFactory());
    Http::fake([HOSTED_ROUTING_URL => Http::response(['reason' => 'limit_reached', 'error' => 'Too many installs are registered for this email.'], 429)]);
    expect(HostedRouting::register('a@acme.test'))->toBe([
        'status'  => 'failed',
        'reason'  => 'limit_reached',
        'message' => 'Too many installs are registered for this email.',
    ]);

    Http::swap(new HttpFactory());
    Http::fake([HOSTED_ROUTING_URL => Http::response('<html>bad gateway</html>', 502)]);
    expect(HostedRouting::register('a@acme.test'))->toBe([
        'status'  => 'failed',
        'reason'  => 'refused',
        'message' => 'Registration was refused (HTTP 502).',
    ]);

    Http::swap(new HttpFactory());
    Http::fake([HOSTED_ROUTING_URL => Http::response(['status' => 'registered'], 201)]);
    expect(HostedRouting::register('a@acme.test'))->toMatchArray(['status' => 'failed', 'reason' => 'refused']);

    expect(Setting::lookup('valhalla'))->toBeNull()
        ->and(HostedRouting::registration())->toBeNull()
        ->and($log->warnings)->toBe([
            'Hosted routing registration failed: timed out',
            'Hosted routing registration refused: 429 limit_reached',
            'Hosted routing registration refused: 502 no reason',
            'Hosted routing registration refused: 201 no reason',
        ]);
});

test('the onboarding job runs after the response through the bus', function () {
    hosted_routing_fixtures();
    $bus = new class {
        public array $dispatched = [];

        public function dispatchAfterResponse($job): void
        {
            $this->dispatched[] = $job;
        }
    };
    app()->instance(Illuminate\Bus\Dispatcher::class, $bus);

    RegisterHostedRouting::afterResponse('grace@acme.test', 'https://api.acme.test');

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(RegisterHostedRouting::class)
        ->and($bus->dispatched[0]->email)->toBe('grace@acme.test')
        ->and($bus->dispatched[0]->domain)->toBe('https://api.acme.test');
    app()->forgetInstance(Illuminate\Bus\Dispatcher::class);
});

test('the onboarding job registers with the admin email and the install URL', function () {
    hosted_routing_fixtures();
    Http::fake([HOSTED_ROUTING_URL => Http::response(hosted_routing_registered(), 201)]);

    (new RegisterHostedRouting('grace@acme.test', 'https://api.acme.test'))->handle();

    expect(Setting::lookup('valhalla')['api_key'])->toBe(hosted_routing_registered()['key']);
    Http::assertSent(fn (HttpRequest $request) => $request['email'] === 'grace@acme.test' && $request['domain'] === 'https://api.acme.test');
});

function hosted_routing_command(array $input = []): CommandTester
{
    $command = new RegisterHostedRoutingCommand();
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->execute($input);

    return $tester;
}

test('routing:register explains what is sent and how to confirm and upgrade', function () {
    hosted_routing_fixtures();
    Http::fake([HOSTED_ROUTING_URL => Http::response(hosted_routing_registered(), 201)]);

    $tester  = hosted_routing_command(['--email' => 'grace@acme.test', '--domain' => 'https://fleet.acme.test']);
    $display = $tester->getDisplay();

    expect($tester->getStatusCode())->toBe(0)
        ->and($display)->toContain('Singapore (DigitalOcean sgp1)')
        ->and($display)->toContain('the admin email and this install\'s URL')
        ->and($display)->toContain('Fleetbase Hosted Routing is on. Plan: routing_unverified.')
        ->and($display)->toContain('Confirm the email sent to grace@acme.test to raise the limit from 500 to 2,500 routes a day.')
        ->and($display)->toContain('https://console.fleetbase.io/~/routing?claim=code-1')
        ->and($display)->not->toContain('fbrl_');
});

test('routing:register succeeds quietly for a verified or claimed key and on skips', function () {
    hosted_routing_fixtures();
    Http::fake([HOSTED_ROUTING_URL => Http::response(hosted_routing_registered(['email_verified' => true, 'claim_url' => null, 'plan' => null]))]);

    $registered = hosted_routing_command(['--email' => 'grace@acme.test', '--force' => true]);
    $skipped    = hosted_routing_command(['--email' => 'grace@acme.test']);

    expect($registered->getStatusCode())->toBe(0)
        ->and($registered->getDisplay())->toContain('Plan: unknown.')
        ->and($registered->getDisplay())->not->toContain('Confirm the email')
        ->and($registered->getDisplay())->not->toContain('Upgrade later')
        ->and($skipped->getStatusCode())->toBe(0)
        ->and($skipped->getDisplay())->toContain('already has a Fleetbase Hosted Routing key');
});

test('routing:register fails with the reason when registration fails', function () {
    hosted_routing_fixtures();
    Http::fake();

    $tester = hosted_routing_command();

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('No administrator email to register with. Pass --email.');
});
