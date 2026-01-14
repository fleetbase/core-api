<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\ApiModelCache;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;

/**
 * Adds caching capabilities to API models.
 *
 * This trait should be used alongside HasApiModelBehavior to provide
 * automatic caching of query results, model instances, and relationships.
 */
trait HasApiModelCache
{
    /**
     * Boot the HasApiModelCache trait.
     *
     * Registers model event listeners for automatic cache invalidation.
     */
    public static function bootHasApiModelCache()
    {
        // Invalidate cache when model is created
        static::created(function ($model) {
            $model->invalidateApiCache();
        });

        // Invalidate cache when model is updated
        static::updated(function ($model) {
            $model->invalidateApiCache();
        });

        // Invalidate cache when model is deleted
        static::deleted(function ($model) {
            $model->invalidateApiCache();
        });

        // Invalidate cache when model is restored (soft deletes)
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->invalidateApiCache();
            });
        }
    }

    /**
     * Query from request with caching.
     */
    public function queryFromRequestCached(Request $request, ?\Closure $queryCallback = null)
    {
        // Note: Caching checks are now handled in queryFromRequest()
        // This method is called automatically when HasApiModelCache trait is present

        // Generate additional params from query callback
        $additionalParams = [];
        if ($queryCallback) {
            // Mark that a callback is present, but don't hash it
            // The callback's effect on results is already captured by request parameters
            // Using spl_object_id() would create unique keys per request, preventing cache hits
            $additionalParams['has_callback'] = true;
        }

        return ApiModelCache::cacheQueryResult(
            $this,
            $request,
            fn () => $this->queryFromRequestWithoutCache($request, $queryCallback),
            $additionalParams,
            ApiModelCache::getQueryTtl()
        );
    }

    /**
     * Query from request without caching (internal use).
     *
     * This method bypasses the cache check to avoid infinite recursion.
     */
    protected function queryFromRequestWithoutCache(Request $request, ?\Closure $queryCallback = null)
    {
        $columns         = $request->input('columns', ['*']);
        $limit           = $request->integer('limit', 30);
        $offset          = $request->integer('offset', 0);
        $page            = max(1, $request->integer('page', 1));
        $calculateOffset = $request->missing('offset') && $request->has('page');

        // Clamp limit
        if ($limit !== -1) {
            $limit = max(1, min($limit, 100));
        }

        /**
         * @var \Illuminate\Database\Eloquent\Builder $builder
         */
        $builder = $this->searchBuilder($request, $columns);

        // Auto-calculate offset from page
        if ($calculateOffset) {
            $offset = ($page - 1) * $limit;
        }

        // Handle limit
        if ($limit === -1) {
            $builder->limit(PHP_INT_MAX);
        } elseif ($limit > 0) {
            $builder->limit($limit);
        }

        // Handle offset
        if ($offset > 0) {
            $builder->offset($offset);
        }

        // if queryCallback is supplied
        if (is_callable($queryCallback)) {
            $queryCallback($builder, $request);
        }

        /* debug */
        // Utils::sqlDump($builder);

        if (\Fleetbase\Support\Http::isInternalRequest($request)) {
            return $builder->fastPaginate($limit, $columns);
        }

        // get the results
        $result = $builder->get($columns);

        // mutate if mutation causing params present
        return static::mutateModelWithRequest($request, $result);
    }

    /**
     * Static alias for queryFromRequestCached().
     */
    public static function queryWithRequestCached(Request $request, ?\Closure $queryCallback = null)
    {
        return (new static())->queryFromRequestCached($request, $queryCallback);
    }

    /**
     * Find a model by ID with caching.
     *
     * @return static|null
     */
    public static function findCached($id, array $with = [])
    {
        if (!ApiModelCache::isCachingEnabled()) {
            $model = static::find($id);
            if ($model && !empty($with)) {
                $model->load($with);
            }

            return $model;
        }

        return ApiModelCache::cacheModel(
            new static(),
            $id,
            function () use ($id, $with) {
                $model = static::find($id);
                if ($model && !empty($with)) {
                    $model->load($with);
                }

                return $model;
            },
            $with,
            ApiModelCache::getModelTtl()
        );
    }

    /**
     * Find a model by public ID with caching.
     *
     * @return static|null
     */
    public static function findByPublicIdCached(string $publicId, array $with = [])
    {
        if (!ApiModelCache::isCachingEnabled()) {
            $model = static::where('public_id', $publicId)->first();
            if ($model && !empty($with)) {
                $model->load($with);
            }

            return $model;
        }

        return ApiModelCache::cacheModel(
            new static(),
            "public_id:{$publicId}",
            function () use ($publicId, $with) {
                $model = static::where('public_id', $publicId)->first();
                if ($model && !empty($with)) {
                    $model->load($with);
                }

                return $model;
            },
            $with,
            ApiModelCache::getModelTtl()
        );
    }

    /**
     * Load a relationship with caching.
     */
    public function loadCached(string $relationshipName)
    {
        if (!ApiModelCache::isCachingEnabled()) {
            return $this->load($relationshipName);
        }

        // Check if relationship is already loaded
        if ($this->relationLoaded($relationshipName)) {
            return $this;
        }

        $cachedRelation = ApiModelCache::cacheRelationship(
            $this,
            $relationshipName,
            fn () => $this->{$relationshipName},
            ApiModelCache::getRelationshipTtl()
        );

        // Set the relationship on the model
        $this->setRelation($relationshipName, $cachedRelation);

        return $this;
    }

    /**
     * Load multiple relationships with caching.
     *
     * @param array|string $relationships
     *
     * @return $this
     */
    public function loadMultipleCached($relationships)
    {
        if (!ApiModelCache::isCachingEnabled()) {
            return $this->load($relationships);
        }

        $relationships = is_string($relationships) ? func_get_args() : $relationships;

        foreach ($relationships as $relationship) {
            $this->loadCached($relationship);
        }

        return $this;
    }

    /**
     * Invalidate all caches for this model.
     */
    public function invalidateApiCache(): void
    {
        if (!ApiModelCache::isCachingEnabled()) {
            return;
        }

        // Get company UUID if available
        $companyUuid = null;
        if (isset($this->company_uuid)) {
            $companyUuid = $this->company_uuid;
        }

        ApiModelCache::invalidateModelCache($this, $companyUuid);
    }

    /**
     * Invalidate cache for a specific query.
     */
    public function invalidateQueryCache(Request $request, array $additionalParams = []): void
    {
        if (!ApiModelCache::isCachingEnabled()) {
            return;
        }

        ApiModelCache::invalidateQueryCache($this, $request, $additionalParams);
    }

    /**
     * Manually invalidate API caches when model events are bypassed.
     *
     * This method should be called after operations that modify or delete
     * records without triggering Eloquent model events (e.g. bulk deletes,
     * bulk updates, raw queries, or maintenance scripts).
     *
     * It performs a table-level cache invalidation, clearing all related
     * model, relationship, and query caches and incrementing the internal
     * query version counter to prevent stale reads.
     *
     * @param string|null $companyUuid Optional company UUID for scoped invalidation
     */
    public static function invalidateApiCacheManually(?string $companyUuid = null): void
    {
        ApiModelCache::invalidateModelCache(new static(), $companyUuid);
    }

    /**
     * Warm up cache for common queries.
     */
    public static function warmUpCache(Request $request, ?\Closure $queryCallback = null): void
    {
        if (!ApiModelCache::isCachingEnabled()) {
            return;
        }

        $model = new static();
        ApiModelCache::warmCache(
            $model,
            $request,
            fn () => $model->queryFromRequest($request, $queryCallback)
        );
    }

    /**
     * Check if caching is enabled for this model.
     */
    public function isCachingEnabled(): bool
    {
        // Check if model has caching disabled
        if (property_exists($this, 'disableApiCache') && $this->disableApiCache === true) {
            return false;
        }

        return ApiModelCache::isCachingEnabled();
    }

    /**
     * Get cache statistics for this model.
     */
    public static function getCacheStats(): array
    {
        return ApiModelCache::getStats();
    }
}
