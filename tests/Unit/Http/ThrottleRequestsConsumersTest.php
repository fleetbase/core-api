<?php

use Fleetbase\Http\Middleware\ThrottleRequests;
use Fleetbase\Support\ApiRateLimits;
use Fleetbase\Tests\Fixtures\Support\RedisMetricsFake;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

/**
 * Binds a real array cache (seeded with the given effective settings) and an in-memory
 * Redis, so the throttle reads administrator settings and records consumer metrics.
 */
function throttle_consumers_fixture(array $settings = [], array $config = []): RedisMetricsFake
{
    $container = bind_test_container(array_merge([
        'app.env'                      => 'testing',
        'api.throttle.enabled'         => true,
        'api.throttle.max_attempts'    => 120,
        'api.throttle.decay_minutes'   => 1,
        'api.throttle.track_consumers' => true,
        'api.throttle.unlimited_keys'  => [],
    ], $config));

    $container->instance('cache', new CacheRepository(new ArrayStore()));
    $redis = new RedisMetricsFake();
    $container->instance('redis', $redis);
    Facade::clearResolvedInstances();

    if ($settings !== []) {
        cache()->put(ApiRateLimits::SETTINGS_CACHE_KEY, $settings, 60);
    }

    return $redis;
}

function throttle_consumers_identify(string $credential, array $consumer): void
{
    cache()->put(ApiRateLimits::CONSUMER_CACHE_PREFIX . sha1($credential), $consumer, 600);
}

function throttle_consumers_request(string $uri = '/v1/orders', array $server = [], ?object $user = null): Request
{
    $request = Request::create($uri, 'GET', [], [], [], array_merge(['REMOTE_ADDR' => '10.0.0.5'], $server));
    $request->setRouteResolver(fn () => new Illuminate\Routing\Route(['GET'], ltrim($uri, '/'), fn () => null));
    $request->setUserResolver(fn () => $user);

    return $request;
}

function throttle_consumers_send(ThrottleRequests $middleware, Request $request): int
{
    try {
        return $middleware->handle($request, fn () => new JsonResponse(['ok' => true]))->getStatusCode();
    } catch (ThrottleRequestsException $exception) {
        return $exception->getStatusCode();
    }
}

function throttle_consumers_middleware(): ThrottleRequests
{
    return new ThrottleRequests(new RateLimiter(new CacheRepository(new ArrayStore())));
}

/**
 * The consumer descriptions the middleware stored, keyed by limiter signature.
 */
function throttle_consumers_recorded(RedisMetricsFake $redis): array
{
    $recorded = [];
    foreach ($redis->strings as $key => $value) {
        $recorded[substr($key, strlen('api_consumer_metrics:consumer:'))] = json_decode($value, true);
    }

    return $recorded;
}

function throttle_consumers_count(RedisMetricsFake $redis, string $metric): array
{
    return $redis->sortedSets['api_consumer_metrics:m:202607181230:' . $metric] ?? [];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:30:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    config(['api.throttle' => array_diff_key((array) config('api.throttle', []), ['track_consumers' => true])]);
    app()->forgetInstance('redis');
    Facade::clearResolvedInstances();
});

test('throttle requests counts every request and every rejection per consumer', function () {
    $redis      = throttle_consumers_fixture(['max_attempts' => 2]);
    $middleware = throttle_consumers_middleware();
    throttle_consumers_identify('Bearer flb_live_noisy', ['type' => 'api_key', 'label' => 'Noisy', 'company_uuid' => 'company-acme']);

    $statuses = [];
    foreach (range(1, 3) as $i) {
        $statuses[] = throttle_consumers_send($middleware, throttle_consumers_request('/v1/orders', ['HTTP_AUTHORIZATION' => 'Bearer flb_live_noisy']));
    }

    $signature = sha1('fleetbase-throttle|v1|credential|Bearer flb_live_noisy');

    expect($statuses)->toBe([200, 200, 429])
        ->and(throttle_consumers_count($redis, 'hits'))->toBe([$signature => 3])
        ->and(throttle_consumers_count($redis, 'throttled'))->toBe([$signature => 1])
        ->and(throttle_consumers_recorded($redis)[$signature])->toBe([
            'type'         => 'api_key',
            'label'        => 'Noisy',
            'company_uuid' => 'company-acme',
            'scope'        => 'v1',
            'ip'           => '10.0.0.5',
            'limit'        => 2,
            'last_seen_at' => '2026-07-18T12:30:00+00:00',
        ]);
});

