<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * API Model Cache Manager.
 *
 * Provides centralized caching functionality for API models with:
 * - Query result caching
 * - Model instance caching
 * - Relationship caching
 * - Automatic cache invalidation
 * - Multi-tenancy support
 * - Cache tagging for efficient bulk invalidation
 *
 * Caches are invalidated by versions embedded in their keys, not by the tag
 * flush: list caches carry per table query versions (table wide and per company),
 * single model and relationship caches per table model versions (their lookups
 * are keyed by id and know no company). A key carries the versions of the
 * model's own table and of every table its cached result embeds: the tables a
 * model declares in `$apiCacheDependencies` (relation paths or table names), plus
 * the relations a request (`with`, `expand`, `count`) or a model or relationship
 * lookup loads. A write to any of those tables changes the key. Only what is
 * loaded inside the cached callback goes stale; relations a resource lazy loads
 * at render time are queried fresh on every hit. On Laravel 10 with phpredis >= 6.1,
 * RedisTagSet::entries() starts ZSCAN with cursor '0', which phpredis treats as
 * an already finished scan, so RedisTaggedCache::flush() deletes only the tag
 * sets and leaves every tagged value in Redis until its TTL expires. Bumping the
 * version moves readers to new keys and leaves the orphaned entries to expire.
 */
class ApiModelCache
{
    /**
     * Cache TTL in seconds (default: 1 hour).
     */
    public const DEFAULT_TTL = 3600;

    /**
     * Cache status for current request.
     */
    protected static $cacheStatus;

    /**
     * Cache key for current request.
     */
    protected static $cacheKey;

    /**
     * Cache TTL for list queries (default: 5 minutes).
     */
    public const LIST_TTL = 300;

    /**
     * Cache TTL for single model instances (default: 1 hour).
     */
    public const MODEL_TTL = 3600;

    /**
     * Cache TTL for relationships (default: 30 minutes).
     */
    public const RELATIONSHIP_TTL = 1800;

    /**
     * Resolved dependency tables, keyed by model class, mode and relation path.
     *
     * @var array<string, string[]>
     */
    protected static array $dependencyTables = [];

    /**
     * Most resolved paths kept per process; request paths are client supplied.
     */
    protected const DEPENDENCY_MEMO_LIMIT = 1000;

    /**
     * Generate a cache key for a query.
     */
    public static function generateQueryCacheKey(Model $model, Request $request, array $additionalParams = []): string
    {
        $table       = $model->getTable();
        $companyUuid = static::getCompanyUuid($request);

        // Get ALL query parameters from the request
        // This ensures different filters (e.g., type=customer vs type=contact) generate different cache keys
        $params = $request->query();

        // Remove internal/non-cacheable parameters that shouldn't affect cache key
        $excludedParams = [
            '_',           // Cache-busting timestamp
            'timestamp',   // Cache-busting timestamp
            'nocache',     // Explicit cache bypass
            '_method',     // Laravel method override
        ];

        foreach ($excludedParams as $excluded) {
            unset($params[$excluded]);
        }

        // Merge additional parameters
        $params = array_merge($params, $additionalParams);

        // Remove null values and sort for consistent keys
        $params = array_filter($params, fn ($value) => $value !== null);
        ksort($params);

        // Generate hash of parameters
        $paramsHash = md5(json_encode($params));

        // Include company UUID for multi-tenancy
        $companyPart = $companyUuid ? "company_{$companyUuid}" : 'no_company';

        // Get query versions for this table and the tables its responses embed
        // This ensures cache keys change after invalidation
        $versionKeys = [];
        foreach ([$table, ...static::dependencyTables($model, static::requestRelationPaths($request))] as $versionTable) {
            $versionKeys[] = static::queryVersionKey($versionTable, null);
            if ($companyUuid) {
                $versionKeys[] = static::queryVersionKey($versionTable, $companyUuid);
            }
        }
        $version = static::versionSegment($versionKeys);

        // Add Redis hash tag {api_query} for Redis Cluster shard routing
        // Include version to ensure cache keys change after writes
        return "{api_query}:{$table}:{$companyPart}:{$version}:{$paramsHash}";
    }

