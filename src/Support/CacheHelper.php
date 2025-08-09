<?php

namespace Fleetbase\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheHelper extends Cache
{
    /**
     * Delete cache keys matching a given pattern.
     *
     * Works only when the cache driver is Redis.
     * Uses SCAN for production safety instead of KEYS.
     *
     * @param string $pattern Pattern for matching keys (supports wildcards, e.g., "deployments:index:*").
     *
     * @return int number of keys deleted
     */
    public static function forgetByPattern(string $pattern): int
    {
        // Only works for Redis
        if (Cache::getStore() instanceof RedisStore) {
            $deletedCount = 0;
            $cursor       = 0;

            do {
                [$cursor, $keys] = Redis::scan($cursor, [
                    'match' => $pattern,
                    'count' => 100,
                ]);

                if (!empty($keys)) {
                    $deletedCount += Redis::del(...$keys);
                }
            } while ($cursor != 0);

            return $deletedCount;
        }

        // Fallback: throw error for unsupported drivers
        throw new \RuntimeException('forgetByPattern only works with Redis cache store.');
    }
}
