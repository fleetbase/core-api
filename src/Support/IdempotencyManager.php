<?php

namespace Fleetbase\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Class IdempotencyManager.
 *
 * Manages idempotency keys for webhook deduplication.
 */
class IdempotencyManager
{
    protected int $ttl = 86400; // 24 hours

    /**
     * Check if a key has already been processed.
     */
    public function isDuplicate(string $key): bool
    {
        return Cache::has($this->getCacheKey($key));
    }

    /**
     * Mark a key as processed.
     */
    public function markProcessed(string $key): void
    {
        Cache::put($this->getCacheKey($key), true, $this->ttl);
    }

    /**
     * Clear an idempotency key.
     */
    public function clear(string $key): void
    {
        Cache::forget($this->getCacheKey($key));
    }

    /**
     * Get the cache key for an idempotency key.
     */
    protected function getCacheKey(string $key): string
    {
        return 'idempotency:' . $key;
    }
}
