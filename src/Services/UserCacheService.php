<?php

namespace Fleetbase\Services;

use Fleetbase\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UserCacheService
{
    /**
     * Cache key prefix for user current endpoint.
     */
    private const CACHE_PREFIX = 'user:current:';

    /**
     * Default cache TTL in seconds (15 minutes).
     */
    private const CACHE_TTL = 900;

    /**
     * Browser cache TTL in seconds (5 minutes).
     */
    private const BROWSER_CACHE_TTL = 300;

    /**
     * Generate cache key for a user and company.
     *
     * @param int|string $userId
     */
    public static function getCacheKey($userId, string $companyId): string
    {
        return self::CACHE_PREFIX . $userId . ':' . $companyId;
    }

    /**
     * Get cached user data.
     *
     * @param int|string $userId
     */
    public static function get($userId, string $companyId): ?array
    {
        $cacheKey = self::getCacheKey($userId, $companyId);

        try {
            $cached = Cache::get($cacheKey);

            if ($cached) {
                Log::debug('User cache hit', [
                    'user_id'    => $userId,
                    'company_id' => $companyId,
                    'cache_key'  => $cacheKey,
                ]);
            }

            return $cached;
        } catch (\Exception $e) {
            Log::error('Failed to get user cache', [
                'error'      => $e->getMessage(),
                'user_id'    => $userId,
                'company_id' => $companyId,
            ]);

            return null;
        }
    }

    /**
     * Store user data in cache.
     *
     * @param int|string $userId
     */
    public static function put($userId, string $companyId, array $data, ?int $ttl = null): bool
    {
        $cacheKey = self::getCacheKey($userId, $companyId);
        $ttl      = $ttl ?? self::CACHE_TTL;

        try {
            Cache::put($cacheKey, $data, $ttl);

            Log::debug('User cache stored', [
                'user_id'    => $userId,
                'company_id' => $companyId,
                'cache_key'  => $cacheKey,
                'ttl'        => $ttl,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to store user cache', [
                'error'      => $e->getMessage(),
                'user_id'    => $userId,
                'company_id' => $companyId,
            ]);

            return false;
        }
    }

    /**
     * Invalidate cache for a specific user.
     */
    public static function invalidateUser(User $user): void
    {
        try {
            // Get all companies the user belongs to
            $companies = $user->companies()->pluck('companies.uuid')->toArray();

            // Clear cache for each company
            foreach ($companies as $companyId) {
                $cacheKey = self::getCacheKey($user->id, $companyId);
                Cache::forget($cacheKey);

                Log::debug('User cache invalidated', [
                    'user_id'    => $user->id,
                    'company_id' => $companyId,
                    'cache_key'  => $cacheKey,
                ]);
            }

            // Also clear for current session company if different
            $sessionCompany = session('company');
            if ($sessionCompany && !in_array($sessionCompany, $companies)) {
                $cacheKey = self::getCacheKey($user->id, $sessionCompany);
                Cache::forget($cacheKey);

                Log::debug('User cache invalidated for session company', [
                    'user_id'    => $user->id,
                    'company_id' => $sessionCompany,
                    'cache_key'  => $cacheKey,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to invalidate user cache', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * Invalidate cache for a specific user and company.
     *
     * @param int|string $userId
     */
    public static function invalidate($userId, string $companyId): void
    {
        $cacheKey = self::getCacheKey($userId, $companyId);

        try {
            Cache::forget($cacheKey);

            Log::debug('User cache invalidated', [
                'user_id'    => $userId,
                'company_id' => $companyId,
                'cache_key'  => $cacheKey,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate user cache', [
                'error'      => $e->getMessage(),
                'user_id'    => $userId,
                'company_id' => $companyId,
            ]);
        }
    }

    /**
     * Invalidate all cache for a company.
     */
    public static function invalidateCompany(string $companyId): void
    {
        try {
            // Get all cache keys for this company
            $pattern   = self::CACHE_PREFIX . '*:' . $companyId;
            $cacheKeys = Cache::getRedis()->keys($pattern);

            foreach ($cacheKeys as $key) {
                // Remove the Redis prefix if present
                $key = str_replace(config('database.redis.options.prefix', ''), '', $key);
                Cache::forget($key);
            }

            Log::debug('Company user cache invalidated', [
                'company_id' => $companyId,
                'keys_count' => count($cacheKeys),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate company cache', [
                'error'      => $e->getMessage(),
                'company_id' => $companyId,
            ]);
        }
    }

    /**
     * Generate ETag for a user.
     */
    public static function generateETag(User $user): string
    {
        return '"user-' . $user->uuid . '-' . $user->updated_at->timestamp . '"';
    }

    /**
     * Get browser cache TTL.
     */
    public static function getBrowserCacheTTL(): int
    {
        return (int) config('fleetbase.user_cache.browser_ttl', self::BROWSER_CACHE_TTL);
    }

    /**
     * Get server cache TTL.
     */
    public static function getServerCacheTTL(): int
    {
        return (int) config('fleetbase.user_cache.server_ttl', self::CACHE_TTL);
    }

    /**
     * Check if caching is enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('fleetbase.user_cache.enabled', true);
    }

    /**
     * Clear all user current caches.
     */
    public static function flush(): void
    {
        try {
            $pattern   = self::CACHE_PREFIX . '*';
            $cacheKeys = Cache::getRedis()->keys($pattern);

            foreach ($cacheKeys as $key) {
                // Remove the Redis prefix if present
                $key = str_replace(config('database.redis.options.prefix', ''), '', $key);
                Cache::forget($key);
            }

            Log::info('All user current cache flushed', [
                'keys_count' => count($cacheKeys),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to flush user cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
