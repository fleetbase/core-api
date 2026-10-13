<?php

use Fleetbase\Http\Controllers\Internal\v1\SmsGuardController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\SmsSend;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsGuard;
use Fleetbase\Support\Sms\SmsLedger;
use Fleetbase\Support\Sms\SmsPolicy;
use Fleetbase\Tests\Fixtures\Support\SmsGuardFixture;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;

function sms_guard_controller_fixture(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'sms.guard'                  => ['enabled' => true, 'mode' => 'enforce', 'limits' => ['company' => ['per_day' => 200]]],
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new CacheRepository(new ArrayStore()));
    app()->forgetInstance(SmsGuard::class);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    SmsGuardFixture::smsSendsSchema($capsule);
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-acme', 'public_id' => 'company_acme', 'name' => 'Acme'],
        ['uuid' => 'company-globex', 'public_id' => 'company_globex', 'name' => 'Globex'],
    ]);

    $validation = new ValidationFactory(new Translator(new ArrayLoader(), 'en'));
    $validation->setPresenceVerifier(new DatabasePresenceVerifier($capsule->getDatabaseManager()));
    Request::macro('validate', function (array $rules) use ($validation) {
        return $validation->make($this->all(), $rules)->validate();
    });

    return $capsule;
}

function sms_guard_controller_request(string $method = 'GET', array $input = []): AdminRequest
{
    return AdminRequest::create('/int/v1/sms-guard/settings', $method, $input);
}

