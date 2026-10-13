<?php

use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\Notifications\SmsGuardAlert;
use Fleetbase\Support\Sms\SmsBreaker;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsGuard;
use Fleetbase\Support\Sms\SmsPolicy;
use Fleetbase\Support\Sms\SmsReservation;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class SmsGuardTestBreaker extends SmsBreaker
{
    /** @var array<int, SmsGuardAlert> */
    public array $alerts = [];

    protected function dispatchAlert(SmsGuardAlert $alert): void
    {
        $this->alerts[] = $alert;
    }
}

class SmsGuardTestBrokenCache extends CacheRepository
{
    public function add($key, $value, $ttl = null)
    {
        throw new RuntimeException('redis down');
    }
}

/**
 * Stands in for Laravel's CacheManager: only the default store exists.
 */
class SmsGuardTestCacheManager
{
    public function __construct(private CacheRepository $repository)
    {
    }

    public function store(?string $name = null): CacheRepository
    {
        if ($name !== null) {
            throw new RuntimeException('Cache store [' . $name . '] is not defined.');
        }

        return $this->repository;
    }
}

/**
 * @return array{0: SmsGuard, 1: SmsGuardTestBreaker, 2: CacheRepository}
 */
function sms_guard_test_setup(array $guard = [], string $path = '/storefront/v1/customers/request-creation-code', ?CacheRepository $cache = null): array
{
    session()->flush();

    $config = SmsPolicy::merge([
        'enabled'      => true,
        'mode'         => 'enforce',
        'alert_emails' => '',
        'validation'   => ['enabled' => true, 'blocked_prefixes' => '+881,+882'],
        'limits'       => [
            'destination' => ['cooldown_seconds' => 60, 'per_hour' => 3, 'per_day' => 5],
            'ip'          => ['per_15_minutes' => 50, 'per_day' => 100],
            'company'     => ['per_minute' => 100, 'per_hour' => 100, 'per_day' => 100],
            'tenant'      => ['per_minute' => null, 'per_hour' => null, 'per_day' => null],
            'probation'   => ['days' => 0, 'per_day' => 20],
            'platform'    => [
                'public'  => ['per_minute' => 500, 'per_hour' => 500, 'per_day' => 500],
                'trusted' => ['per_minute' => 500, 'per_hour' => 500, 'per_day' => 500],
            ],
        ],
        'breaker' => [
            'pause_minutes'                 => 30,
            'alert_percentages'             => [50],
            'distinct_destinations_per_10m' => 50,
            'auto_pauses_before_manual'     => 2,
        ],
        'purposes' => ['2fa' => ['trusted' => true]],
    ], $guard);

    $container = bind_test_container(['sms.guard' => $config]);
    $cache ??= new CacheRepository(new ArrayStore());
    $container->instance('cache', $cache);
    $container->instance('request', Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'StorefrontApp/3.1']));
    Facade::clearResolvedInstances();
    session(['company' => 'company-1', 'storefront_store' => 'store-1', 'storefront_network' => null]);

    $breaker = new SmsGuardTestBreaker($cache);

    return [new SmsGuard($cache, $breaker), $breaker, $cache];
}

function sms_guard_context(string $destination, string $purpose = 'storefront_create_customer'): SmsContext
{
    return SmsContext::resolve($destination, ['purpose' => $purpose]);
}

function sms_guard_refusal(SmsGuard $guard, SmsContext $context): SmsBlockedException
{
    try {
        $guard->reserve($context);
    } catch (SmsBlockedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the guard to refuse ' . $context->destination);
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    Facade::clearResolvedInstances();
});

