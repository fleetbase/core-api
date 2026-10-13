<?php

use Fleetbase\Models\Setting;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsPolicy;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function sms_policy_config(array $overrides = []): array
{
    return SmsPolicy::merge([
        'enabled'      => true,
        'mode'         => 'enforce',
        'alert_emails' => 'ops@example.test, OPS@example.test, not-an-email',
        'validation'   => [
            'enabled'           => true,
            'allowed_types'     => ['mobile', 'fixed_line_or_mobile'],
            'blocked_countries' => 'kp, ir',
            'blocked_prefixes'  => '+881,+882',
        ],
        'limits' => [
            'destination' => ['cooldown_seconds' => 60, 'per_hour' => 3, 'per_day' => 5],
            'ip'          => ['per_15_minutes' => 5, 'per_day' => 20],
            'company'     => ['per_minute' => 10, 'per_hour' => 60, 'per_day' => 200],
            'tenant'      => ['per_minute' => null, 'per_hour' => null, 'per_day' => null],
            'probation'   => ['days' => 7, 'per_day' => 20],
            'platform'    => [
                'public'  => ['per_minute' => 100, 'per_hour' => 1500, 'per_day' => 10000],
                'trusted' => ['per_minute' => 100, 'per_hour' => 1500, 'per_day' => 10000],
            ],
        ],
        'breaker' => [
            'pause_minutes'                 => 60,
            'alert_percentages'             => [50, 80],
            'distinct_destinations_per_10m' => 25,
            'auto_pauses_before_manual'     => 2,
        ],
        'purposes' => [
            '2fa'              => ['trusted' => true, 'destination' => ['cooldown_seconds' => 20, 'per_hour' => 10]],
            'storefront_login' => ['destination' => ['cooldown_seconds' => 30]],
        ],
    ], $overrides);
}

function sms_policy_database(array $guard = []): Capsule
{
    EloquentModel::clearBootedModels();
    session()->flush();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'sms.guard'                  => sms_policy_config($guard),
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new CacheRepository(new ArrayStore()));
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
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $db = $capsule->getConnection('mysql');
    $db->table('companies')->insert([
        ['uuid' => 'company-old', 'public_id' => 'company_old', 'name' => 'Old Co', 'created_at' => Carbon::now()->subDays(30), 'updated_at' => Carbon::now()],
        ['uuid' => 'company-new', 'public_id' => 'company_new', 'name' => 'New Co', 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()],
    ]);
    $db->table('users')->insert([
        ['uuid' => 'admin-1', 'type' => 'admin', 'name' => 'Root', 'email' => 'Root@example.test', 'deleted_at' => null],
        ['uuid' => 'admin-2', 'type' => 'admin', 'name' => 'Deleted', 'email' => 'gone@example.test', 'deleted_at' => Carbon::now()],
        ['uuid' => 'user-1', 'type' => 'user', 'name' => 'Member', 'email' => 'member@example.test', 'deleted_at' => null],
    ]);

    return $capsule;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('sms policy defaults are normalised from the configuration', function () {
    bind_test_container(['sms.guard' => sms_policy_config(['mode' => 'Observe', 'breaker' => ['alert_percentages' => '50, 80, 100, 0']])]);

    $defaults = SmsPolicy::defaults();

    expect($defaults['enabled'])->toBeTrue()
        ->and($defaults['mode'])->toBe('enforce')
        ->and($defaults['alert_emails'])->toBe(['ops@example.test'])
        ->and($defaults['validation']['allowed_types'])->toBe(['MOBILE', 'FIXED_LINE_OR_MOBILE'])
        ->and($defaults['validation']['blocked_countries'])->toBe(['KP', 'IR'])
        ->and($defaults['validation']['blocked_prefixes'])->toBe(['+881', '+882'])
        ->and($defaults['limits']['company'])->toBe(['per_minute' => 10, 'per_hour' => 60, 'per_day' => 200])
        ->and($defaults['limits']['tenant'])->toBe(['per_minute' => null, 'per_hour' => null, 'per_day' => null])
        ->and($defaults['breaker']['alert_percentages'])->toBe([50, 80])
        ->and($defaults['purposes']['2fa'])->toBe(['trusted' => true, 'destination' => ['cooldown_seconds' => 20, 'per_hour' => 10, 'per_day' => null]])
        ->and($defaults['overrides'])->toBe([])
        ->and($defaults['pauses'])->toBe([]);
});

test('sms policy falls back to the defaults when there is no database or cache', function () {
    bind_test_container(['sms.guard' => sms_policy_config()]);
    Facade::clearResolvedInstances();

    expect(SmsPolicy::stored())->toBe([])
        ->and(SmsPolicy::settings()['mode'])->toBe('enforce')
        ->and(SmsPolicy::isEnabled())->toBeTrue()
        ->and(SmsPolicy::mode())->toBe('enforce')
        ->and(SmsPolicy::isOnProbation('company-new', 7))->toBeFalse()
        ->and(SmsPolicy::alertRecipients())->toBe(['ops@example.test']);
});