afterEach(function () {
    Carbon::setTestNow();

    $macros = new ReflectionProperty(Request::class, 'macros');
    $macros->setAccessible(true);
    $macros->setValue(null, array_diff_key($macros->getValue(), ['validate' => true]));

    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('sms guard controller returns the effective policy, defaults, pauses and ledger availability', function () {
    sms_guard_controller_fixture();
    SmsPolicy::update(['mode' => 'monitor', 'overrides' => [['company_uuid' => 'company-acme', 'per_day' => 2000, 'note' => 'large client']]]);
    SmsGuard::make()->breaker()->pause('company:company-globex', 30, 'incident');

    $payload = (new SmsGuardController())->getSettings(sms_guard_controller_request())->getData(true);

    expect($payload['settings']['mode'])->toBe('monitor')
        ->and($payload['settings']['limits']['company']['per_day'])->toBe(200)
        ->and($payload['settings']['overrides'][0])->toMatchArray(['company_uuid' => 'company-acme', 'per_day' => 2000, 'note' => 'large client', 'company_id' => 'company_acme', 'company_name' => 'Acme'])
        ->and($payload['defaults']['mode'])->toBe('enforce')
        ->and($payload['pauses'])->toHaveCount(1)
        ->and($payload['pauses'][0])->toMatchArray(['scope' => 'company:company-globex', 'reason' => 'incident', 'company_name' => 'Globex', 'platform' => false])
        ->and($payload['pauses'][0]['until_iso'])->toBeString()
        ->and($payload['ledger_available'])->toBeTrue();
});

test('sms guard controller saves a validated policy, replaces the override list and keeps pauses', function () {
    sms_guard_controller_fixture();
    SmsGuard::make()->breaker()->pause('platform', null, 'freeze');

    $saved = (new SmsGuardController())->saveSettings(sms_guard_controller_request('POST', [
        'enabled'      => true,
        'mode'         => 'enforce',
        'alert_emails' => ['ops@example.test'],
        'validation'   => ['enabled' => true, 'blocked_countries' => ['KP'], 'blocked_prefixes' => ['+881']],
        'limits'       => ['company' => ['per_day' => 300, 'per_hour' => null], 'destination' => ['cooldown_seconds' => 30]],
        'breaker'      => ['pause_minutes' => 15, 'alert_percentages' => [50], 'new_country_min_sends' => 5],
        'overrides'    => [['company_uuid' => 'company-globex', 'unlimited' => true, 'note' => 'partner']],
    ]))->getData(true);

    $trimmed = (new SmsGuardController())->saveSettings(sms_guard_controller_request('POST', ['enabled' => true, 'mode' => 'enforce', 'overrides' => []]))->getData(true);

    expect($saved['settings']['alert_emails'])->toBe(['ops@example.test'])
        ->and($saved['settings']['validation']['blocked_countries'])->toBe(['KP'])
        ->and($saved['settings']['limits']['company'])->toBe(['per_minute' => 10, 'per_hour' => null, 'per_day' => 300])
        ->and($saved['settings']['limits']['destination']['cooldown_seconds'])->toBe(30)
        ->and($saved['settings']['breaker']['pause_minutes'])->toBe(15)
        ->and($saved['settings']['breaker']['new_country_min_sends'])->toBe(5)
        ->and($saved['settings']['overrides'][0])->toMatchArray(['company_uuid' => 'company-globex', 'unlimited' => true, 'company_name' => 'Globex'])
        ->and($saved['pauses'][0]['scope'])->toBe('platform')
        ->and($trimmed['settings']['overrides'])->toBe([])
        ->and($trimmed['settings']['limits']['company']['per_day'])->toBe(300, 'a save without limits keeps the stored ones')
        ->and($trimmed['pauses'][0]['scope'])->toBe('platform', 'saving never drops a pause');
});

test('sms guard controller rejects an unknown organization, a bad mode and a bad email', function () {
    sms_guard_controller_fixture();

    $cases = [
        ['enabled' => true, 'mode' => 'enforce', 'overrides' => [['company_uuid' => 'company-missing', 'per_day' => 5]]],
        ['enabled' => true, 'mode' => 'loud'],
        ['enabled' => true, 'mode' => 'monitor', 'alert_emails' => ['not-an-email']],
    ];

    foreach ($cases as $input) {
        try {
            (new SmsGuardController())->saveSettings(sms_guard_controller_request('POST', $input));
            expect(false)->toBeTrue('validation should have failed');
        } catch (ValidationException $e) {
            expect($e->errors())->not->toBeEmpty();
        }
    }
});

test('sms guard controller resets to the defaults and manages pauses through the api', function () {
    sms_guard_controller_fixture();
    $controller = new SmsGuardController();

    $paused  = $controller->pause(sms_guard_controller_request('POST', ['scope' => 'company_acme', 'minutes' => 15, 'reason' => 'incident 7']))->getData(true);
    $bad     = $controller->pause(sms_guard_controller_request('POST', ['scope' => 'company_nobody']));
    $listed  = $controller->pauses(sms_guard_controller_request())->getData(true);
    $lifted  = $controller->unpause(sms_guard_controller_request('DELETE'), 'company%3Acompany-acme')->getData(true);
    $badLift = $controller->unpause(sms_guard_controller_request('DELETE'), 'company_nobody');

    SmsPolicy::update(['mode' => 'monitor']);
    $reset = $controller->resetSettings(sms_guard_controller_request('DELETE'))->getData(true);

    expect($paused['pauses'][0])->toMatchArray(['scope' => 'company:company-acme', 'reason' => 'incident 7', 'company_id' => 'company_acme', 'auto' => false])
        ->and($bad->getStatusCode())->toBe(422)
        ->and($listed['pauses'])->toHaveCount(1)
        ->and($lifted['pauses'])->toBe([])
        ->and($badLift->getStatusCode())->toBe(422)
        ->and($reset['settings']['mode'])->toBe('enforce')
        ->and(SmsPolicy::stored())->toBe([]);
});

test('sms guard controller summarises usage per organization and filters the ledger', function () {
    sms_guard_controller_fixture();
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));

    $acme   = new SmsContext('storefront_create_customer', '+447400100001', 'company-acme', 'store', 'store-1', 'storefront', '203.0.113.9');
    $globex = new SmsContext('driver_login', '+447400100002', 'company-globex', null, null, 'api', '198.51.100.7');

    SmsLedger::update(SmsLedger::record($acme, 'reserved'), ['status' => 'sent']);
    SmsLedger::update(SmsLedger::record($acme, 'reserved'), ['status' => 'sent']);
    SmsLedger::record($acme, 'blocked', ['block_reason' => 'cooldown']);
    SmsLedger::update(SmsLedger::record($globex, 'reserved'), ['status' => 'failed', 'error' => 'carrier']);
    SmsLedger::record(new SmsContext('2fa', '+447400100003', null), 'reserved');
    SmsPolicy::update(['overrides' => [['company_uuid' => 'company-globex', 'unlimited' => true]]]);
    SmsGuard::make()->breaker()->pause('company:company-acme', 5, 'test');

    $usage    = (new SmsGuardController())->usage(sms_guard_controller_request('GET', ['hours' => 1]))->getData(true);
    $ledger   = (new SmsGuardController())->ledger(sms_guard_controller_request('GET', ['status' => 'blocked']))->getData(true);
    $byNumber = (new SmsGuardController())->ledger(sms_guard_controller_request('GET', ['destination' => '+44 7400 100002', 'company_uuid' => 'company-globex', 'purpose' => 'driver_login']))->getData(true);

    expect($usage['hours'])->toBe(1)
        ->and($usage['ledger_available'])->toBeTrue()
        ->and($usage['usage'])->toHaveCount(2)
        ->and($usage['usage'][0])->toMatchArray(['company_uuid' => 'company-acme', 'company_name' => 'Acme', 'sent' => 2, 'failed' => 0, 'blocked' => 1, 'destinations' => 1, 'daily_budget' => 200, 'paused' => true])
        ->and($usage['usage'][1])->toMatchArray(['company_uuid' => 'company-globex', 'sent' => 0, 'failed' => 1, 'daily_budget' => null, 'paused' => false])
        ->and($ledger['sends'])->toHaveCount(1)
        ->and($ledger['sends'][0]['block_reason'])->toBe('cooldown')
        ->and($byNumber['sends'])->toHaveCount(1)
        ->and($byNumber['sends'][0]['error'])->toBe('carrier')
        ->and(SmsSend::count())->toBe(5);
});