test('sms context is resolved from the session and request', function () {
    sms_guard_test_setup();

    $context = sms_guard_context('+447400100123');

    expect($context->companyUuid)->toBe('company-1')
        ->and($context->tenantType)->toBe('store')
        ->and($context->tenantUuid)->toBe('store-1')
        ->and($context->source)->toBe(SmsContext::SOURCE_STOREFRONT)
        ->and($context->ip)->toBe('203.0.113.9')
        ->and($context->userAgent)->toBe('StorefrontApp/3.1')
        ->and($context->isPublic())->toBeTrue()
        ->and($context->companyScope())->toBe('company:company-1')
        ->and($context->tenantScope())->toBe('tenant:store:store-1')
        ->and($context->withDestination('+15550001111')->destination)->toBe('+15550001111')
        ->and(SmsContext::detectSource(Request::create('/v1/drivers/login-with-sms')))->toBe(SmsContext::SOURCE_API)
        ->and(SmsContext::detectSource(Request::create('/int/v1/onboard/send-verification-sms')))->toBe(SmsContext::SOURCE_PUBLIC)
        ->and(SmsContext::detectSource(Request::create('/int/v1/two-fa/resend')))->toBe(SmsContext::SOURCE_CONSOLE)
        ->and(SmsContext::detectSource(Request::create('/public/track/ABC/codes')))->toBe(SmsContext::SOURCE_PUBLIC)
        ->and(SmsContext::detectSource(Request::create('/')))->toBe(SmsContext::SOURCE_SYSTEM)
        ->and(SmsContext::detectSource(null))->toBe(SmsContext::SOURCE_SYSTEM);
});

test('sms guard is bypassed entirely when disabled', function () {
    [$guard] = sms_guard_test_setup(['enabled' => false]);

    $reservation = $guard->reserve(sms_guard_context('not-a-number'));

    expect($reservation->decision)->toBe(SmsReservation::DECISION_BYPASSED)
        ->and($reservation->isGuarded())->toBeFalse()
        ->and($reservation->trace())->toBe([]);
});

test('sms guard refuses destinations that can never be texted with a 422', function () {
    [$guard] = sms_guard_test_setup();

    $invalid = sms_guard_refusal($guard, sms_guard_context('+4471'));
    $premium = sms_guard_refusal($guard, sms_guard_context('+449098790123'));
    $blocked = sms_guard_refusal($guard, sms_guard_context('+88216123456789'));

    expect($invalid->status())->toBe(422)
        ->and($invalid->reason())->toBe('invalid_destination')
        ->and($invalid->headers())->toBe([])
        ->and($premium->status())->toBe(422)
        ->and($premium->reason())->toBe('type_not_allowed')
        ->and($blocked->status())->toBe(422)
        ->and($blocked->reason())->toBeIn(['prefix_blocked', 'type_not_allowed', 'invalid_destination'])
        ->and($guard->limiter()->attempts(SmsGuard::CACHE_PREFIX . 'company:company-1:per_day'))->toBe(0);
});