    /**
     * Cache key holding the query version for a table/company.
     *
     * A null company is the table wide version, which every company's list keys
     * include, so writes that know no company invalidate all of them.
     */
    protected static function queryVersionKey(string $table, ?string $companyUuid): string
    {
        return "api_query_version:{$table}:{$companyUuid}";
    }

    /**
     * Cache key holding the model and relationship version for a table.
     */
    protected static function modelVersionKey(string $table): string
    {
        return "api_model_version:{$table}";
    }

    /**
     * Read versions in one round trip and join them into a key segment.
     *
     * @param string[] $versionKeys
     */
    protected static function versionSegment(array $versionKeys): string
    {
        $versions = Cache::many($versionKeys);

        return 'v' . implode('.', array_map(fn ($version) => (int) $version, $versions));
    }

    /**
     * Get the other tables whose data a model's cached results embed.
     *
     * Declared dependencies come from the model's `$apiCacheDependencies` and must
     * resolve (an entry that does not throws in debug and is logged and skipped
     * otherwise); `$paths` are relations a request or lookup loads (`with`, `expand`,
     * `$with`, a relationship name), and segments that cannot be resolved there
     * end that path.
     *
     * @param string[] $paths
     *
     * @return string[] sorted table names, excluding the model's own table
     */
    public static function dependencyTables(Model $model, array $paths = []): array
    {
        $declared = method_exists($model, 'getApiCacheDependencies') ? $model->getApiCacheDependencies() : [];
        $tables   = [];

        foreach ($declared as $path) {
            array_push($tables, ...static::pathTables($model, $path, true));
        }
        foreach ($paths as $path) {
            array_push($tables, ...static::pathTables($model, $path, false));
        }

        $tables = array_values(array_unique(array_diff($tables, [$model->getTable()])));
        sort($tables);

        return $tables;
    }

    /**
     * Resolve one relation path, memoized per process.
     *
     * @return string[]
     */
    protected static function pathTables(Model $model, string $path, bool $strict): array
    {
        $memoKey = get_class($model) . '|' . ($strict ? 'declared' : 'lookup') . '|' . $path;
        if (array_key_exists($memoKey, static::$dependencyTables)) {
            return static::$dependencyTables[$memoKey];
        }

        if (count(static::$dependencyTables) >= static::DEPENDENCY_MEMO_LIMIT) {
            static::$dependencyTables = [];
        }

        return static::$dependencyTables[$memoKey] = static::resolvePath($model, $path, $strict);
    }

    /**
     * Resolve a relation path (or, when declared, a table name) to tables.
     *
     * A relation contributes its related table, the pivot table of a many to many
     * relation and the intermediate table of a has-many-through. A morphTo has no
     * single related table, so declarations must name its tables instead.
     *
     * @return string[]
     */
    protected static function resolvePath(Model $model, string $path, bool $strict): array
    {
        $tables   = [];
        $current  = $model->newInstance();
        $segments = explode('.', $path);

        foreach ($segments as $segment) {
            $method = static::relationMethod($current, trim($segment), $strict);
            // Like Eloquent's eager loading: a fresh instance, without the relation's constraints
            $relation = $method ? Relation::noConstraints(fn () => $current->newInstance()->{$method}()) : null;

            if (!$relation instanceof Relation || $relation instanceof MorphTo) {
                if (!$strict) {
                    return $tables;
                }

                // A declared entry that is not a relation name is a table name
                if ($method === null && count($segments) === 1) {
                    return [$segment];
                }

                // Extensions deploy independently of core, so a bad declaration fails
                // loudly in debug (tests, development) but only logs in production
                $message = sprintf('%s::$apiCacheDependencies entry "%s" cannot be resolved at "%s"; declare a relation path or the table names it reads.', get_class($model), $path, $segment);
                if (config('app.debug')) {
                    throw new \LogicException($message);
                }
                Log::error($message);

                return $tables;
            }

            if ($relation instanceof BelongsToMany) {
                $tables[] = $relation->getTable();
            }
            if ($relation instanceof HasManyThrough) {
                $tables[] = $relation->getParent()->getTable();
            }

            $current  = $relation->getRelated();
            $tables[] = $current->getTable();
        }

        return $tables;
    }