test('sms policy applies the stored administrator override and company exceptions', function () {
    sms_policy_database();

    Setting::configureSystem(SmsPolicy::SETTING_KEY, [
        'mode'      => 'monitor',
        'limits'    => ['company' => ['per_day' => 50]],
        'overrides' => [
            ['company_uuid' => 'company-old', 'per_day' => 2000, 'note' => 'large client'],
            ['company_uuid' => 'company-new', 'unlimited' => true],
            ['company_uuid' => '', 'per_day' => 1],
        ],
        'pauses' => ['company:company-old' => ['until' => null, 'reason' => 'manual', 'auto' => false]],
    ]);
    SmsPolicy::flush();

    $settings = SmsPolicy::settings();
    $raised   = SmsPolicy::limitsFor(new SmsContext('storefront_create_customer', '+447400100123', 'company-old', 'store', 'store-1', 'storefront', '203.0.113.9'), $settings);
    $plain    = SmsPolicy::limitsFor(new SmsContext('storefront_create_customer', '+447400100123', 'company-other'), $settings);
    $noLimit  = SmsPolicy::limitsFor(new SmsContext('storefront_create_customer', '+447400100123', 'company-new'), $settings);

    expect($settings['mode'])->toBe('monitor')
        ->and($settings['limits']['company']['per_day'])->toBe(50)
        ->and($settings['limits']['company']['per_hour'])->toBe(60)
        ->and($settings['overrides'])->toHaveCount(2)
        ->and($settings['pauses']['company:company-old']['reason'])->toBe('manual')
        ->and(SmsPolicy::overrideFor('company-old', $settings)['note'])->toBe('large client')
        ->and(SmsPolicy::overrideFor(null, $settings))->toBeNull()
        ->and($raised['company']['per_day'])->toBe(2000)
        ->and($raised['company']['per_hour'])->toBe(60)
        ->and($raised['tenant'])->toBeNull()
        ->and($raised['probation'])->toBeFalse()
        ->and($plain['company']['per_day'])->toBe(50)
        ->and($noLimit['company'])->toBeNull()
        ->and($noLimit['company_unlimited'])->toBeTrue();
});

test('sms policy update keeps the rest of the stored override and reset drops it', function () {
    sms_policy_database();

    SmsPolicy::update(['mode' => 'monitor']);
    SmsPolicy::update(['limits' => ['company' => ['per_day' => 75]]]);

    $settings = SmsPolicy::settings();
    expect($settings['mode'])->toBe('monitor')
        ->and($settings['limits']['company']['per_day'])->toBe(75)
        ->and(SmsPolicy::stored())->toBe(['mode' => 'monitor', 'limits' => ['company' => ['per_day' => 75]]]);

    $reset = SmsPolicy::reset();
    expect($reset['mode'])->toBe('enforce')
        ->and($reset['limits']['company']['per_day'])->toBe(200)
        ->and(SmsPolicy::stored())->toBe([]);
});

test('sms policy puts young companies on probation and exempts trusted purposes from the company budget', function () {
    sms_policy_database();

    $young   = SmsPolicy::limitsFor(new SmsContext('storefront_create_customer', '+447400100123', 'company-new', 'store', 'store-1', 'storefront', '203.0.113.9'));
    $old     = SmsPolicy::limitsFor(new SmsContext('storefront_create_customer', '+447400100123', 'company-old', null, null, 'api', '203.0.113.9'));
    $trusted = SmsPolicy::limitsFor(new SmsContext('2fa', '+447400100123', 'company-new', 'store', 'store-1', 'console', '203.0.113.9'));
    $login   = SmsPolicy::limitsFor(new SmsContext('storefront_login', '+447400100123', 'company-old'));

    expect($young['probation'])->toBeTrue()
        ->and($young['company']['per_day'])->toBe(20)
        ->and($young['bucket'])->toBe('public')
        ->and($young['ip'])->toBe(['per_15_minutes' => 5, 'per_day' => 20])
        ->and($old['probation'])->toBeFalse()
        ->and($old['company']['per_day'])->toBe(200)
        ->and($trusted['trusted'])->toBeTrue()
        ->and($trusted['bucket'])->toBe('trusted')
        ->and($trusted['company'])->toBeNull()
        ->and($trusted['ip'])->toBeNull()
        ->and($trusted['tenant'])->toBeNull()
        ->and($trusted['destination'])->toBe(['cooldown_seconds' => 20, 'per_hour' => 10, 'per_day' => 5])
        ->and($login['destination']['cooldown_seconds'])->toBe(30)
        ->and($login['destination']['per_hour'])->toBe(3)
        ->and(SmsPolicy::isTrusted('2fa'))->toBeTrue()
        ->and(SmsPolicy::isTrusted('storefront_login'))->toBeFalse()
        ->and(SmsPolicy::isOnProbation(null, 7))->toBeFalse()
        ->and(SmsPolicy::isOnProbation('company-new', 0))->toBeFalse()
        ->and(SmsPolicy::isOnProbation('company-missing', 7))->toBeFalse();
});

test('sms policy alerts go to the configured addresses and every live system administrator', function () {
    sms_policy_database();

    expect(SmsPolicy::alertRecipients())->toBe(['ops@example.test', 'root@example.test'])
        ->and(SmsPolicy::adminUsers()->pluck('uuid')->all())->toBe(['admin-1']);
});

test('sms policy merge replaces lists and merges associative arrays', function () {
    $merged = SmsPolicy::merge(
        ['validation' => ['allowed_types' => ['MOBILE', 'FIXED_LINE_OR_MOBILE'], 'enabled' => true], 'mode' => 'enforce'],
        ['validation' => ['allowed_types' => ['MOBILE']], 'mode' => 'monitor', 'extra' => ['a' => 1]]
    );

    expect($merged)->toBe([
        'validation' => ['allowed_types' => ['MOBILE'], 'enabled' => true],
        'mode'       => 'monitor',
        'extra'      => ['a' => 1],
    ]);
});
