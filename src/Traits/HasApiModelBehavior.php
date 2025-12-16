<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Auth;
use Fleetbase\Support\Http;
use Fleetbase\Support\QueryOptimizer;
use Fleetbase\Support\Resolve;
use Fleetbase\Support\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds API Model Behavior.
 */
trait HasApiModelBehavior
{
    /**
     * Boot the HasApiModelBehavior trait.
     * 
     * Registers model event listeners for automatic cache invalidation.
     */
    public static function bootHasApiModelBehavior()
    {
        // Only set up cache invalidation if caching is enabled
        if (!config('api.cache.enabled', true)) {
            return;
        }

        // Invalidate cache when model is created
        static::created(function ($model) {
            $model->invalidateApiCacheOnChange();
        });

        // Invalidate cache when model is updated
        static::updated(function ($model) {
            $model->invalidateApiCacheOnChange();
        });

        // Invalidate cache when model is deleted
        static::deleted(function ($model) {
            $model->invalidateApiCacheOnChange();
        });

        // Invalidate cache when model is restored (soft deletes)
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->invalidateApiCacheOnChange();
            });
        }
    }

    /**
     * Invalidate API cache when model changes.
     * 
     * @return void
     */
    protected function invalidateApiCacheOnChange(): void
    {
        if (!config('api.cache.enabled', true)) {
            return;
        }

        // Get company UUID if available
        $companyUuid = null;
        if (isset($this->company_uuid)) {
            $companyUuid = $this->company_uuid;
        }

        // Use ApiModelCache if available
        if (class_exists('\Fleetbase\Support\ApiModelCache')) {
            \Fleetbase\Support\ApiModelCache::invalidateModelCache($this, $companyUuid);
        }
    }

    /**
     * The name of the database column used to store the public ID for this model.
     *
     * @var string
     */
    public static $publicIdColumn = 'public_id';

    /**
     * Get the fully qualified name of the database column used to store the public ID for this model.
     *
     * @return string the fully qualified name of the public ID column
     */
    public function getQualifiedPublicId()
    {
        return static::$publicIdColumn;
    }

    /**
     * Get the plural name of this model, either from the `pluralName` property or by inflecting the table name.
     *
     * @return string the plural name of this model
     */
    public function getPluralName(): string
    {
        if (isset($this->pluralName)) {
            return $this->pluralName;
        }

        if (isset($this->payloadKey)) {
            return Str::plural($this->payloadKey);
        }

        return Str::plural($this->getTable());
    }

    /**
     * Get the singular name of this model, either from the `singularName` property or by inflecting the table name.
     *
     * @return string the singular name of this model
     */
    public function getSingularName(): string
    {
        if (isset($this->singularName)) {
            return $this->singularName;
        }

        if (isset($this->payloadKey)) {
            return Str::singular($this->payloadKey);
        }

        return Str::singular($this->getTable());
    }

    /**
     * Returns a list of fields that can be searched / filtered by. This includes
     * all fillable columns, the primary key column, and the created_at
     * and updated_at columns.
     *
     * @return array
     */
    public function searcheableFields()
    {
        if ($this->searchableColumns) {
            return $this->searchableColumns;
        }

        return array_merge(
            $this->fillable,
            [
                $this->getKeyName(),
                $this->getCreatedAtColumn(),
                $this->getUpdatedAtColumn(),
            ]
        );
    }

    /**
     * Retrieves all records based on request data passed in.
     *
     * @param Request       $request       the HTTP request containing the input data
     * @param \Closure|null $queryCallback optional callback to modify data with Request and QueryBuilder instance
     *
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function queryFromRequest(Request $request, ?\Closure $queryCallback = null)
    {
        // Check if model has caching enabled via HasApiModelCache trait
        if ($this->shouldUseCache()) {
            return $this->queryFromRequestCached($request, $queryCallback);
        }

        $limit   = $request->integer('limit', 30);
        $columns = $request->input('columns', ['*']);

        /**
         * @var \Illuminate\Database\Eloquent\Builder $builder
         */
        $builder = $this->searchBuilder($request, $columns);

        if (intval($limit) > 0) {
            $builder->limit($limit);
        } elseif ($limit === -1) {
            $limit = 999999999;
            $builder->limit($limit);
        }

        // if queryCallback is supplied
        if (is_callable($queryCallback)) {
            $queryCallback($builder, $request);
        }

        /* debug */
        // Utils::sqlDump($builder);

        if (Http::isInternalRequest($request)) {
            return $builder->fastPaginate($limit, $columns);
        }

        // get the results
        $result = $builder->get($columns);

        // mutate if mutation causing params present
        return static::mutateModelWithRequest($request, $result);
    }

    /**
     * Check if this model should use caching.
     * 
     * @return bool
     */
    protected function shouldUseCache(): bool
    {
        // Check if HasApiModelCache trait is used
        $traits = class_uses_recursive(static::class);
        $hasCacheTrait = isset($traits['Fleetbase\\Traits\\HasApiModelCache']);
        
        if (!$hasCacheTrait) {
            return false;
        }
        
        // Check if caching is disabled for this specific model
        if (property_exists($this, 'disableApiCache') && $this->disableApiCache === true) {
            return false;
        }
        
        // Check if API caching is enabled globally
        return config('api.cache.enabled', true);
    }

    /**
     * Static alias for queryFromRequest().
     *
     * @see queryFromRequest()
     *
     * @param Request       $request       the HTTP request containing the input data
     * @param \Closure|null $queryCallback optional callback to modify data with Request and QueryBuilder instance
     *
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @static
     */
    public static function queryWithRequest(Request $request, ?\Closure $queryCallback = null)
    {
        return (new static())->queryFromRequest($request, $queryCallback);
    }

    /**
     * Create a new record in the database based on the input data in the given request.
     *
     * @param Request       $request  the HTTP request containing the input data
     * @param callable|null $onBefore an optional callback function to execute before creating the record
     * @param callable|null $onAfter  an optional callback function to execute after creating the record
     * @param array         $options  an optional array of additional options
     *
     * @return mixed the newly created record, or a JSON response if the callbacks return one
     */
    public function createRecordFromRequest($request, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        $input = $this->getApiPayloadFromRequest($request);
        $input = $this->fillSessionAttributes($input);

        if (is_callable($onBefore)) {
            $before = $onBefore($request, $input);
            if ($before instanceof JsonResponse) {
                return $before;
            }
        }

        // Check if the Model has a custom creation method defined
        if (property_exists($this, 'creationMethod') && method_exists($this, $this->creationMethod)) {
            // Call the custom creation method
            $record = $this->{$this->creationMethod}($input);
        } else {
            // Default creation method
            $record = static::create($input);
        }

        if (isset($options['return_object']) && $options['return_object'] === true) {
            return $record;
        }

        // PERFORMANCE OPTIMIZATION: Use load() instead of re-querying the database
        // This avoids an unnecessary second database query
        $with = $request->or(['with', 'expand'], []);
        if (!empty($with)) {
            $record->load($with);
        }

        // Load counts if requested
        $withCount = $request->array('with_count', []);
        if (!empty($withCount)) {
            $record->loadCount($withCount);
        }

        if (is_callable($onAfter)) {
            $after = $onAfter($request, $record, $input);
            if ($after instanceof JsonResponse) {
                return $after;
            }
        }

        return static::mutateModelWithRequest($request, $record);
    }

    /**
     * Update an existing record in the database based on the input data in the given request.
     *
     * @param Request       $request  the HTTP request containing the input data
     * @param mixed         $id       the ID of the record to update
     * @param callable|null $onBefore an optional callback function to execute before updating the record
     * @param callable|null $onAfter  an optional callback function to execute after updating the record
     * @param array         $options  an optional array of additional options
     *
     * @return mixed the updated record, or a JSON response if the callbacks return one
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException if the record with the given ID is not found
     * @throws \Exception                                                    if the input contains an invalid parameter that is not fillable
     */
    public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        $builder = $this->where(function ($q) use ($id) {
            $publicIdColumn = $this->getQualifiedPublicId();

            $q->where($this->getQualifiedKeyName(), $id);
            if ($this->isColumn($publicIdColumn)) {
                $q->orWhere($publicIdColumn, $id);
            }
        });
        $builder = $this->applyDirectivesToQuery($request, $builder);
        $record  = $builder->first();

        if (!$record) {
            throw new \Exception($this->getApiHumanReadableName() . ' not found');
        }

        $input = $this->getApiPayloadFromRequest($request);
        $input = $this->fillSessionAttributes($input, [], ['updated_by_uuid']);

        if (is_callable($onBefore)) {
            $before = $onBefore($request, $record, $input);
            if ($before instanceof JsonResponse) {
                return $before;
            }
        }

        $keys = array_keys($input);

        foreach ($keys as $key) {
            if ($this->isInvalidUpdateParam($key)) {
                throw new \Exception('Invalid param "' . $key . '" in update request!');
            }
        }

        // Remove ID's and timestamps from input
        $input = Arr::except($input, ['uuid', 'public_id', 'deleted_at', 'updated_at', 'created_at']);
        try {
            $record->update($input);
        } catch (\Exception $e) {
            throw new \Exception(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Failed to update ' . $this->getApiHumanReadableName());
        }

        if (isset($options['return_object']) && $options['return_object'] === true) {
            return $record;
        }

        // PERFORMANCE OPTIMIZATION: Use load() instead of re-querying the database
        // This avoids an unnecessary second database query
        $with = $request->or(['with', 'expand'], []);
        if (!empty($with)) {
            $record->load($with);
        }

        // Load counts if requested
        $withCount = $request->array('with_count', []);
        if (!empty($withCount)) {
            $record->loadCount($withCount);
        }

        if (is_callable($onAfter)) {
            $after = $onAfter($request, $record, $input);
            if ($after instanceof JsonResponse) {
                return $after;
            }
        }

        return static::mutateModelWithRequest($request, $record);
    }

    /**
     * Removes a record from the database based on the given ID.
     *
     * @param mixed $id The ID or public ID of the record to remove
     *
     * @return bool|int The number of records affected, or false if the record is not found
     *
     * @throws \Exception If there's an issue while deleting the record
     */
    public function remove($id)
    {
        $record = $this->where(function ($q) use ($id) {
            $publicIdColumn = $this->getQualifiedPublicId();

            $q->where($this->getQualifiedKeyName(), $id);
            if ($this->isColumn($publicIdColumn)) {
                $q->orWhere($publicIdColumn, $id);
            }
        });

        if (!$record) {
            return false;
        }

        try {
            return $record->delete();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Removes multiple records from the database based on the given IDs.
     *
     * @param array $ids The array of IDs or public IDs of the records to remove
     *
     * @return bool|int The number of records affected, or false if no records are found
     *
     * @throws \Exception If there's an issue while deleting the records
     */
    public function bulkRemove($ids = [])
    {
        $records = $this->where(function ($q) use ($ids) {
            $publicIdColumn = $this->getQualifiedPublicId();

            $q->whereIn($this->getQualifiedKeyName(), $ids);
            if ($this->isColumn($publicIdColumn)) {
                $q->orWhereIn($publicIdColumn, $ids);
            }
        });

        if (!$records) {
            return false;
        }

        $count = $records->count();

        try {
            $records->delete();

            return $count;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Mutates the given model, collection or array of results with the request.
     * Applies 'with' and 'without' parameters to the result.
     *
     * @param Request $request The request object containing the 'with' and 'without' parameters
     * @param mixed   $result  The model, collection or array of results to be mutated
     *
     * @return mixed The mutated model, collection or array of results
     */
    public static function mutateModelWithRequest(Request $request, $result)
    {
        $with    = $request->or(['with', 'expand'], []);
        $without = $request->array('without');

        // handle collection or array of results
        if (is_array($result) || $result instanceof \Illuminate\Support\Collection) {
            return collect($result)->map(
                function ($model) use ($request) {
                    return static::mutateModelWithRequest($request, $model);
                }
            );
        }

        if ($with) {
            $result->load($with);
        }

        if ($without) {
            $result->setHidden($without);
        }

        return $result;
    }

    /**
     * Fills the target array with session attributes based on the specified rules.
     * Allows to apply exceptions and only specific attributes to be filled.
     *
     * @param array $target The target array to fill with session attributes
     * @param array $except The list of attributes that should not be filled (default: [])
     * @param array $only   The list of attributes that should only be filled (default: [])
     *
     * @return array The filled target array with session attributes
     */
    public function fillSessionAttributes(?array $target = [], array $except = [], array $only = []): array
    {
        $fill       = [];
        $attributes = [
            'user_uuid'       => 'user',
            'author_uuid'     => 'user',
            'uploader_uuid'   => 'user',
            'creator_uuid'    => 'user',
            'created_by_uuid' => 'user',
            'updated_by_uuid' => 'user',
            'company_uuid'    => 'company',
        ];

        foreach ($attributes as $attr => $key) {
            if ((!empty($only) && !in_array($attr, $only)) || isset($target[$attr])) {
                continue;
            }

            if ($this->isSessionAgnosticColumn($attr)) {
                continue;
            }

            if ($this->isFillable($attr) && !in_array($except, array_keys($attributes))) {
                $fill[$attr] = session($key);
            }
        }

        return array_merge($target, $fill);
    }

    /**
     * Checks if request contains relationships.
     *
     * @param \Illuminate\Database\Query\Builder $builder
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function withRelationships(Request $request, $builder)
    {
        $with    = $request->or(['with', 'expand']);
        $without = $request->array('without', []);

        if (!$with && !$without) {
            return $builder;
        }

        $contains = is_array($with) ? $with : explode(',', $with);

        foreach ($contains as $contain) {
            $camelVersion = Str::camel(trim($contain));
            if (\method_exists($this, $camelVersion)) {
                $builder->with($camelVersion);
                continue;
            }

            $snakeCase = Str::snake(trim($contain));
            if (\method_exists($this, $snakeCase)) {
                $builder->with(trim($snakeCase));
                continue;
            }

            if (strpos($contain, '.') !== false) {
                $parts = array_map(
                    function ($part) {
                        return Str::camel($part);
                    },
                    explode('.', $contain)
                );
                $contain = implode('.', $parts);

                $builder->with($contain);
                continue;
            }
        }

        if ($without) {
            $builder->without($without);
        }

        return $builder;
    }

    /**
     * Checks if request includes counts.
     *
     * @param Request                            $request
     * @param \Illuminate\Database\Query\Builder $builder
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function withCounts($request, $builder)
    {
        $count = $request->or(['count', 'with_count']);

        if (!$count) {
            return $builder;
        }

        $counters = explode(',', $count);

        foreach ($counters as $counter) {
            if (\method_exists($this, $counter)) {
                $builder->withCount($counter);
                continue;
            }

            $camelVersion = Str::camel($counter);
            if (\method_exists($this, $camelVersion)) {
                $builder->withCount($camelVersion);
                continue;
            }
        }

        return $builder;
    }

    /**
     * Apply sorts to query.
     *
     * @param Request                            $request - HTTP Request
     * @param \Illuminate\Database\Query\Builder $builder - Query Builder
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function applySorts($request, $builder)
    {
        // Grab the raw sort input
        $sorts = (array) $request->array('sort');

        // Nothing to sort by
        if (empty($sorts)) {
            return $builder;
        }

        foreach ($sorts as $sort) {
            $sort = trim($sort);

            if ($sort === '') {
                continue;
            }

            // Handle special keywords that don't care about column name
            if (Schema::hasColumn($this->getTable(), $this->getCreatedAtColumn())) {
                $sortLower = strtolower($sort);

                if ($sortLower === 'latest') {
                    $builder->orderBy($this->qualifySortColumn($this->getCreatedAtColumn()), 'desc');
                    continue;
                }

                if ($sortLower === 'oldest') {
                    $builder->orderBy($this->qualifySortColumn($this->getCreatedAtColumn()), 'asc');
                    continue;
                }
            }

            // Custom distance sort hook
            if (strtolower($sort) === 'distance') {
                $builder->orderByDistance();
                continue;
            }

            // Normal case: "status", "-status", "status:desc", etc.
            // Delegate parsing of direction & column to Http::useSort
            [$column, $direction] = Http::useSort($sort);

            if (!empty($column)) {
                $builder->orderBy($this->qualifySortColumn($column), $direction ?: 'asc');
            }
        }

        return $builder;
    }

    /**
     * Retrieves a record based on primary key id.
     *
     * @param string  $id      - The ID
     * @param Request $request - HTTP Request
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getById($id, ?callable $queryCallback = null, Request $request)
    {
        $builder = $this->where(function ($q) use ($id) {
            $publicIdColumn = $this->getQualifiedPublicId();

            $q->where($this->getQualifiedKeyName(), $id);
            if ($this->isColumn($publicIdColumn)) {
                $q->orWhere($publicIdColumn, $id);
            }
        });

        if (is_callable($queryCallback)) {
            $queryCallback($builder, $request);
        }

        $builder = $this->withCounts($request, $builder);
        $builder = $this->withRelationships($request, $builder);
        $builder = $this->applySorts($request, $builder);
        $builder = $this->applyDirectivesToQuery($request, $builder);

        return $builder->first();
    }

    /**
     * Retrieves the options for the given model.
     *
     * @return array An array of options with 'value' and 'label' keys
     */
    public function getOptions()
    {
        $builder = $this->select($this->option_key, $this->option_label)
            ->orderBy($this->option_label, 'asc')
            ->get();

        // convert data to standard object {value:'', label:''}
        $arr = [];
        foreach ($builder as $x) {
            if ($x[$this->option_label]) {
                $arr[] = [
                    'value' => $x[$this->option_key],
                    'label' => $x[$this->option_label],
                ];
            }
        }

        return $arr;
    }

    /**
     * Searches for records based on the request parameters and returns a paginated result.
     *
     * @param Request $request The request object containing the search parameters
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator The paginated search results
     */
    public function searchRecordFromRequest(Request $request)
    {
        $limit   = $request->integer('limit', 30);
        $builder =  $this->searchBuilder($request);

        return $builder->fastPaginate($limit);
    }

    /**
     * Builds the search query based on the request parameters.
     *
     * @param Request $request The request object containing the search parameters
     *
     * @return \Illuminate\Database\Eloquent\Builder The search query builder
     */
    public function searchBuilder(Request $request, $columns = ['*'])
    {
        $builder = self::query()->select($columns);

        // PERFORMANCE OPTIMIZATION: Apply authorization directives FIRST to reduce dataset early
        $builder = $this->applyDirectivesToQuery($request, $builder);

        // CRITICAL: Apply custom filters ALWAYS (handles queryForInternal/queryForPublic)
        // This MUST run before the fast path check to ensure data isolation
        $builder = $this->applyCustomFilters($request, $builder);

        // PERFORMANCE OPTIMIZATION: Check if this is a simple query (no filters, sorts, or relationships)
        // This avoids unnecessary method calls for the most common case
        $hasFilters = $request->has('filters') || count($request->except(['limit', 'offset', 'page', 'sort', 'order'])) > 0;
        $hasSorts = $request->has('sort') || $request->has('order');
        $hasRelationships = $request->has('with') || $request->has('expand') || $request->has('without');
        $hasCounts = $request->has('with_count');

        if (!$hasFilters && !$hasSorts && !$hasRelationships && !$hasCounts) {
            // Fast path: no additional processing needed (custom filters already applied)
            return $builder;
        }

        // PERFORMANCE OPTIMIZATION: Only apply optimized filters if there are actual filter parameters
        if ($hasFilters) {
            $builder = $this->applyOptimizedFilters($request, $builder);
        }

        // Only apply sorts if requested
        if ($hasSorts) {
            $builder = $this->applySorts($request, $builder);
        }

        // Only eager-load relationships if requested
        if ($hasRelationships) {
            $builder = $this->withRelationships($request, $builder);
        }

        if ($hasCounts) {
            $builder = $this->withCounts($request, $builder);
        }

        // PERFORMANCE OPTIMIZATION: Apply query optimizer to remove duplicate where clauses
        $builder = $this->optimizeQuery($builder);

        return $builder;
    }

    /**
     * Applies all authorization directives from the request to the given query builder.
     *
     * This method retrieves directives from the request using the `Auth::getDirectivesFromRequest` method,
     * then iterates over each directive and applies it to the provided query builder. The directives modify
     * the query to enforce the appropriate access controls based on the authenticated user's permissions.
     *
     * @param Request                               $request the HTTP request containing the authorization directives
     * @param \Illuminate\Database\Eloquent\Builder $builder the query builder instance to which the directives will be applied
     *
     * @return \Illuminate\Database\Eloquent\Builder the modified query builder with all directives applied
     */
    public function applyDirectivesToQuery(Request $request, $builder)
    {
        $directives       = Auth::getDirectivesFromRequest($request);
        $uniqueDirectives = $directives->unique('rules');
        foreach ($uniqueDirectives as $directive) {
            $directive->apply($builder);
        }

        return $builder;
    }

    /**
     * Optimizes the given query builder by removing duplicate where clauses.
     *
     * This method takes a query builder instance and passes it to the QueryOptimizer,
     * which processes the query to remove any duplicate where clauses while ensuring
     * that the associated bindings are correctly managed. This optimization helps in
     * improving query performance and avoiding potential issues with redundant conditions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $builder the query builder instance to optimize
     *
     * @return \Illuminate\Database\Eloquent\Builder the optimized query builder with unique where clauses
     */
    public function optimizeQuery($builder)
    {
        return QueryOptimizer::removeDuplicateWheres($builder);
    }

    /**
     * Applies custom filters to the search query based on the request parameters.
     *
     * @param Request                               $request The request object containing the custom filter parameters
     * @param \Illuminate\Database\Eloquent\Builder $builder The search query builder
     *
     * @return \Illuminate\Database\Eloquent\Builder The search query builder with custom filters applied
     */
    public function applyCustomFilters(Request $request, $builder)
    {
        $resourceFilter = Resolve::httpFilterForModel($this, $request);

        if ($resourceFilter) {
            $builder->filter($resourceFilter);
        }

        // handle with/without here
        $with             = $request->or(['with', 'expand'], []);
        $without          = $request->array('without');
        $withoutRelations = $request->boolean('without_relations');

        // camelcase all params in with and apply
        if (is_array($with)) {
            $with = array_map(
                function ($relationship) {
                    return Str::camel($relationship);
                },
                $with
            );

            $builder = $builder->with($with);
        }

        // camelcase all params in with and apply
        if (is_array($without)) {
            $without = array_map(
                function ($relationship) {
                    return Str::camel($relationship);
                },
                $without
            );

            $builder = $builder->without($without);
        }

        // if to query without all relations
        if ($withoutRelations) {
            $builder = $builder->withoutRelations();
        }

        return $builder;
    }

    /**
     * Applies filters to the search query based on the request parameters.
     *
     * @param Request                               $request The request object containing the filter parameters
     * @param \Illuminate\Database\Eloquent\Builder $builder The search query builder
     *
     * @return \Illuminate\Database\Eloquent\Builder The search query builder with filters applied
     */
    public function applyFilters(Request $request, $builder)
    {
        $operators = $this->getQueryOperators();
        $filters   = $request->input('filters', []);

        foreach ($filters as $column => $values) {
            if (!in_array($column, $this->searcheableFields())) {
                continue;
            }

            $valueParts      = explode(':', $values);
            $operator        = 'eq';
            $operator_symbol = '=';
            $value           = null;

            if (count($valueParts) > 1) {
                $operator        = $valueParts[0];
                $operator_symbol = $operators[$operator] ?? '=';
                $value           = $valueParts[1];
            } else {
                $value = $valueParts[0];
            }

            if ($this->prioritizedCustomColumnFilter($request, $builder, $column)) {
                continue;
            }

            $builder = $this->applyOperators($builder, $column, $operator, $operator_symbol, $value);
        }

        return $builder;
    }

    /**
     * PERFORMANCE OPTIMIZATION: Optimized filter application that merges buildSearchParams and applyFilters logic.
     * This method eliminates redundant iterations and string operations.
     *
     * Filters are only applied if the column is searchable (defined in searchableFields()).
     * Custom filters defined in Filter classes take precedence over automatic filtering.
     *
     * @param Request                               $request The request object containing filter parameters
     * @param \Illuminate\Database\Eloquent\Builder $builder The search query builder
     *
     * @return \Illuminate\Database\Eloquent\Builder The search query builder with filters applied
     */
    protected function applyOptimizedFilters(Request $request, $builder)
    {
        // Extract only filter parameters (exclude pagination, sorting, relationships)
        $filters = $request->except(['limit', 'offset', 'page', 'sort', 'order', 'with', 'expand', 'without', 'with_count']);
        
        if (empty($filters)) {
            return $builder;
        }

        $operators = $this->getQueryOperators();
        $operatorKeys = array_keys($operators);

        foreach ($filters as $key => $value) {
            // Skip empty values (but allow '0' and 0)
            if (empty($value) && $value !== '0' && $value !== 0) {
                continue;
            }

            // Skip if a custom filter method exists for this parameter
            if ($this->prioritizedCustomColumnFilter($request, $builder, $key)) {
                continue;
            }

            // Determine the column name and operator type
            $column = $key;
            $opKey = '=';
            $opType = '=';

            // Check if the parameter has an operator suffix (_in, _like, _gt, etc.)
            foreach ($operatorKeys as $op_key) {
                if (Str::endsWith(strtolower($key), strtolower($op_key))) {
                    $column = Str::replaceLast($op_key, '', $key);
                    $opKey = $op_key;
                    $opType = $operators[$op_key];
                    break;
                }
            }

            // Only apply filters for searchable columns
            // searchableFields() includes: fillable + primary key + timestamps + custom searchableColumns
            if ($this->isFillable($column) || in_array($column, ['uuid', 'public_id']) || in_array($column, $this->searcheableFields())) {
                $builder = $this->applyOperators($builder, $column, $opKey, $opType, $value);
            }
        }

        return $builder;
    }

    /**
     * Counts the records based on the request search parameters.
     *
     * @param Request $request The request object containing the search parameters
     *
     * @return int The number of records found
     */
    public function count(Request $request)
    {
        return $this->buildSearchParams($request, self::query())->count();
    }

    /**
     * Checks if the custom column filter should be prioritized.
     *
     * @param Request                               $request The request object containing filter parameters
     * @param \Illuminate\Database\Eloquent\Builder $builder The search query builder
     * @param string                                $column  The column name
     *
     * @return bool True if the custom column filter should be prioritized, false otherwise
     */
    public function prioritizedCustomColumnFilter($request, $builder, $column)
    {
        $resourceFilter        = Resolve::httpFilterForModel($this, $request);
        $camelizedColumnName   = Str::camel($column);
        $camelizedRelationName = Str::camel(Str::replace('_uuid', '', $column));

        if (empty($resourceFilter)) {
            return false;
        }

        return method_exists($resourceFilter, $camelizedColumnName) || method_exists($resourceFilter, $column) || method_exists($resourceFilter, $camelizedRelationName);
    }

    /**
     * Builds the search parameters based on the request.
     *
     * @param Request                               $request The request object containing the search parameters
     * @param \Illuminate\Database\Eloquent\Builder $builder The search query builder
     *
     * @return \Illuminate\Database\Eloquent\Builder The search query builder with search parameters applied
     */
    public function buildSearchParams(Request $request, $builder)
    {
        $operators = $this->getQueryOperators();

        foreach ($request->getFilters() as $key => $value) {
            if ($this->prioritizedCustomColumnFilter($request, $builder, $key) || empty($value)) {
                continue;
            }

            $fieldEndsWithOperator = Str::endsWith($key, array_keys($operators));
            $isFillable            = $this->isFillable($key) || in_array($key, ['uuid', 'public_id']);

            if (!$fieldEndsWithOperator && $isFillable) {
                $builder->where($key, '=', $value);
                continue;
            }

            // apply special operators based on the column name passed
            foreach ($operators as $op_key => $op_type) {
                $key                   = strtolower($key);
                $op_key                = strtolower($op_key);
                $column                = Str::replaceLast($op_key, '', $key);
                $fieldEndsWithOperator = Str::endsWith($key, $op_key);

                if (!$fieldEndsWithOperator) {
                    continue;
                }

                $builder = $this->applyOperators($builder, $column, $op_key, $op_type, $value);
            }
        }

        return $builder;
    }

    /**
     * Returns the query operators for filtering.
     *
     * @return array The query operators
     */
    private function getQueryOperators()
    {
        return [
            '_not'       => '!=',
            '_gt'        => '>',
            '_lt'        => '<',
            '_gte'       => '>=',
            '_lte'       => '<=',
            '_like'      => 'LIKE',
            '_in'        => true,
            '_notIn'     => true,
            '_isNull'    => true,
            '_isNotNull' => true,
        ];
    }

    /**
     * Applies the query operators to the search query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $builder     The search query builder
     * @param string                                $column_name The column name
     * @param string                                $op_key      The operator key
     * @param string                                $op_type     The operator type
     * @param mixed                                 $value       The value for the query operator
     *
     * @return \Illuminate\Database\Eloquent\Builder The search query builder with query operators applied
     */
    private function applyOperators($builder, $column_name, $op_key, $op_type, $value)
    {
        $column_name = $this->shouldQualifyColumn($column_name)
            ? $this->qualifyColumn($column_name)
            : $column_name;

        if ($op_key == '_in') {
            $builder->whereIn($column_name, explode(',', $value));
        } elseif ($op_key == strtolower('_notIn')) {
            $builder->whereNotIn($column_name, explode(',', $value));
        } elseif ($op_key == strtolower('_isNull')) {
            $builder->whereNull($column_name);
        } elseif ($op_key == strtolower('_isNotNull')) {
            $builder->whereNotNull($column_name);
        } elseif ($op_key == '_like') {
            $builder->where($column_name, 'LIKE', "{$value}%");
        } else {
            $builder->where($column_name, $op_type, $value);
        }

        return $builder;
    }

    /**
     * Determines whether the given column name should be qualified.
     *
     * @param string $column_name The column name
     *
     * @return bool True if the column should be qualified, false otherwise
     */
    public function shouldQualifyColumn($column_name)
    {
        return in_array($column_name, [
            $this->getKey() ?? 'uuid',
            $this->getCreatedAtColumn() ?? 'created_at',
            $this->getUpdatedAtColumn() ?? 'updated_at',
            $this->getDeletedAtColumn() ?? 'deleted_at',
        ]);
    }

    /**
     * Checks if a given key exists in the filter parameters.
     *
     * @param string $key the key to be checked for existence in the filter parameters
     *
     * @return bool returns true if the key exists in the filter parameters, otherwise returns false
     */
    public function isFilterParam(string $key): bool
    {
        if (!empty($this->filterParams) && is_array($this->filterParams)) {
            return in_array($key, $this->filterParams);
        }

        return false;
    }

    /**
     * Get the human-readable name for the API model.
     *
     * This function converts the table name of the model into a singular, title-cased string to be used as a human-readable name.
     *
     * @return string the human-readable name for the API model
     */
    public function getApiHumanReadableName()
    {
        return Utils::humanize($this->getTable());
    }

    /**
     * Get the API payload from the request.
     *
     * This function extracts the payload from the request using the singular name or camel-cased singular name as keys. If neither is found, it returns all input data.
     *
     * @param Request $request the incoming HTTP request instance
     *
     * @return array the extracted payload from the request
     */
    public function getApiPayloadFromRequest(Request $request): array
    {
        $payloadKeys = [$this->getSingularName(), Str::camel($this->getSingularName())];
        $input       = $request->or($payloadKeys) ?? $request->all();
        // the following input keys should always be managed by the server
        $input = Arr::except($input, ['company_uuid', 'created_by_uuid', 'updated_by_uuid', 'uploader_uuid']);

        return $input;
    }

    /**
     * Determines whether a given column exists in the table associated with the model.
     */
    public function isColumn(string $columnName): bool
    {
        $connectionName = config('database.default');
        $connection     = $this->getConnection();

        if ($connection instanceof \Illuminate\Database\Connection) {
            $connectionName = $connection->getName();
        }

        return Schema::connection($connectionName)->hasColumn($this->getTable(), $columnName);
    }

    /**
     * Determines whether a given parameter key is invalid for an update operation.
     *
     * This function checks if the provided key is not one of the timestamp fields, not a fillable attribute,
     * not a relation (either in its given form or in its camel case equivalent), not a filter parameter,
     * and not an appended attribute.
     *
     * @param string $key the parameter key to evaluate
     *
     * @return bool returns true if the key is not valid for updating; false otherwise
     */
    public function isInvalidUpdateParam(string $key): bool
    {
        $isNotTimestamp          = !in_array($key, ['created_at', 'updated_at', 'deleted_at']);
        $isNotFillable           = !$this->isFillable($key);
        $isNotGuarded            = !$this->isGuarded($key);
        $isNotRelation           = !$this->isRelation($key) && !$this->isRelation(Str::camel($key));
        $isNotFilterParam        = !$this->isFilterParam($key);
        $isNotAppenededAttribute = !in_array($key, $this->appends ?? []);
        $isNotIdParam            = !in_array($key, ['id', 'uuid', 'public_id']);

        return $isNotTimestamp && $isNotFillable && $isNotGuarded && $isNotRelation && $isNotFilterParam && $isNotAppenededAttribute && $isNotIdParam;
    }

    /**
     * Find a model by its `public_id` or `internal_id` key or throw an exception.
     *
     * @param mixed        $id            ID of the record to find
     * @param array        $with          Relationships to include
     * @param array        $columns       Columns to select in query
     * @param Closure|null $queryCallback Optional callback to modify the QueryBuilder
     *
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|static|static[]
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function findRecordOrFail($id, $with = [], $columns = ['*'], ?\Closure $queryCallback = null)
    {
        if (is_null($columns) || empty($columns)) {
            $columns = ['*'];
        }

        /** @var \Illuminate\Database\Eloquent\Model $instance New instance of current Model * */
        $instance = (new static());

        // has internal id?
        $hasInternalId = in_array('internal_id', $instance->getFillable());

        // create query
        $query = static::query()
            ->select($columns)
            ->with($with)
            ->where(
                function ($query) use ($id, $hasInternalId) {
                    $query->where('public_id', $id);

                    if ($hasInternalId) {
                        $query->orWhere('internal_id', $id);
                    }
                }
            );

        // more query modifications if callback supplied
        if (is_callable($queryCallback)) {
            $queryCallback($query);
        }

        // get result
        $result = $query->first();

        if (!is_null($result)) {
            return $result;
        }

        throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(static::class, $id);
    }

    /**
     * Return true if $column exists on the model's base table.
     */
    protected function isBaseColumn(string $column): bool
    {
        return !str_contains($column, '.') && Schema::hasColumn($this->getTable(), $column);
    }

    /**
     * Qualify base-table columns; leave anything else (aliases, joins, expressions) alone.
     */
    protected function qualifySortColumn(string $column): string
    {
        // If it's already qualified, an alias, or a function/expression, don't touch it
        if (str_contains($column, '.') || str_contains($column, '(') || str_contains($column, ' ')) {
            return $column;
        }

        // Qualify only when it's a real column on the base table
        return $this->isBaseColumn($column) ? ($this->getTable() . '.' . $column) : $column;
    }
}
