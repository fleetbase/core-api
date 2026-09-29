<?php

namespace Fleetbase\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * Per-consumer API request and throttle counts, for the admin "API consumers" view.
 *
 * The request log cannot answer "who is hammering the API": it records writes only, and a
 * throttled request is rejected before it is ever logged. So the throttle middleware counts
 * every request it sees here, per consumer, into Redis sorted sets bucketed by minute (kept
 * for two hours) and by hour (kept for eight days). One pipelined round trip per request.
 *
 * Without Redis nothing is recorded and the view reports metrics as unavailable; a Redis
 * failure never fails the request.
 */
class ApiConsumerMetrics
{
    public const PREFIX = 'api_consumer_metrics';

    public const MINUTE_RETENTION = 7200;

    public const HOUR_RETENTION = 691200;

    /**
     * Windows the admin view offers, in minutes.
     */
    public const WINDOWS = [5, 15, 60, 360, 1440, 10080];

    /**
     * Count one request for a consumer.
     *
     * @param string $signature the consumer's limiter signature
     * @param array  $consumer  descriptive fields shown in the admin view
     * @param bool   $throttled whether the request was rejected with 429
     */
    public static function record(string $signature, array $consumer, bool $throttled = false, ?Carbon $at = null): void
    {
        $at ??= Carbon::now();
        $minute = static::minuteBucket($at);
        $hour   = static::hourBucket($at);

        $consumer['last_seen_at'] = $at->toIso8601String();

        try {
            static::connection()->pipeline(function ($pipe) use ($signature, $consumer, $throttled, $minute, $hour) {
                $metrics = $throttled ? ['hits', 'throttled'] : ['hits'];
                foreach ($metrics as $metric) {
                    $pipe->zincrby(static::key('m', $minute, $metric), 1, $signature);
                    $pipe->expire(static::key('m', $minute, $metric), static::MINUTE_RETENTION);
                    $pipe->zincrby(static::key('h', $hour, $metric), 1, $signature);
                    $pipe->expire(static::key('h', $hour, $metric), static::HOUR_RETENTION);
                }

                $pipe->setex(static::PREFIX . ':consumer:' . $signature, static::HOUR_RETENTION, json_encode($consumer));
            });
        } catch (\Throwable $e) {
            // Metrics are best-effort; never fail the request over them.
        }
    }

    /**
     * The busiest consumers over a window, with platform totals and a request timeline.
     *
     * @param int    $windowMinutes one of WINDOWS
     * @param string $sort          "hits" or "throttled"
     */
    public static function top(int $windowMinutes = 15, string $sort = 'hits', int $limit = 50, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $sort = $sort === 'throttled' ? 'throttled' : 'hits';

        try {
            $redis                   = static::connection();
            [$granularity, $buckets] = static::buckets($windowMinutes, $now);

            $results = $redis->pipeline(function ($pipe) use ($buckets, $granularity) {
                foreach ($buckets as $bucket) {
                    $pipe->zrangebyscore(static::key($granularity, $bucket, 'hits'), '-inf', '+inf', ['withscores' => true]);
                    $pipe->zrangebyscore(static::key($granularity, $bucket, 'throttled'), '-inf', '+inf', ['withscores' => true]);
                }
            });
        } catch (\Throwable $e) {
            return static::unavailable($windowMinutes, $sort);
        }

        $consumers = [];
        $series    = [];
        foreach ($buckets as $index => $bucket) {
            $hits      = static::scores($results[$index * 2] ?? []);
            $throttled = static::scores($results[$index * 2 + 1] ?? []);

            $series[] = [
                'bucket'    => static::bucketTime($granularity, $bucket)->toIso8601String(),
                'hits'      => array_sum($hits),
                'throttled' => array_sum($throttled),
            ];

            foreach (['hits' => $hits, 'throttled' => $throttled] as $metric => $scores) {
                foreach ($scores as $signature => $count) {
                    $consumers[$signature] ??= ['signature' => $signature, 'hits' => 0, 'throttled' => 0, 'peak_per_minute' => 0];
                    $consumers[$signature][$metric] += $count;
                    if ($metric === 'hits' && $granularity === 'm') {
                        $consumers[$signature]['peak_per_minute'] = max($consumers[$signature]['peak_per_minute'], $count);
                    }
                }
            }
        }

        $totalHits      = array_sum(array_column($consumers, 'hits'));
        $totalThrottled = array_sum(array_column($consumers, 'throttled'));

        uasort($consumers, fn ($a, $b) => [$b[$sort], $b['hits']] <=> [$a[$sort], $a['hits']]);
        $top = array_slice(array_values($consumers), 0, max(1, $limit));

        $details = static::details(array_column($top, 'signature'));
        $top     = array_map(function (array $row) use ($details, $totalHits, $windowMinutes) {
            $row = array_merge($details[$row['signature']] ?? [], $row);

            $row['share']           = $totalHits > 0 ? round($row['hits'] / $totalHits * 100, 2) : 0;
            $row['avg_per_minute']  = round($row['hits'] / max(1, $windowMinutes), 2);
            $row['peak_per_minute'] = $row['peak_per_minute'] ?: null;

            return $row;
        }, $top);

        return [
            'available'      => true,
            'window'         => $windowMinutes,
            'granularity'    => $granularity === 'm' ? 'minute' : 'hour',
            'sort'           => $sort,
            'total_requests' => $totalHits,
            'total_throttled'=> $totalThrottled,
            'consumer_count' => count($consumers),
            'consumers'      => $top,
            'series'         => $series,
        ];
    }