test('throttle requests applies organization overrides to the consumer behind a credential', function () {
    $redis = throttle_consumers_fixture(ApiRateLimits::normalize([
        'max_attempts' => 1,
        'overrides'    => [
            ['company_uuid' => 'company-partner', 'max_attempts' => 3],
            ['company_uuid' => 'company-internal', 'unlimited' => true],
        ],
    ]));
    $middleware = throttle_consumers_middleware();
    throttle_consumers_identify('Bearer flb_live_partner', ['type' => 'api_key', 'label' => 'Partner', 'company_uuid' => 'company-partner']);
    throttle_consumers_identify('Bearer flb_live_internal', ['type' => 'api_key', 'label' => 'Internal', 'company_uuid' => 'company-internal']);

    $send = fn (string $credential) => throttle_consumers_send($middleware, throttle_consumers_request('/v1/orders', ['HTTP_AUTHORIZATION' => $credential]));

    $partner  = array_map(fn () => $send('Bearer flb_live_partner'), range(1, 4));
    $internal = array_map(fn () => $send('Bearer flb_live_internal'), range(1, 5));
    $other    = array_map(fn () => $send('Bearer flb_live_other'), range(1, 2));

    $recorded = throttle_consumers_recorded($redis);

    expect($partner)->toBe([200, 200, 200, 429])
        ->and($internal)->toBe([200, 200, 200, 200, 200])
        ->and($other)->toBe([200, 429])
        ->and($recorded[sha1('fleetbase-throttle|v1|credential|Bearer flb_live_partner')]['limit'])->toBe(3)
        ->and($recorded[sha1('fleetbase-throttle|v1|credential|Bearer flb_live_internal')])->toMatchArray(['label' => 'Internal', 'limit' => null])
        // No cached identity and no database: the credential is reported as unrecognized.
        ->and($recorded[sha1('fleetbase-throttle|v1|credential|Bearer flb_live_other')])->toMatchArray(['type' => 'unknown', 'limit' => 1]);
});

test('throttle requests still counts consumers when throttling is disabled or the key is unlimited', function () {
    $redis      = throttle_consumers_fixture(['enabled' => false]);
    $middleware = throttle_consumers_middleware();

    $disabled = throttle_consumers_send($middleware, throttle_consumers_request('/v1/orders', [], new GenericUser(['id' => 'user-1', 'name' => 'Ada', 'company_uuid' => 'company-acme'])));

    expect($disabled)->toBe(200)
        ->and(throttle_consumers_recorded($redis)[sha1('fleetbase-throttle|v1|user|user-1')])->toMatchArray([
            'type'         => 'user',
            'label'        => 'Ada',
            'company_uuid' => 'company-acme',
            'limit'        => null,
        ]);

    $redis = throttle_consumers_fixture([], ['api.throttle.unlimited_keys' => ['Bearer load-test']]);

    $unlimited = throttle_consumers_send($middleware, throttle_consumers_request('/v1/orders', ['HTTP_AUTHORIZATION' => 'Bearer load-test']));

    expect($unlimited)->toBe(200)
        ->and(throttle_consumers_recorded($redis)[sha1('fleetbase-throttle|v1|credential|Bearer load-test')])->toMatchArray([
            'type'  => 'unknown',
            'limit' => null,
        ]);
});

test('throttle requests describes users without a name and anonymous callers by ip', function () {
    $redis      = throttle_consumers_fixture();
    $middleware = throttle_consumers_middleware();

    throttle_consumers_send($middleware, throttle_consumers_request('/int/v1/orders', [], new GenericUser(['id' => 'user-2', 'email' => 'grace@example.test'])));
    throttle_consumers_send($middleware, throttle_consumers_request('/int/v1/orders', [], new GenericUser(['id' => 'user-3'])));
    throttle_consumers_send($middleware, throttle_consumers_request('/', ['REMOTE_ADDR' => '203.0.113.9']));

    $recorded = throttle_consumers_recorded($redis);

    expect($recorded[sha1('fleetbase-throttle|int|user|user-2')])->toMatchArray(['type' => 'user', 'label' => 'grace@example.test', 'company_uuid' => null, 'scope' => 'int', 'limit' => 120])
        ->and($recorded[sha1('fleetbase-throttle|int|user|user-3')])->toMatchArray(['type' => 'user', 'label' => 'user-3'])
        ->and($recorded[sha1('fleetbase-throttle||ip|203.0.113.9')])->toMatchArray([
            'type'         => 'ip',
            'label'        => '203.0.113.9',
            'company_uuid' => null,
            'scope'        => '',
            'ip'           => '203.0.113.9',
        ]);
});

test('throttle requests skips consumer lookups and metrics when tracking is off and there are no overrides', function () {
    $redis      = throttle_consumers_fixture(['track_consumers' => false, 'max_attempts' => 1]);
    $middleware = throttle_consumers_middleware();
    throttle_consumers_identify('Bearer flb_live_key', ['type' => 'api_key', 'label' => 'Key', 'company_uuid' => 'company-acme']);

    $statuses = [
        throttle_consumers_send($middleware, throttle_consumers_request('/v1/orders', ['HTTP_AUTHORIZATION' => 'Bearer flb_live_key'])),
        throttle_consumers_send($middleware, throttle_consumers_request('/v1/orders', ['HTTP_AUTHORIZATION' => 'Bearer flb_live_key'])),
    ];

    expect($statuses)->toBe([200, 429])
        ->and($redis->commands)->toBe([]);
});