    /**
     * Find the method a relation segment names, as requests spell it (camel or snake case).
     *
     * Request paths are client supplied, so only methods the model's own classes
     * declare, with no required parameters, are considered.
     */
    protected static function relationMethod(Model $model, string $segment, bool $strict): ?string
    {
        foreach (array_unique([$segment, Str::camel($segment), Str::snake($segment)]) as $method) {
            if ($method === '' || !method_exists($model, $method)) {
                continue;
            }

            $reflection = new \ReflectionMethod($model, $method);
            $declaredBy = $reflection->getDeclaringClass()->getName();
            if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            if (!$strict && str_starts_with($declaredBy, 'Illuminate\\')) {
                continue;
            }

            return $method;
        }

        return null;
    }

    /**
     * Get the relation paths a list request eager loads or counts.
     *
     * @return string[]
     */
    protected static function requestRelationPaths(Request $request): array
    {
        $paths = [];
        foreach (['with', 'expand', 'count', 'with_count'] as $param) {
            $value = $request->input($param);
            if (is_string($value)) {
                $value = explode(',', $value);
            }
            if (is_array($value)) {
                array_push($paths, ...array_filter($value, 'is_string'));
            }
        }

        return array_values(array_unique(array_map('trim', $paths)));
    }

    /**
     * Turn eager load definitions into relation paths.
     *
     * @param array<int|string, mixed> $with
     *
     * @return string[]
     */
    protected static function relationPaths(array $with): array
    {
        $paths = [];
        foreach ($with as $key => $value) {
            $path = is_string($key) ? $key : $value;
            if (is_string($path)) {
                $paths[] = explode(':', $path, 2)[0];
            }
        }

        return $paths;
    }

    /**
     * Advance a cache version so keys built from it change.
     *
     * A missing version reads as 0 and INCR creates it at 1, so the first bump
     * also changes the key. Stores whose increment does not create missing keys
     * (database, memcached) return false, so the version is seeded instead.
     */
    protected static function bumpVersion(string $versionKey): void
    {
        if (Cache::increment($versionKey) === false) {
            Cache::add($versionKey, 1) || Cache::increment($versionKey);
        }
    }

    /**
     * Generate a cache key for a single model instance.
     *
     * @param string|int $id
     */
    public static function generateModelCacheKey(Model $model, $id, array $with = []): string
    {
        $table    = $model->getTable();
        $withHash = !empty($with) ? ':' . md5(json_encode($with)) : '';
        $tables   = [$table, ...static::dependencyTables($model, static::relationPaths($with))];
        $version  = static::versionSegment(array_map(fn ($versionTable) => static::modelVersionKey($versionTable), $tables));

        // Add Redis hash tag {api_model} for Redis Cluster shard routing
        // Include version to ensure cache keys change after writes
        return "{api_model}:{$table}:{$version}:{$id}{$withHash}";
    }

    /**
     * Generate a cache key for a relationship.
     */
    public static function generateRelationshipCacheKey(Model $model, string $relationshipName): string
    {
        $table   = $model->getTable();
        $id      = $model->getKey();
        $tables  = [$table, ...static::dependencyTables($model, [$relationshipName])];
        $version = static::versionSegment(array_map(fn ($versionTable) => static::modelVersionKey($versionTable), $tables));

        // Add Redis hash tag {api_relation} for Redis Cluster shard routing
        // Carries the parent's versions and the related tables', so a write to either side changes it
        return "{api_relation}:{$table}:{$version}:{$id}:{$relationshipName}";
    }

