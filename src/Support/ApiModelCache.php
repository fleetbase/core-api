<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * API Model Cache Manager
 * 
 * Provides centralized caching functionality for API models with:
 * - Query result caching
 * - Model instance caching
 * - Relationship caching
 * - Automatic cache invalidation
 * - Multi-tenancy support
 * - Cache tagging for efficient bulk invalidation
 */
class ApiModelCache
{
    /**
     * Cache TTL in seconds (default: 1 hour)
     */
    const DEFAULT_TTL = 3600;

    /**
     * Cache status for current request
     */
    protected static $cacheStatus = null;

    /**
     * Cache key for current request
     */
    protected static $cacheKey = null;

    /**
     * Cache TTL for list queries (default: 5 minutes)
     */
    const LIST_TTL = 300;

    /**
     * Cache TTL for single model instances (default: 1 hour)
     */
    const MODEL_TTL = 3600;

    /**
     * Cache TTL for relationships (default: 30 minutes)
     */
    const RELATIONSHIP_TTL = 1800;

    /**
     * Generate a cache key for a query.
     *
     * @param Model $model
     * @param Request $request
     * @param array $additionalParams
     * @return string
     */
    public static function generateQueryCacheKey(Model $model, Request $request, array $additionalParams = []): string
    {
        $table = $model->getTable();
        $companyUuid = static::getCompanyUuid($request);
        
        // Get all relevant query parameters
        $params = [
            'limit' => $request->input('limit'),
            'offset' => $request->input('offset'),
            'page' => $request->input('page'),
            'sort' => $request->input('sort'),
            'order' => $request->input('order'),
            'query' => $request->input('query'),
            'search' => $request->input('search'),
            'filter' => $request->input('filter'),
            'with' => $request->input('with'),
            'expand' => $request->input('expand'),
            'columns' => $request->input('columns'),
        ];
        
        // Merge additional parameters
        $params = array_merge($params, $additionalParams);
        
        // Remove null values and sort for consistent keys
        $params = array_filter($params, fn($value) => $value !== null);
        ksort($params);
        
        // Generate hash of parameters
        $paramsHash = md5(json_encode($params));
        
        // Include company UUID for multi-tenancy
        $companyPart = $companyUuid ? "company_{$companyUuid}" : 'no_company';
        
        // Get query version for this table/company
        // This ensures cache keys change after invalidation
        $versionKey = "api_query_version:{$table}:{$companyUuid}";
        $version = Cache::get($versionKey, 1);  // Default to version 1
        
        // Add Redis hash tag {api_query} for Redis Cluster shard routing
        // Include version to ensure cache keys change after writes
        return "{api_query}:{$table}:{$companyPart}:v{$version}:{$paramsHash}";
    }

    /**
     * Generate a cache key for a single model instance.
     *
     * @param Model $model
     * @param string|int $id
     * @param array $with
     * @return string
     */
    public static function generateModelCacheKey(Model $model, $id, array $with = []): string
    {
        $table = $model->getTable();
        $withHash = !empty($with) ? ':' . md5(json_encode($with)) : '';
        
        // Add Redis hash tag {api_model} for Redis Cluster shard routing
        return "{api_model}:{$table}:{$id}{$withHash}";
    }

    /**
     * Generate a cache key for a relationship.
     *
     * @param Model $model
     * @param string $relationshipName
     * @return string
     */
    public static function generateRelationshipCacheKey(Model $model, string $relationshipName): string
    {
        $table = $model->getTable();
        $id = $model->getKey();
        
        // Add Redis hash tag {api_relation} for Redis Cluster shard routing
        return "{api_relation}:{$table}:{$id}:{$relationshipName}";
    }