test('sms guard enforces the per-destination cooldown and caps with a retry-after', function () {
    [$guard] = sms_guard_test_setup(['limits' => ['destination' => ['cooldown_seconds' => 60, 'per_hour' => 2, 'per_day' => 5]]]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00', 'UTC'));

    $first = $guard->reserve(sms_guard_context('+44 7400 100123'));
    $again = sms_guard_refusal($guard, sms_guard_context('+447400100123'));

    Carbon::setTestNow(Carbon::parse('2026-10-13 10:01:01', 'UTC'));
    $second = $guard->reserve(sms_guard_context('+447400100123'));

    Carbon::setTestNow(Carbon::parse('2026-10-13 10:02:02', 'UTC'));
    $third = sms_guard_refusal($guard, sms_guard_context('+447400100123'));
    $other = $guard->reserve(sms_guard_context('+447400100124'));

    expect($first->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($first->context->destination)->toBe('+447400100123')
        ->and($first->country)->toBe('GB')
        ->and($first->type)->toBe('MOBILE')
        ->and($again->status())->toBe(429)
        ->and($again->reason())->toBe('cooldown')
        ->and($again->retryAfter())->toBeGreaterThanOrEqual(1)
        ->and($again->headers())->toHaveKey('Retry-After')
        ->and($second->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($third->reason())->toBe('destination_limit')
        ->and($third->status())->toBe(429)
        ->and($other->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($guard->limiter()->attempts(SmsGuard::CACHE_PREFIX . 'company:company-1:per_day'))->toBe(3)
        ->and($guard->limiter()->attempts(SmsGuard::CACHE_PREFIX . 'platform:public:per_day'))->toBe(3);
});

test('sms guard hits the company budget, pauses the company and alerts the admins once', function () {
    [$guard, $breaker] = sms_guard_test_setup([
        'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null], 'company' => ['per_minute' => 100, 'per_hour' => 100, 'per_day' => 3]],
        'breaker' => ['alert_percentages' => []],
    ]);

    $guard->reserve(sms_guard_context('+447400100001'));
    $guard->reserve(sms_guard_context('+447400100002'));
    $third  = $guard->reserve(sms_guard_context('+447400100003'));
    $paused = sms_guard_refusal($guard, sms_guard_context('+447400100004'));
    $pause  = $breaker->pausedUntil('company:company-1');

    // Staff 2FA for the paused company still goes out: trusted purposes skip tenant pauses
    // and the company budget, and count in their own platform bucket.
    $twoFa = $guard->reserve(sms_guard_context('+447400100005', '2fa'));

    // Another tenant is untouched.
    session(['company' => 'company-2', 'storefront_store' => 'store-9']);
    $otherTenant = $guard->reserve(sms_guard_context('+447400100006'));

    expect($third->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($paused->status())->toBe(423)
        ->and($paused->reason())->toBe(SmsBlockedException::REASON_PAUSED)
        ->and($paused->retryAfter())->toBeGreaterThan(0)
        ->and($paused->context()['scope'])->toBe('company:company-1')
        ->and($pause['auto'])->toBeTrue()
        ->and($pause['reason'])->toBe(SmsBreaker::SIGNAL_COMPANY_DAILY_BUDGET)
        ->and($pause['until'])->toBeGreaterThan(time())
        ->and($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_COMPANY_DAILY_BUDGET)
        ->and($breaker->alerts[0]->data['sends_today'])->toBe(3)
        ->and($breaker->alerts[0]->toArray(null)['title'])->toBe('tenant hit its daily SMS budget')
        ->and($twoFa->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($guard->limiter()->attempts(SmsGuard::CACHE_PREFIX . 'platform:trusted:per_day'))->toBe(1)
        ->and($otherTenant->decision)->toBe(SmsReservation::DECISION_ALLOWED);
});

test('sms guard alerts at the configured share of the daily budget without pausing', function () {
    [$guard, $breaker] = sms_guard_test_setup([
        'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null], 'company' => ['per_minute' => 100, 'per_hour' => 100, 'per_day' => 4]],
        'breaker' => ['alert_percentages' => [50]],
    ]);

    $guard->reserve(sms_guard_context('+447400100001'));
    $guard->reserve(sms_guard_context('+447400100002'));
    $guard->reserve(sms_guard_context('+447400100003'));

    expect($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_BUDGET_THRESHOLD)
        ->and($breaker->alerts[0]->data['percent'])->toBe(50)
        ->and($breaker->pausedUntil('company:company-1'))->toBeNull();
});

test('sms guard pauses a store that texts a burst of distinct numbers, and escalates repeat pauses', function () {
    [$guard, $breaker] = sms_guard_test_setup([
        'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null]],
        'breaker' => ['distinct_destinations_per_10m' => 2, 'alert_percentages' => []],
    ]);

    $guard->reserve(sms_guard_context('+447400100001'));
    $guard->reserve(sms_guard_context('+447400100002'));
    $refused = sms_guard_refusal($guard, sms_guard_context('+447400100003'));
    $first   = $breaker->pausedUntil('tenant:store:store-1');

    // The company itself is not paused, so its other store keeps working.
    session(['company' => 'company-1', 'storefront_store' => 'store-2']);
    $sibling = $guard->reserve(sms_guard_context('+447400100007'));

    session(['company' => 'company-1', 'storefront_store' => 'store-1']);
    $breaker->unpause('tenant:store:store-1');
    $guard->reserve(sms_guard_context('+447400100003'));
    $second = $breaker->pausedUntil('tenant:store:store-1');

    expect($refused->status())->toBe(423)
        ->and($refused->context()['scope'])->toBe('tenant:store:store-1')
        ->and($first['reason'])->toBe(SmsBreaker::SIGNAL_DISTINCT_DESTINATIONS)
        ->and($first['until'])->not->toBeNull()
        ->and($breaker->pausedUntil('company:company-1'))->toBeNull()
        ->and($sibling->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($second)->not->toBeNull()
        ->and($second['until'])->toBeNull()
        ->and($second['reason'])->toContain('administrator must unpause')
        ->and($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_DISTINCT_DESTINATIONS);
});

test('sms guard in monitor mode lets everything through and records what it would have refused', function () {
    [$guard, $breaker] = sms_guard_test_setup([
        'mode'    => 'monitor',
        'limits'  => ['destination' => ['cooldown_seconds' => 60, 'per_hour' => null, 'per_day' => null]],
        'breaker' => ['alert_percentages' => [], 'distinct_destinations_per_10m' => 2],
    ]);

    $invalid  = $guard->reserve(sms_guard_context('+4471'));
    $first    = $guard->reserve(sms_guard_context('+447400100123'));
    $cooldown = $guard->reserve(sms_guard_context('+447400100123'));
    $entries  = collect(app('log')->entries)->pluck(1)->all();

    expect($invalid->decision)->toBe(SmsReservation::DECISION_MONITOR)
        ->and($invalid->wouldBlock)->toBe('invalid_destination')
        ->and($first->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($cooldown->decision)->toBe(SmsReservation::DECISION_MONITOR)
        ->and($cooldown->wouldBlock)->toBe('cooldown')
        ->and($entries)->toContain('sms.would_block')
        ->and($entries)->not->toContain('sms.blocked')
        ->and($breaker->pausedUntil('tenant:store:store-1'))->toBeNull()
        ->and($breaker->pausedUntil('company:company-1'))->toBeNull()
        ->and($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->data['mode'])->toBe('monitor')
        ->and($breaker->alerts[0]->toArray(null)['lines'])->toContain('The guard is in monitor mode, so nothing was paused.');
});

test('sms guard fails closed for public sends and open for trusted ones when the counters are unreachable', function () {
    [$guard] = sms_guard_test_setup([], '/storefront/v1/customers/request-creation-code', new SmsGuardTestBrokenCache(new ArrayStore()));

    $public  = sms_guard_refusal($guard, sms_guard_context('+447400100123'));
    $trusted = $guard->reserve(sms_guard_context('+447400100123', '2fa'));

    [$consoleGuard] = sms_guard_test_setup([], '/int/v1/users/send-verification', new SmsGuardTestBrokenCache(new ArrayStore()));
    $console        = $consoleGuard->reserve(sms_guard_context('+447400100123', 'phone_verification'));

    [$monitorGuard] = sms_guard_test_setup(['mode' => 'monitor'], '/storefront/v1/customers/request-creation-code', new SmsGuardTestBrokenCache(new ArrayStore()));
    $monitored      = $monitorGuard->reserve(sms_guard_context('+447400100123'));

    expect($public->status())->toBe(503)
        ->and($public->reason())->toBe(SmsBlockedException::REASON_UNAVAILABLE)
        ->and($trusted->decision)->toBe(SmsReservation::DECISION_DEGRADED)
        ->and($console->decision)->toBe(SmsReservation::DECISION_DEGRADED)
        ->and($monitored->decision)->toBe(SmsReservation::DECISION_DEGRADED);
});

test('sms guard counters skip ip and tenant layers for trusted purposes and keep guard options from providers', function () {
    [$guard] = sms_guard_test_setup(['limits' => ['tenant' => ['per_minute' => null, 'per_hour' => null, 'per_day' => 40]]]);

    $public  = collect($guard->counters(sms_guard_context('+447400100123'), SmsPolicy::limitsFor(sms_guard_context('+447400100123'))));
    $trusted = collect($guard->counters(sms_guard_context('+447400100123', '2fa'), SmsPolicy::limitsFor(sms_guard_context('+447400100123', '2fa'))));

    expect($public->pluck('scope')->unique()->values()->all())->toBe(['destination', 'ip', 'tenant', 'company', 'platform'])
        ->and($public->firstWhere('scope', 'tenant')['key'])->toBe(SmsGuard::CACHE_PREFIX . 'tenant:store:store-1:per_day')
        ->and($public->firstWhere('scope', 'platform')['key'])->toBe(SmsGuard::CACHE_PREFIX . 'platform:public:per_minute')
        ->and($trusted->pluck('scope')->unique()->values()->all())->toBe(['destination', 'platform'])
        ->and($trusted->firstWhere('scope', 'platform')['key'])->toBe(SmsGuard::CACHE_PREFIX . 'platform:trusted:per_minute')
        ->and(SmsGuard::providerOptions(['purpose' => 'x', 'sms_reservation' => 'r', 'twilioParams' => ['from' => 'ACME'], 'from' => 'ACME']))->toBe(['twilioParams' => ['from' => 'ACME'], 'from' => 'ACME']);
});

test('sms guard settle logs the outcome once per reservation', function () {
    [$guard] = sms_guard_test_setup();

    $reservation = $guard->reserve(sms_guard_context('+447400100123'));
    $guard->settle($reservation, true, ['provider' => 'twilio', 'message_id' => 'SM1']);
    $guard->settle($reservation, false, ['provider' => 'twilio', 'error' => 'ignored, already settled']);

    $failed = $guard->reserve(sms_guard_context('+447400100124'));
    $guard->settle($failed, false, ['provider' => 'twilio', 'error' => 'carrier refused']);

    $bypassed = new SmsReservation(sms_guard_context('+447400100125'), SmsReservation::DECISION_BYPASSED);
    $guard->settle($bypassed, true);

    $entries = collect(app('log')->entries);

    expect($entries->where(1, 'sms.sent'))->toHaveCount(1)
        ->and($entries->where(1, 'sms.sent')->first()[2]['message_id'])->toBe('SM1')
        ->and($entries->where(1, 'sms.failed'))->toHaveCount(1)
        ->and($entries->where(1, 'sms.failed')->first()[2]['error'])->toBe('carrier refused')
        ->and($reservation->settled)->toBeTrue()
        ->and($bypassed->settled)->toBeTrue();
});

test('sms guard make returns a bound instance when the container has one', function () {
    [$guard] = sms_guard_test_setup();
    app()->instance(SmsGuard::class, $guard);

    expect(SmsGuard::make())->toBe($guard);

    app()->forgetInstance(SmsGuard::class);

    expect(SmsGuard::make())->not->toBe($guard);
});

test('sms context takes a network tenant from the session and survives a request that cannot be resolved', function () {
    sms_guard_test_setup();
    session(['company' => 'company-1', 'storefront_store' => null, 'storefront_network' => 'network-1']);
    $network = sms_guard_context('+447400100123');

    app()->bind('request', fn () => throw new RuntimeException('no request bound'));
    $blind = sms_guard_context('+447400100123');
    app()->instance('request', Request::create('/'));

    expect($network->tenantType)->toBe('network')
        ->and($network->tenantUuid)->toBe('network-1')
        ->and($network->tenantScope())->toBe('tenant:network:network-1')
        ->and($blind->ip)->toBeNull()
        ->and($blind->userAgent)->toBeNull()
        ->and($blind->source)->toBe(SmsContext::SOURCE_SYSTEM)
        ->and(SmsContext::detectSource(Request::create('/health')))->toBe(SmsContext::SOURCE_SYSTEM);
});

test('sms breaker lets an expired pause lapse and reads a missing platform counter as absent', function () {
    [$guard, $breaker, $cache] = sms_guard_test_setup([
        'limits'  => ['platform' => ['public' => ['per_minute' => null, 'per_hour' => null, 'per_day' => 500]]],
        'breaker' => ['alert_percentages' => [50]],
    ]);
    $cache->forever(SmsGuard::CACHE_PREFIX . 'pause:company:company-1', ['until' => time() - 5, 'reason' => 'old', 'auto' => true, 'company_uuid' => 'company-1', 'at' => time() - 600]);

    $reservation = $guard->reserve(sms_guard_context('+447400100123'));

    expect($breaker->pausedUntil('company:company-1'))->toBeNull()
        ->and($reservation->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($breaker->alerts)->toBe([])
        ->and(SmsBreaker::companyUuidFromScope('company:company-1'))->toBe('company-1')
        ->and(SmsBreaker::companyUuidFromScope('platform'))->toBeNull();
});

test('sms breaker pauses a tenant that hits its hourly budget in a second hour of the day', function () {
    [$guard, $breaker, $cache] = sms_guard_test_setup([
        'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null], 'company' => ['per_minute' => 100, 'per_hour' => 2, 'per_day' => 100]],
        'breaker' => ['alert_percentages' => []],
    ]);
    // An earlier hour today already reached the budget.
    $cache->put(SmsGuard::CACHE_PREFIX . 'hour-trips:company:company-1', 1, 86400);

    $guard->reserve(sms_guard_context('+447400100001'));
    $second = $guard->reserve(sms_guard_context('+447400100002'));
    $third  = sms_guard_refusal($guard, sms_guard_context('+447400100003'));
    $pause  = $breaker->pausedUntil('company:company-1');

    expect($second->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($pause['reason'])->toBe(SmsBreaker::SIGNAL_COMPANY_HOURLY_REPEAT)
        ->and($third->status())->toBe(423)
        ->and($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_COMPANY_HOURLY_REPEAT)
        ->and($breaker->alerts[0]->data)->toMatchArray(['hours_at_budget_today' => 2, 'budget' => 2])
        ->and($breaker->alerts[0]->toArray(null)['title'])->toBe('tenant hit its hourly SMS budget twice');
});

test('sms breaker alerts when the platform bucket nears its hourly budget and ignores a bucket without one', function () {
    [$guard, $breaker] = sms_guard_test_setup([
        'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null], 'platform' => ['public' => ['per_minute' => 500, 'per_hour' => 4, 'per_day' => 500]]],
        'breaker' => ['alert_percentages' => [50]],
    ]);

    $guard->reserve(sms_guard_context('+447400100001'));
    $guard->reserve(sms_guard_context('+447400100002'));
    $guard->reserve(sms_guard_context('+447400100003'));

    expect($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_PLATFORM_THRESHOLD)
        ->and($breaker->alerts[0]->data)->toMatchArray(['percent' => 50, 'sends_this_hour' => 2, 'budget' => 4, 'scope' => SmsBreaker::SCOPE_PLATFORM])
        ->and($breaker->alerts[0]->toArray(null)['title'])->toBe('platform at 50% of its hourly SMS budget')
        ->and($breaker->pausedUntil(SmsBreaker::SCOPE_PLATFORM))->toBeNull();

    [$unbounded, $quiet] = sms_guard_test_setup([
        'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null], 'platform' => ['public' => ['per_minute' => null, 'per_hour' => null, 'per_day' => null]]],
        'breaker' => ['alert_percentages' => [50]],
    ]);
    $unbounded->reserve(sms_guard_context('+447400100001'));

    expect($quiet->alerts)->toBe([]);
});

test('sms breaker does not pause a scope twice when two signals trip on the same send', function () {
    [$guard, $breaker] = sms_guard_test_setup([
        'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null], 'company' => ['per_minute' => 100, 'per_hour' => 100, 'per_day' => 2]],
        'breaker' => ['distinct_destinations_per_10m' => 2, 'alert_percentages' => []],
    ], '/v1/drivers/login-with-sms');
    // No store or network, so the burst signal lands on the company scope like the budget does.
    session(['company' => 'company-1', 'storefront_store' => null, 'storefront_network' => null]);

    $guard->reserve(sms_guard_context('+447400100001'));
    $guard->reserve(sms_guard_context('+447400100002'));
    $pause = $breaker->pausedUntil('company:company-1');

    expect($pause['reason'])->toBe(SmsBreaker::SIGNAL_COMPANY_DAILY_BUDGET)
        ->and($breaker->alerts)->toHaveCount(1)
        ->and($breaker->alerts[0]->signal)->toBe(SmsBreaker::SIGNAL_COMPANY_DAILY_BUDGET);
});

test('sms breaker skips the tenant signals for a send with no company behind it', function () {
    [$guard, $breaker] = sms_guard_test_setup([], '/v1/drivers/login-with-sms');
    session()->flush();

    $context     = sms_guard_context('+447400100123');
    $reservation = $guard->reserve($context);

    expect($context->companyUuid)->toBeNull()
        ->and($context->tenantScope())->toBeNull()
        ->and($reservation->decision)->toBe(SmsReservation::DECISION_ALLOWED)
        ->and($breaker->alerts)->toBe([])
        ->and($guard->limiter()->attempts(SmsGuard::CACHE_PREFIX . 'ip:platform:203.0.113.9:day'))->toBe(1);
});

test('sms guard in monitor mode records a pause it would have enforced', function () {
    [$guard, , $cache] = sms_guard_test_setup(['mode' => 'monitor', 'limits' => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null]]]);
    $cache->put(SmsGuard::CACHE_PREFIX . 'pause:company:company-1', ['until' => time() + 1800, 'reason' => 'incident', 'auto' => false, 'company_uuid' => 'company-1', 'at' => time()], 1800);

    $reservation = $guard->reserve(sms_guard_context('+447400100123'));

    expect($reservation->decision)->toBe(SmsReservation::DECISION_MONITOR)
        ->and($reservation->wouldBlock)->toBe(SmsBlockedException::REASON_PAUSED)
        ->and(collect(app('log')->entries)->pluck(1)->all())->toContain('sms.would_block');
});

test('sms guard counts on the configured cache store and falls back to the default store when it is missing', function () {
    [, , $cache] = sms_guard_test_setup(['cache_store' => 'sms-counters']);
    app()->instance('cache', new SmsGuardTestCacheManager($cache));
    Facade::clearResolvedInstances();

    $guard = new SmsGuard();
    $guard->limiter()->hit(SmsGuard::CACHE_PREFIX . 'probe', 60);

    expect($cache->get(SmsGuard::CACHE_PREFIX . 'probe'))->toBe(1)
        ->and(collect(app('log')->entries)->pluck(1)->all())->toContain('sms.guard_cache_store_unavailable');

    bind_test_container(['sms.guard' => ['cache_store' => null]]);
    app()->instance('cache', new SmsGuardTestCacheManager($cache));
    Facade::clearResolvedInstances();

    $plain = new SmsGuard();
    $plain->limiter()->hit(SmsGuard::CACHE_PREFIX . 'probe', 60);

    expect($cache->get(SmsGuard::CACHE_PREFIX . 'probe'))->toBe(2)
        ->and($plain->breaker())->toBeInstanceOf(SmsBreaker::class)
        ->and(app('log')->entries)->toBe([]);
});
