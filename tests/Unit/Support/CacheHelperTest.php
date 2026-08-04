<?php

use Fleetbase\Support\CacheHelper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheHelperCacheFake
{
    public function __construct(private mixed $store)
    {
    }

    public function getStore(): mixed
    {
        return $this->store;
    }
}

class CacheHelperRedisFake
{
    public array $scanCalls   = [];
    public array $deletedKeys = [];

    public function __construct(private array $scanResults)
    {
    }

    public function scan(int|string $cursor, array $options): array
    {
        $this->scanCalls[] = [$cursor, $options];

        return array_shift($this->scanResults) ?? [0, []];
    }

    public function del(string ...$keys): int
    {
        array_push($this->deletedKeys, ...$keys);

        return count($keys);
    }
}

function redis_cache_store_for_cache_helper(): RedisStore
{
    return new RedisStore(new class implements RedisFactory {
        public function connection($name = null)
        {
            return null;
        }
    });
}

it('deletes redis keys by pattern using scan until the cursor is exhausted', function () {
    bind_test_container();

    $redis = new CacheHelperRedisFake([
        [7, ['deployments:index:1', 'deployments:index:2']],
        [0, ['deployments:index:3']],
    ]);

    Cache::swap(new CacheHelperCacheFake(redis_cache_store_for_cache_helper()));
    Redis::swap($redis);

    $deleted = CacheHelper::forgetByPattern('deployments:index:*');

    expect($deleted)->toBe(3)
        ->and($redis->scanCalls)->toBe([
            [0, ['match' => 'deployments:index:*', 'count' => 100]],
            [7, ['match' => 'deployments:index:*', 'count' => 100]],
        ])
        ->and($redis->deletedKeys)->toBe([
            'deployments:index:1',
            'deployments:index:2',
            'deployments:index:3',
        ]);
});

it('rejects non redis cache stores for pattern deletes', function () {
    bind_test_container();
    Cache::swap(new CacheHelperCacheFake(new ArrayStore()));

    CacheHelper::forgetByPattern('deployments:index:*');
})->throws(RuntimeException::class, 'forgetByPattern only works with Redis cache store.');
