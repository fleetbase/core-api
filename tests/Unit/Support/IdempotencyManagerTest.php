<?php

use Fleetbase\Support\IdempotencyManager;
use Illuminate\Support\Facades\Cache;

class IdempotencyManagerCacheFake
{
    public array $values    = [];
    public array $ttl       = [];
    public array $forgotten = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttl[$key]    = $ttl;

        return true;
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
        unset($this->values[$key], $this->ttl[$key]);

        return true;
    }
}

beforeEach(function () {
    Cache::swap(new IdempotencyManagerCacheFake());
});

test('idempotency manager marks keys processed with namespaced cache key and ttl', function () {
    $manager = new IdempotencyManager();

    expect($manager->isDuplicate('webhook-event-1'))->toBeFalse();

    $manager->markProcessed('webhook-event-1');

    $cache = Cache::getFacadeRoot();

    expect($manager->isDuplicate('webhook-event-1'))->toBeTrue()
        ->and($cache->values)->toBe(['idempotency:webhook-event-1' => true])
        ->and($cache->ttl)->toBe(['idempotency:webhook-event-1' => 86400]);
});

test('idempotency manager clears processed keys without touching unrelated entries', function () {
    $manager = new IdempotencyManager();

    $manager->markProcessed('first');
    $manager->markProcessed('second');
    $manager->clear('first');

    $cache = Cache::getFacadeRoot();

    expect($manager->isDuplicate('first'))->toBeFalse()
        ->and($manager->isDuplicate('second'))->toBeTrue()
        ->and($cache->forgotten)->toBe(['idempotency:first'])
        ->and($cache->values)->toBe(['idempotency:second' => true]);
});