    /**
     * Generate cache tags for a model.
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
     */
    public static function cacheQueryResult(Model $model, Request $request, \Closure $callback, array $additionalParams = [], ?int $ttl = null)
    {
        // Check if caching is enabled
        if (!static::isCachingEnabled()) {
            $result = $callback();

            return $result ?? collect([]); // Guard against null
        }

        $cacheKey    = static::generateQueryCacheKey($model, $request, $additionalParams);
        $companyUuid = static::getCompanyUuid($request);
        $tags        = static::generateCacheTags($model, $companyUuid, true);  // Include query tag
        $ttl         = $ttl ?? static::LIST_TTL;

        // FIX #3: Guard against read-after-invalidate in same request
        // After invalidation, resetCacheStatus() sets $cacheStatus to null and the
        // query version has moved, so the key generated above is already the new one

        try {
            // Initialize cache status and key
            static::$cacheStatus = null;
            static::$cacheKey    = $cacheKey;

            // Use atomic lock to prevent cache stampede
            $lock   = Cache::lock("lock:{$cacheKey}", 10);
            $result = $lock->block(10, function () use ($tags, $cacheKey, $ttl, $callback) {
                // Inside the lock, use remember() to check if cache exists
                return Cache::tags($tags)->remember($cacheKey, $ttl, function () use ($callback, $cacheKey) {
                    // Callback only runs if cache is empty/expired
                    static::$cacheStatus = 'MISS';
                    static::$cacheKey    = $cacheKey;

                    return $callback();
                });
            });

            // If block() timed out (couldn't get lock in 10 seconds), result is null
            // This is rare, but we need graceful fallback
            if ($result === null) {
                // Try to read from cache (might have been populated by another process)
                $cached = Cache::tags($tags)->get($cacheKey);
                if ($cached !== null) {
                    // Cache hit from fallback
                    $result              = $cached;
                    static::$cacheStatus = 'HIT';
                } else {
                    // Cache miss - execute callback
                    $result              = $callback();
                    static::$cacheStatus = 'MISS';
                }
            }

            // Default to HIT if MISS was never set (normal path through remember())
            static::$cacheStatus ??= 'HIT';

            // FINAL GUARD: Ensure we never return null/false
            return $result ?? collect([]);
        } catch (\Exception $e) {
            Log::warning('Cache error, falling back to direct query', [
                'key'   => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            // Exception means cache failed, so this is a MISS
            static::$cacheStatus = 'MISS';
            $result              = $callback();

            // Guard against callback returning null/false
            return $result ?? collect([]);
        }
    }

    /**
     * Cache a model instance.
     *
     * @param string|int $id
     */
    public static function cacheModel(Model $model, $id, \Closure $callback, array $with = [], ?int $ttl = null)
    {
        if (!static::isCachingEnabled()) {
            return $callback();
        }

        $cacheKey = static::generateModelCacheKey($model, $id, $with);
        $tags     = static::generateCacheTags($model);
        $ttl      = $ttl ?? static::MODEL_TTL;

        try {
            $isCached = Cache::tags($tags)->has($cacheKey);

            $result = Cache::tags($tags)->remember($cacheKey, $ttl, function () use ($callback, $cacheKey) {
                static::$cacheStatus = 'MISS';
                static::$cacheKey    = $cacheKey;

                return $callback();
            });

            if ($isCached) {
                static::$cacheStatus = 'HIT';
                static::$cacheKey    = $cacheKey;
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('Cache error, falling back to direct query', [
                'key'   => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            static::$cacheStatus = 'ERROR';
            static::$cacheKey    = $cacheKey;

            return $callback();
        }
    }

    /**
     * Cache a relationship result.
     */
    public static function cacheRelationship(Model $model, string $relationshipName, \Closure $callback, ?int $ttl = null)
    {
        if (!static::isCachingEnabled()) {
            return $callback();
        }

        $cacheKey = static::generateRelationshipCacheKey($model, $relationshipName);
        $tags     = static::generateCacheTags($model);
        $ttl      = $ttl ?? static::RELATIONSHIP_TTL;

        try {
            $isCached = Cache::tags($tags)->has($cacheKey);

            $result = Cache::tags($tags)->remember($cacheKey, $ttl, function () use ($callback, $cacheKey) {
                static::$cacheStatus = 'MISS';
                static::$cacheKey    = $cacheKey;

                return $callback();
            });

            if ($isCached) {
                static::$cacheStatus = 'HIT';
                static::$cacheKey    = $cacheKey;
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('Cache error, falling back to direct query', [
                'key'   => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            static::$cacheStatus = 'ERROR';
            static::$cacheKey    = $cacheKey;

            return $callback();
        }
    }

    /**
     * Invalidate all caches for a model.
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

        // FIX #4: Increment the query and model version counters
        // This ensures cache keys change after invalidation, and it is what
        // actually hides stale caches (see the class docblock)
        $table = $model->getTable();
        static::bumpVersion(static::queryVersionKey($table, $companyUuid));
        static::bumpVersion(static::modelVersionKey($table));

        // Generate tags for BOTH model and query caches
        // Model caches: single-record lookups
        // Query caches: collection/list endpoints
        $modelTags = static::generateCacheTags($model, $companyUuid, false);
        $queryTags = static::generateCacheTags($model, $companyUuid, true);

        try {
            // Flush model-level caches (single records, relationships)
            Cache::tags($modelTags)->flush();

            // Flush query-level caches (collections, lists)
            // On Redis these flushes remove the tag sets but not the values (see the
            // class docblock); the version bumps above are what invalidate caches
            Cache::tags($queryTags)->flush();
        } catch (\Exception $e) {
            Log::error('Failed to invalidate cache', [
                'model' => get_class($model),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Invalidate cache for a specific query.
     */
    public static function invalidateQueryCache(Model $model, Request $request, array $additionalParams = []): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        $cacheKey    = static::generateQueryCacheKey($model, $request, $additionalParams);
        $companyUuid = static::getCompanyUuid($request);
        $tags        = static::generateCacheTags($model, $companyUuid, true);  // Include query tag

        try {
            Cache::tags($tags)->forget($cacheKey);
        } catch (\Exception $e) {
            Log::error('Failed to invalidate query cache', [
                'key'   => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate all caches for a company.
     */
    public static function invalidateCompanyCache(string $companyUuid): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        try {
            Cache::tags(["company:{$companyUuid}"])->flush();
        } catch (\Exception $e) {
            Log::error('Failed to invalidate company cache', [
                'company_uuid' => $companyUuid,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // flushRedisCacheByPattern() method removed - not safe for Redis Cluster
    // Cache invalidation is now handled solely through Cache::tags()->flush()

    /**
     * Check if caching is enabled.
     */
    public static function isCachingEnabled(): bool
    {
        return config('api.cache.enabled', true);
    }

    /**
     * Get the cache TTL for query results.
     */
    public static function getQueryTtl(): int
    {
        return config('api.cache.ttl.query', static::LIST_TTL);
    }

    /**
     * Get the cache TTL for model instances.
     */
    public static function getModelTtl(): int
    {
        return config('api.cache.ttl.model', static::MODEL_TTL);
    }

    /**
     * Get the cache TTL for relationships.
     */
    public static function getRelationshipTtl(): int
    {
        return config('api.cache.ttl.relationship', static::RELATIONSHIP_TTL);
    }

    /**
     * Extract company UUID from request.
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
     */
    public static function warmCache(Model $model, Request $request, \Closure $callback): void
    {
        if (!static::isCachingEnabled()) {
            return;
        }

        try {
            static::cacheQueryResult($model, $request, $callback);
        } catch (\Exception $e) {
            Log::error('Failed to warm up cache', [
                'model' => get_class($model),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache statistics.
     */
    public static function getStats(): array
    {
        return [
            'enabled' => static::isCachingEnabled(),
            'driver'  => config('cache.default'),
            'ttl'     => [
                'query'        => static::getQueryTtl(),
                'model'        => static::getModelTtl(),
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
     */
    public static function getCacheKey(): ?string
    {
        return static::$cacheKey;
    }

    /**
     * Reset cache status (for testing).
     */
    public static function resetCacheStatus(): void
    {
        static::$cacheStatus = null;
        static::$cacheKey    = null;
    }
}
