<?php

use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\Models\SmsSend;
use Fleetbase\Notifications\SmsGuardAlert;
use Fleetbase\Support\Sms\SmsBreaker;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsGuard;
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
use Illuminate\Support\Str;

class SmsGuardLedgerTestBreaker extends SmsBreaker
{
    /** @var array<int, SmsGuardAlert> */
    public array $alerts = [];

    protected function dispatchAlert(SmsGuardAlert $alert): void
    {
        $this->alerts[] = $alert;
    }
}

/**
 * The guard with a real ledger and verification code table behind it.
 *
 * @return array{0: SmsGuard, 1: SmsGuardLedgerTestBreaker, 2: Capsule}
 */
function sms_guard_ledger_setup(array $guard = []): array
{
    EloquentModel::clearBootedModels();
    session()->flush();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $config = SmsPolicy::merge([
        'enabled'    => true,
        'mode'       => 'enforce',
        'validation' => ['enabled' => true],
        'limits'     => [
            'destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null],
            'ip'          => ['per_15_minutes' => 500, 'per_day' => 500],
            'company'     => ['per_minute' => 500, 'per_hour' => 500, 'per_day' => 500],
            'probation'   => ['days' => 0],
            'platform'    => ['public' => ['per_minute' => 500, 'per_hour' => 500, 'per_day' => 500]],
        ],
        'breaker' => [
            'alert_percentages'             => [],
            'distinct_destinations_per_10m' => 500,
            'new_country_min_sends'         => 3,
            'new_country_min_history'       => 5,
            'conversion_min_issued'         => 20,
            'conversion_min_percent'        => 10,
        ],
    ], $guard);

    $container = bind_test_container([
        'sms.guard'                  => $config,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $cache = new CacheRepository(new ArrayStore());
    $container->instance('cache', $cache);
    $container->instance('request', Request::create('/storefront/v1/customers/request-creation-code', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']));
    Facade::clearResolvedInstances();
    session(['company' => 'company-1', 'storefront_store' => 'store-1']);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    SmsGuardFixture::smsSendsSchema($capsule);
    SmsGuardFixture::verificationCodesSchema($capsule);

    $breaker = new SmsGuardLedgerTestBreaker($cache);

    return [new SmsGuard($cache, $breaker), $breaker, $capsule];
}

function sms_guard_ledger_seed_sent(Capsule $capsule, string $company, string $country, int $count): void
{
    $rows = [];
    foreach (range(1, $count) as $i) {
        $rows[] = [
            'uuid'                => (string) Str::uuid(),
            'company_uuid'        => $company,
            'purpose'             => 'storefront_create_customer',
            'source'              => 'storefront',
            'destination'         => '+4474001' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'destination_country' => $country,
            'status'              => 'sent',
            'created_at'          => Carbon::now()->subDay(),
            'updated_at'          => Carbon::now()->subDay(),
        ];
    }
    $capsule->getConnection('mysql')->table('sms_sends')->insert($rows);
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('an allowed send is reserved on the ledger and settled with the provider outcome', function () {
    [$guard] = sms_guard_ledger_setup();

    $reservation = $guard->reserve(SmsContext::resolve('+44 7400 100123', ['purpose' => 'storefront_create_customer']));
    $guard->settle($reservation, true, ['provider' => 'twilio', 'message_id' => 'SM1']);

    $failed = $guard->reserve(SmsContext::resolve('+447400100124', ['purpose' => 'storefront_create_customer']));
    $guard->settle($failed, false, ['provider' => 'twilio', 'error' => 'carrier refused']);

    $sent = SmsSend::where('uuid', $reservation->sendUuid)->first();
    $lost = SmsSend::where('uuid', $failed->sendUuid)->first();

    expect($reservation->sendUuid)->toBeString()
        ->and($reservation->trace()['sms_send_uuid'])->toBe($reservation->sendUuid)
        ->and($sent->status)->toBe('sent')
        ->and($sent->company_uuid)->toBe('company-1')
        ->and($sent->tenant_uuid)->toBe('store-1')
        ->and($sent->destination)->toBe('+447400100123')
        ->and($sent->destination_country)->toBe('GB')
        ->and($sent->destination_type)->toBe('MOBILE')
        ->and($sent->provider_message_id)->toBe('SM1')
        ->and($sent->ip_address)->toBe('203.0.113.9')
        ->and($sent->meta)->toBe(['decision' => 'allowed'])
        ->and($lost->status)->toBe('failed')
        ->and($lost->error)->toBe('carrier refused');
});

test('a refused send is written to the ledger as blocked with its reason', function () {
    [$guard] = sms_guard_ledger_setup();

    try {
        $guard->reserve(SmsContext::resolve('+4471', ['purpose' => 'storefront_create_customer']));
    } catch (SmsBlockedException $e) {
        // expected
    }

    $guard->breaker()->pause('company:company-1', 30, 'incident');
    try {
        $guard->reserve(SmsContext::resolve('+447400100123', ['purpose' => 'storefront_create_customer']));
    } catch (SmsBlockedException $e) {
        // expected
    }

    $rows = SmsSend::orderBy('created_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe('blocked')
        ->and($rows[0]->block_reason)->toBe('invalid_destination')
        ->and($rows[0]->destination)->toBe('+4471')
        ->and($rows[1]->status)->toBe('blocked')
        ->and($rows[1]->block_reason)->toBe('paused')
        ->and($rows[1]->company_uuid)->toBe('company-1');
});

test('a burst of sends to a country the tenant has never texted pauses it', function () {
    [$guard, $breaker, $capsule] = sms_guard_ledger_setup();

    // Not armed yet: no history. Three sends to a new country go through.
    foreach (['+97699112201', '+97699112202', '+97699112203'] as $number) {
        $guard->reserve(SmsContext::resolve($number, ['purpose' => 'storefront_create_customer']));
    }
    expect($breaker->alerts)->toBe([])
        ->and($breaker->pausedUntil('tenant:store:store-1'))->toBeNull();

    // With history in GB, the same burst to Mongolia trips the breaker on the third send.
    sms_guard_ledger_seed_sent($capsule, 'company-1', 'GB', 6);
    Illuminate\Support\Facades\Cache::forget('sms-guard:countries:company-1');
    Illuminate\Support\Facades\Cache::forget('sms-guard:sent-count:company-1');

    $guard->reserve(SmsContext::resolve('+97699112211', ['purpose' => 'storefront_create_customer']));
    $guard->reserve(SmsContext::resolve('+97699112212', ['purpose' => 'storefront_create_customer']));
    $guard->reserve(SmsContext::resolve('+97699112213', ['purpose' => 'storefront_create_customer']));

    $refused = null;
    try {
        $guard->reserve(SmsContext::resolve('+97699112214', ['purpose' => 'storefront_create_customer']));
    } catch (SmsBlockedException $e) {
        $refused = $e;
    }

    // A known country is still fine for the company's other store.
    session(['company' => 'company-1', 'storefront_store' => 'store-2']);
    $known = $guard->reserve(SmsContext::resolve('+447400100199', ['purpose' => 'storefront_create_customer']));

    expect($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_NEW_COUNTRY)
        ->and($breaker->alerts[0]->data['country'])->toBe('MN')
        ->and($breaker->alerts[0]->data['known_countries'])->toBe(['GB'])
        ->and($breaker->alerts[0]->toArray(null)['title'])->toBe('SMS to a country new to the tenant')
        ->and($breaker->pausedUntil('tenant:store:store-1')['reason'])->toBe(SmsBreaker::SIGNAL_NEW_COUNTRY)
        ->and($refused?->status())->toBe(423)
        ->and($known->decision)->toBe('allowed');
});

test('codes that are sent by sms but never verified pause the company', function () {
    [$guard, $breaker, $capsule] = sms_guard_ledger_setup();
    $codes                       = [];
    foreach (range(1, 20) as $i) {
        $codes[] = [
            'uuid'         => 'code-' . $i,
            'company_uuid' => 'company-1',
            'channel'      => 'sms',
            'code'         => '123456',
            'for'          => 'storefront_create_customer',
            'status'       => 'active',
            'expires_at'   => Carbon::now()->addMinutes(10),
            'consumed_at'  => $i <= 1 ? Carbon::now() : null,
            'created_at'   => Carbon::now()->subMinutes(5),
            'updated_at'   => Carbon::now()->subMinutes(5),
        ];
    }
    $capsule->getConnection('mysql')->table('verification_codes')->insert($codes);

    $guard->reserve(SmsContext::resolve('+447400100123', ['purpose' => 'storefront_create_customer']));

    $refused = null;
    try {
        $guard->reserve(SmsContext::resolve('+447400100124', ['purpose' => 'storefront_create_customer']));
    } catch (SmsBlockedException $e) {
        $refused = $e;
    }

    expect($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_LOW_CONVERSION)
        ->and($breaker->alerts[0]->data)->toMatchArray(['issued_last_hour' => 20, 'verified_last_hour' => 1])
        ->and($breaker->alerts[0]->toArray(null)['title'])->toBe('SMS codes going unverified')
        ->and($breaker->pausedUntil('company:company-1'))->not->toBeNull()
        ->and($refused?->status())->toBe(423);

    // Healthy conversion trips nothing.
    [$healthyGuard, $healthyBreaker, $healthyCapsule] = sms_guard_ledger_setup();
    $healthyCapsule->getConnection('mysql')->table('verification_codes')->insert(array_map(fn ($code) => array_merge($code, ['consumed_at' => Carbon::now()]), $codes));
    $healthyGuard->reserve(SmsContext::resolve('+447400100123', ['purpose' => 'storefront_create_customer']));

    expect($healthyBreaker->alerts)->toBe([]);
});