    /**
     * Stored descriptions for a set of consumer signatures.
     */
    protected static function details(array $signatures): array
    {
        if (empty($signatures)) {
            return [];
        }

        try {
            $values = static::connection()->mget(array_map(fn ($signature) => static::PREFIX . ':consumer:' . $signature, $signatures));
        } catch (\Throwable $e) {
            return [];
        }

        $details = [];
        foreach (array_values($signatures) as $index => $signature) {
            $decoded             = is_string($values[$index] ?? null) ? json_decode($values[$index], true) : null;
            $details[$signature] = is_array($decoded) ? $decoded : [];
        }

        return $details;
    }

    /**
     * Minute buckets for windows up to two hours, hour buckets beyond, oldest first.
     */
    protected static function buckets(int $windowMinutes, Carbon $now): array
    {
        $windowMinutes = max(1, $windowMinutes);

        if ($windowMinutes <= 120) {
            $buckets = [];
            for ($i = $windowMinutes - 1; $i >= 0; $i--) {
                $buckets[] = static::minuteBucket($now->copy()->subMinutes($i));
            }

            return ['m', $buckets];
        }

        $hours   = (int) ceil($windowMinutes / 60);
        $buckets = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $buckets[] = static::hourBucket($now->copy()->subHours($i));
        }

        return ['h', $buckets];
    }

    /**
     * Normalize a zrangebyscore WITHSCORES reply to [member => int].
     */
    protected static function scores($reply): array
    {
        if (!is_array($reply)) {
            return [];
        }

        // Some clients reply with a flat [member, score, member, score] list.
        if (!empty($reply) && array_keys($reply) === range(0, count($reply) - 1) && count($reply) % 2 === 0 && !is_numeric($reply[0])) {
            $pairs = [];
            for ($i = 0; $i < count($reply); $i += 2) {
                $pairs[$reply[$i]] = $reply[$i + 1];
            }
            $reply = $pairs;
        }

        return array_map(fn ($score) => (int) $score, $reply);
    }

    protected static function unavailable(int $windowMinutes, string $sort): array
    {
        return [
            'available'       => false,
            'window'          => $windowMinutes,
            'granularity'     => $windowMinutes <= 120 ? 'minute' : 'hour',
            'sort'            => $sort,
            'total_requests'  => 0,
            'total_throttled' => 0,
            'consumer_count'  => 0,
            'consumers'       => [],
            'series'          => [],
        ];
    }

    protected static function key(string $granularity, string $bucket, string $metric): string
    {
        return static::PREFIX . ':' . $granularity . ':' . $bucket . ':' . $metric;
    }

    protected static function minuteBucket(Carbon $at): string
    {
        return $at->copy()->utc()->format('YmdHi');
    }

    protected static function hourBucket(Carbon $at): string
    {
        return $at->copy()->utc()->format('YmdH');
    }

    protected static function bucketTime(string $granularity, string $bucket): Carbon
    {
        return Carbon::createFromFormat($granularity === 'm' ? '!YmdHi' : '!YmdH', $bucket, 'UTC');
    }

    protected static function connection()
    {
        return Redis::connection(config('api.throttle.metrics_connection', 'cache'));
    }
}
