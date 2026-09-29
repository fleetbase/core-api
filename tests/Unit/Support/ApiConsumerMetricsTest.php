<?php

use Fleetbase\Support\ApiConsumerMetrics;
use Fleetbase\Tests\Fixtures\Support\RedisMetricsFake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function api_consumer_metrics_redis(array $config = []): RedisMetricsFake
{
    $container = bind_test_container($config);
    $redis     = new RedisMetricsFake();
    $container->instance('redis', $redis);
    Facade::clearResolvedInstance('redis');

    return $redis;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:30:45', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    config(['api.throttle' => array_diff_key((array) config('api.throttle', []), ['metrics_connection' => true])]);
    app()->forgetInstance('redis');
    Facade::clearResolvedInstance('redis');
});

test('api consumer metrics record counts a request into minute and hour buckets and stores the consumer', function () {
    $redis = api_consumer_metrics_redis(['api.throttle.metrics_connection' => 'metrics']);

    ApiConsumerMetrics::record('sig-a', ['type' => 'api_key', 'label' => 'Live key']);

    $consumer = json_decode($redis->strings['api_consumer_metrics:consumer:sig-a'], true);

    expect($redis->connections)->toBe(['metrics'])
        ->and($redis->sortedSets)->toBe([
            'api_consumer_metrics:m:202607181230:hits' => ['sig-a' => 1],
            'api_consumer_metrics:h:2026071812:hits'   => ['sig-a' => 1],
        ])
        ->and($redis->ttls)->toBe([
            'api_consumer_metrics:m:202607181230:hits' => ApiConsumerMetrics::MINUTE_RETENTION,
            'api_consumer_metrics:h:2026071812:hits'   => ApiConsumerMetrics::HOUR_RETENTION,
            'api_consumer_metrics:consumer:sig-a'      => ApiConsumerMetrics::HOUR_RETENTION,
        ])
        ->and($consumer)->toBe([
            'type'         => 'api_key',
            'label'        => 'Live key',
            'last_seen_at' => '2026-07-18T12:30:45+00:00',
        ]);
});

test('api consumer metrics record counts throttled requests as hits and throttles at an explicit time', function () {
    $redis = api_consumer_metrics_redis();

    ApiConsumerMetrics::record('sig-a', [], true, Carbon::parse('2026-07-18 09:05:00', 'UTC'));
    ApiConsumerMetrics::record('sig-a', [], true, Carbon::parse('2026-07-18 09:05:30', 'UTC'));

    expect($redis->connections)->toBe(['cache', 'cache'])
        ->and($redis->sortedSets)->toBe([
            'api_consumer_metrics:m:202607180905:hits'      => ['sig-a' => 2],
            'api_consumer_metrics:h:2026071809:hits'        => ['sig-a' => 2],
            'api_consumer_metrics:m:202607180905:throttled' => ['sig-a' => 2],
            'api_consumer_metrics:h:2026071809:throttled'   => ['sig-a' => 2],
        ]);
});

test('api consumer metrics record never fails the request when redis is unavailable', function () {
    bind_test_container();
    app()->forgetInstance('redis');
    Facade::clearResolvedInstance('redis');

    ApiConsumerMetrics::record('sig-a', ['type' => 'ip']);

    $redis                 = api_consumer_metrics_redis();
    $redis->pipelineThrows = true;

    ApiConsumerMetrics::record('sig-a', ['type' => 'ip']);

    expect($redis->commands)->toBe([]);
});