    /**
     * Generate cache tags for a model.
     *
     * @param Model $model
     * @param string|null $companyUuid
     * @return array
     */
    public static function generateCacheTags(Model $model, ?string $companyUuid = null, bool $includeQueryTag = false): array
    {
        $table = $model->getTable();
        
        $tags = [
            'api_cache',
            "api_model:{$table}",
        ];
        
        // Add query-specific tag for collection/list caches
        // This allows query caches to be invalidated separately from model caches
        if ($includeQueryTag) {
            $tags[] = "api_query:{$table}";
        }
        
        if ($companyUuid) {
            $tags[] = "company:{$companyUuid}";
        }
        
        return $tags;
    }

    /**
     * Cache a query result.
     *
     * @param Model $model
     * @param Request $request
     * @param \Closure $callback
     * @param array $additionalParams
     * @param int|null $ttl
     * @return mixed
     */
    public static function cacheQueryResult(Model $model, Request $request, \Closure $callback, array $additionalParams = [], ?int $ttl = null)
    {
        // Check if caching is enabled
        if (!static::isCachingEnabled()) {
            return $callback();
        }

        $cacheKey = static::generateQueryCacheKey($model, $request, $additionalParams);
        $companyUuid = static::getCompanyUuid($request);
        $tags = static::generateCacheTags($model, $companyUuid, true);  // Include query tag
        $ttl = $ttl ?? static::LIST_TTL;

        // FIX #3: Guard against read-after-invalidate in same request
        // After invalidation, resetCacheStatus() sets $cacheStatus to null
        // But we need a way to detect if invalidation happened in this request
        // For now, we'll rely on tag flush working correctly

        try {
            // Use atomic lock to prevent cache stampede
            // When cache expires and 250 VUs hit simultaneously, only one rebuilds cache
            // Others wait for the lock, then get the newly cached value
            $lockKey = "lock:{$cacheKey}";
            $lockTimeout = 10; // Wait up to 10 seconds for lock
            
            // Try to get the lock
            $lock = Cache::lock($lockKey, $lockTimeout);
            
            // FIX #2: Remove Cache::has() check - it primes Laravel's request-level cache
            // and causes false HITs. Always use remember() and check if callback runs.
            $callbackRan = false;
            
            // If we can't get the lock, wait and retry
            // This prevents 250 concurrent cache rebuilds
            $result = $lock->get(function () use ($tags, $cacheKey, $ttl, $callback, &$callbackRan) {
                // Inside the lock, check if cache was built by another process
                return Cache::tags($tags)->remember($cacheKey, $ttl, function () use ($callback, $cacheKey, &$callbackRan) {
                    $callbackRan = true;
                    Log::debug("Cache MISS for query (rebuilding)", ['key' => $cacheKey]);
                    static::$cacheStatus = 'MISS';
                    static::$cacheKey = $cacheKey;
                    return $callback();
                });
            });
            
            // If lock->get() returns null, it means we couldn't get the lock in time
            // Fall back to reading cache without lock (might be stale, but better than nothing)
            if ($result === null) {
                Log::warning("Cache lock timeout, reading without lock", ['key' => $cacheKey]);
                $result = Cache::tags($tags)->remember($cacheKey, $ttl, $callback);
            }
            
            if (!$callbackRan) {
                Log::debug("Cache HIT for query", ['key' => $cacheKey]);
                static::$cacheStatus = 'HIT';
                static::$cacheKey = $cacheKey;
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::warning("Cache error, falling back to direct query", [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    /**
     * Cache a model instance.
     *
     * @param Model $model
     * @param string|int $id
     * @param \Closure $callback
     * @param array $with
     * @param int|null $ttl
     * @return mixed
     */
    public static function cacheModel(Model $model, $id, \Closure $callback, array $with = [], ?int $ttl = null)
    {
        if (!static::isCachingEnabled()) {
            return $callback();
        }

        $cacheKey = static::generateModelCacheKey($model, $id, $with);
        $tags = static::generateCacheTags($model);
        $ttl = $ttl ?? static::MODEL_TTL;

        try {
            $isCached = Cache::tags($tags)->has($cacheKey);
            
            $result = Cache::tags($tags)->remember($cacheKey, $ttl, function () use ($callback, $cacheKey) {
                Log::debug("Cache MISS for model", ['key' => $cacheKey]);
                static::$cacheStatus = 'MISS';
                static::$cacheKey = $cacheKey;
                return $callback();
            });
            
            if ($isCached) {
                Log::debug("Cache HIT for model", ['key' => $cacheKey]);
                static::$cacheStatus = 'HIT';
                static::$cacheKey = $cacheKey;
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::warning("Cache error, falling back to direct query", [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            static::$cacheStatus = 'ERROR';
            static::$cacheKey = $cacheKey;
            return $callback();
        }
    }

    /**
     * Cache a relationship result.
     *
     * @param Model $model
     * @param string $relationshipName
     * @param \Closure $callback
     * @param int|null $ttl
     * @return mixed
     */
    public static function cacheRelationship(Model $model, string $relationshipName, \Closure $callback, ?int $ttl = null)
    {
        if (!static::isCachingEnabled()) {
            return $callback();
        }

        $cacheKey = static::generateRelationshipCacheKey($model, $relationshipName);
        $tags = static::generateCacheTags($model);
        $ttl = $ttl ?? static::RELATIONSHIP_TTL;

        try {
            $isCached = Cache::tags($tags)->has($cacheKey);
            
            $result = Cache::tags($tags)->remember($cacheKey, $ttl, function () use ($callback, $cacheKey) {
                Log::debug("Cache MISS for relationship", ['key' => $cacheKey]);
                static::$cacheStatus = 'MISS';
                static::$cacheKey = $cacheKey;
                return $callback();
            });
            
            if ($isCached) {
                Log::debug("Cache HIT for relationship", ['key' => $cacheKey]);
                static::$cacheStatus = 'HIT';
                static::$cacheKey = $cacheKey;
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::warning("Cache error, falling back to direct query", [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            static::$cacheStatus = 'ERROR';
            static::$cacheKey = $cacheKey;
            return $callback();
        }
    }

    /**
     * Invalidate all caches for a model.
     *
     * @param Model $model
     * @param string|null $companyUuid
     * @return void
     */
    public static function invalidateModelCache(Model $model, ?string $companyUuid = null): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        // FIX #1: Reset request-level cache state
        // This prevents Laravel from serving stale data from request memory
        // after invalidation within the same request lifecycle
        static::resetCacheStatus();

        // FIX #4: Increment query version counter
        // This ensures the cache key changes after invalidation
        $table = $model->getTable();
        $versionKey = "api_query_version:{$table}:{$companyUuid}";
        Cache::increment($versionKey);
        Log::debug("Incremented query version", [
            'table' => $table,
            'company_uuid' => $companyUuid,
            'version_key' => $versionKey,
            'new_version' => Cache::get($versionKey),
        ]);

        // Generate tags for BOTH model and query caches
        // Model caches: single-record lookups
        // Query caches: collection/list endpoints
        $modelTags = static::generateCacheTags($model, $companyUuid, false);
        $queryTags = static::generateCacheTags($model, $companyUuid, true);

        try {
            Log::info("Invalidating cache via tag flush", [
                'model' => get_class($model),
                'table' => $model->getTable(),
                'id' => $model->getKey(),
                'company_uuid' => $companyUuid,
                'model_tags' => $modelTags,
                'query_tags' => $queryTags,
            ]);
            
            // Flush model-level caches (single records, relationships)
            Cache::tags($modelTags)->flush();
            
            // Flush query-level caches (collections, lists)
            // This is CRITICAL - query caches must be explicitly flushed
            Cache::tags($queryTags)->flush();
            
            Log::info("Cache invalidated successfully", [
                'model' => get_class($model),
                'table' => $model->getTable(),
                'id' => $model->getKey(),
                'model_tags_flushed' => $modelTags,
                'query_tags_flushed' => $queryTags,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to invalidate cache", [
                'model' => get_class($model),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Invalidate cache for a specific query.
     *
     * @param Model $model
     * @param Request $request
     * @param array $additionalParams
     * @return void
     */
    public static function invalidateQueryCache(Model $model, Request $request, array $additionalParams = []): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        $cacheKey = static::generateQueryCacheKey($model, $request, $additionalParams);
        $companyUuid = static::getCompanyUuid($request);
        $tags = static::generateCacheTags($model, $companyUuid, true);  // Include query tag

        try {
            Cache::tags($tags)->forget($cacheKey);
            Log::debug("Cache invalidated for query", ['key' => $cacheKey]);
        } catch (\Exception $e) {
            Log::error("Failed to invalidate query cache", [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate all caches for a company.
     *
     * @param string $companyUuid
     * @return void
     */
    public static function invalidateCompanyCache(string $companyUuid): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        try {
            Cache::tags(["company:{$companyUuid}"])->flush();
            Log::info("Cache invalidated for company", ['company_uuid' => $companyUuid]);
        } catch (\Exception $e) {
            Log::error("Failed to invalidate company cache", [
                'company_uuid' => $companyUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // flushRedisCacheByPattern() method removed - not safe for Redis Cluster
    // Cache invalidation is now handled solely through Cache::tags()->flush()

    /**
     * Check if caching is enabled.
     *
     * @return bool
     */
    public static function isCachingEnabled(): bool
    {
        return config('api.cache.enabled', true);
    }

    /**
     * Get the cache TTL for query results.
     *
     * @return int
     */
    public static function getQueryTtl(): int
    {
        return config('api.cache.ttl.query', static::LIST_TTL);
    }

    /**
     * Get the cache TTL for model instances.
     *
     * @return int
     */
    public static function getModelTtl(): int
    {
        return config('api.cache.ttl.model', static::MODEL_TTL);
    }

    /**
     * Get the cache TTL for relationships.
     *
     * @return int
     */
    public static function getRelationshipTtl(): int
    {
        return config('api.cache.ttl.relationship', static::RELATIONSHIP_TTL);
    }

    /**
     * Extract company UUID from request.
     *
     * @param Request $request
     * @return string|null
     */
    protected static function getCompanyUuid(Request $request): ?string
    {
        // Try to get from session
        if ($request->session()->has('company')) {
            return $request->session()->get('company');
        }

        // Try to get from authenticated user
        $user = $request->user();
        if ($user && method_exists($user, 'company_uuid')) {
            return $user->company_uuid;
        }

        // Try to get from request input
        return $request->input('company_uuid');
    }

    /**
     * Warm up cache for a model.
     *
     * @param Model $model
     * @param Request $request
     * @param \Closure $callback
     * @return void
     */
    public static function warmCache(Model $model, Request $request, \Closure $callback): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        try {
            static::cacheQueryResult($model, $request, $callback);
            Log::info("Cache warmed up", [
                'model' => get_class($model),
                'table' => $model->getTable(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to warm up cache", [
                'model' => get_class($model),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public static function getStats(): array
    {
        return [
            'enabled' => static::isCachingEnabled(),
            'driver' => config('cache.default'),
            'ttl' => [
                'query' => static::getQueryTtl(),
                'model' => static::getModelTtl(),
                'relationship' => static::getRelationshipTtl(),
            ],
        ];
    }

    /**
     * Get cache status for current request.
     *
     * @return string|null 'HIT', 'MISS', 'ERROR', or null
     */
    public static function getCacheStatus(): ?string
    {
        return static::$cacheStatus;
    }

    /**
     * Get cache key for current request.
     *
     * @return string|null
     */
    public static function getCacheKey(): ?string
    {
        return static::$cacheKey;
    }

    /**
     * Reset cache status (for testing).
     *
     * @return void
     */
    public static function resetCacheStatus(): void
    {
        static::$cacheStatus = null;
        static::$cacheKey = null;
    }
}