test('api consumer metrics top aggregates minute buckets into ranked consumers with details and a timeline', function () {
    $redis = api_consumer_metrics_redis();
    $now   = Carbon::now();

    foreach (range(1, 3) as $i) {
        ApiConsumerMetrics::record('sig-busy', ['label' => 'Busy key'], false, $now->copy()->subMinutes(2));
    }
    ApiConsumerMetrics::record('sig-busy', ['label' => 'Busy key'], false, $now);
    ApiConsumerMetrics::record('sig-busy', ['label' => 'Busy key'], true, $now);
    ApiConsumerMetrics::record('sig-quiet', ['label' => 'Quiet key'], false, $now);
    // Outside a five-minute window.
    ApiConsumerMetrics::record('sig-old', ['label' => 'Old key'], false, $now->copy()->subMinutes(10));

    $top = ApiConsumerMetrics::top(5, 'hits', 50);

    expect($top)->toMatchArray([
        'available'       => true,
        'window'          => 5,
        'granularity'     => 'minute',
        'sort'            => 'hits',
        'total_requests'  => 6,
        'total_throttled' => 1,
        'consumer_count'  => 2,
    ])
        ->and($top['consumers'])->toHaveCount(2)
        ->and($top['consumers'][0])->toMatchArray([
            'label'           => 'Busy key',
            'last_seen_at'    => '2026-07-18T12:30:45+00:00',
            'signature'       => 'sig-busy',
            'hits'            => 5,
            'throttled'       => 1,
            'peak_per_minute' => 3,
            'share'           => 83.33,
            'avg_per_minute'  => 1.0,
        ])
        ->and($top['consumers'][1])->toMatchArray([
            'label'           => 'Quiet key',
            'signature'       => 'sig-quiet',
            'hits'            => 1,
            'throttled'       => 0,
            'peak_per_minute' => 1,
            'share'           => 16.67,
            'avg_per_minute'  => 0.2,
        ])
        ->and($top['series'])->toBe([
            ['bucket' => '2026-07-18T12:26:00+00:00', 'hits' => 0, 'throttled' => 0],
            ['bucket' => '2026-07-18T12:27:00+00:00', 'hits' => 0, 'throttled' => 0],
            ['bucket' => '2026-07-18T12:28:00+00:00', 'hits' => 3, 'throttled' => 0],
            ['bucket' => '2026-07-18T12:29:00+00:00', 'hits' => 0, 'throttled' => 0],
            ['bucket' => '2026-07-18T12:30:00+00:00', 'hits' => 3, 'throttled' => 1],
        ]);
});

test('api consumer metrics top sorts by throttled counts and honours the limit', function () {
    $redis = api_consumer_metrics_redis();

    ApiConsumerMetrics::record('sig-busy', [], false);
    ApiConsumerMetrics::record('sig-busy', [], false);
    ApiConsumerMetrics::record('sig-blocked', [], true);

    $byThrottled = ApiConsumerMetrics::top(15, 'throttled', 1);
    $byUnknown   = ApiConsumerMetrics::top(15, 'bogus', 0);

    expect($byThrottled['sort'])->toBe('throttled')
        ->and($byThrottled['consumer_count'])->toBe(2)
        ->and(array_column($byThrottled['consumers'], 'signature'))->toBe(['sig-blocked'])
        ->and($byUnknown['sort'])->toBe('hits')
        ->and(array_column($byUnknown['consumers'], 'signature'))->toBe(['sig-busy']);
});

test('api consumer metrics top uses hour buckets for long windows and omits the per minute peak', function () {
    $redis = api_consumer_metrics_redis();
    $now   = Carbon::now();

    ApiConsumerMetrics::record('sig-a', [], false, $now->copy()->subHours(5));
    ApiConsumerMetrics::record('sig-a', [], false, $now);
    // Older than the six-hour window.
    ApiConsumerMetrics::record('sig-a', [], false, $now->copy()->subHours(7));

    $top = ApiConsumerMetrics::top(360, 'hits', 10, $now);

    expect($top['granularity'])->toBe('hour')
        ->and($top['total_requests'])->toBe(2)
        ->and($top['series'])->toHaveCount(6)
        ->and($top['series'][0])->toBe(['bucket' => '2026-07-18T07:00:00+00:00', 'hits' => 1, 'throttled' => 0])
        ->and($top['series'][5])->toBe(['bucket' => '2026-07-18T12:00:00+00:00', 'hits' => 1, 'throttled' => 0])
        ->and($top['consumers'][0])->toMatchArray([
            'signature'       => 'sig-a',
            'hits'            => 2,
            'peak_per_minute' => null,
            'avg_per_minute'  => 0.01,
        ]);
});

test('api consumer metrics top reads flat withscores replies and ignores malformed ones', function () {
    $redis             = api_consumer_metrics_redis();
    $redis->flatScores = true;

    ApiConsumerMetrics::record('sig-a', ['label' => 'A']);
    ApiConsumerMetrics::record('sig-a', ['label' => 'A']);
    ApiConsumerMetrics::record('sig-b', ['label' => 'B'], true);

    $flat = ApiConsumerMetrics::top(1);

    $redis->pipelineReply = [false, 'not-a-reply'];
    $malformed            = ApiConsumerMetrics::top(1);

    expect($flat['total_requests'])->toBe(3)
        ->and($flat['total_throttled'])->toBe(1)
        ->and(array_column($flat['consumers'], 'hits', 'signature'))->toBe(['sig-a' => 2, 'sig-b' => 1])
        ->and($malformed)->toMatchArray([
            'available'       => true,
            'total_requests'  => 0,
            'consumer_count'  => 0,
            'consumers'       => [],
            'series'          => [['bucket' => '2026-07-18T12:30:00+00:00', 'hits' => 0, 'throttled' => 0]],
        ]);
});

test('api consumer metrics top keeps counts without details when stored details are missing or unreadable', function () {
    $redis = api_consumer_metrics_redis();

    ApiConsumerMetrics::record('sig-a', ['label' => 'A']);
    ApiConsumerMetrics::record('sig-b', ['label' => 'B'], true);
    $redis->strings['api_consumer_metrics:consumer:sig-a'] = '"not an object"';
    unset($redis->strings['api_consumer_metrics:consumer:sig-b']);

    $withoutDetails = ApiConsumerMetrics::top(1);

    $redis->mgetThrows = true;
    $detailsDown       = ApiConsumerMetrics::top(1);

    expect($withoutDetails['consumers'][0])->not->toHaveKey('label')
        ->and($withoutDetails['consumers'][1])->not->toHaveKey('label')
        ->and($withoutDetails['consumers'][1])->toMatchArray(['signature' => 'sig-b', 'hits' => 1, 'throttled' => 1, 'share' => 50.0])
        ->and($detailsDown['consumers'])->toHaveCount(2)
        ->and($detailsDown['consumers'][0])->not->toHaveKey('label');
});

test('api consumer metrics top reports throttles without hits as a zero share', function () {
    $redis                = api_consumer_metrics_redis();
    $redis->pipelineReply = [[], ['sig-a' => '2']];

    $top = ApiConsumerMetrics::top(0);

    expect($top['window'])->toBe(0)
        ->and($top['series'])->toHaveCount(1)
        ->and($top['consumers'][0])->toMatchArray([
            'signature'       => 'sig-a',
            'hits'            => 0,
            'throttled'       => 2,
            'share'           => 0,
            'avg_per_minute'  => 0.0,
            'peak_per_minute' => null,
        ]);
});

test('api consumer metrics top reports metrics as unavailable when redis fails', function () {
    $redis                 = api_consumer_metrics_redis();
    $redis->pipelineThrows = true;

    expect(ApiConsumerMetrics::top(15, 'throttled'))->toBe([
        'available'       => false,
        'window'          => 15,
        'granularity'     => 'minute',
        'sort'            => 'throttled',
        'total_requests'  => 0,
        'total_throttled' => 0,
        'consumer_count'  => 0,
        'consumers'       => [],
        'series'          => [],
    ])
        ->and(ApiConsumerMetrics::top(1440)['granularity'])->toBe('hour');
});
