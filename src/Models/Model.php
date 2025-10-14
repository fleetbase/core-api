<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\ClearsHttpCache;
use Fleetbase\Traits\Expandable;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasCacheableAttributes;
use Fleetbase\Traits\HasSessionAttributes;
use Fleetbase\Traits\Insertable;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Model extends EloquentModel
{
    use SoftDeletes;
    use HasCacheableAttributes;
    use ClearsHttpCache;
    use Insertable;
    use Filterable;
    use Expandable;
    use HasSessionAttributes;

    /**
     * Column names used for identifier lookups.
     * Override in child models if your schema differs.
     */
    public const UUID_COLUMN      = 'uuid';
    public const PUBLIC_ID_COLUMN = 'public_id';

    /**
     * Create a new instance of the model.
     *
     * @param array $attributes the attributes to set on the model
     *
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->connection = config('fleetbase.db.connection');
    }

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'uuid';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var string
     */
    public $incrementing = false;

    /**
     * Determines if model is searchable.
     *
     * @return bool
     */
    public static function isSearchable()
    {
        return in_array(Searchable::class, class_uses_recursive(static::class));
    }

    /**
     * Saves the model instance and returns itself.
     */
    public function saveInstance(): Model
    {
        $this->save();

        return $this;
    }

    /**
     * Get the relationships that are queued for eager loading.
     *
     * @return array
     */
    public function getQueueableRelations()
    {
        return [];
    }

    /**
     * Retrieve a child model instance by binding it to the parent.
     *
     * @param string      $childType
     * @param string|null $field
     */
    public function resolveChildRouteBinding($childType, $value, $field)
    {
    }

    /**
     * Get the HTTP resource class for the model.
     *
     * @return string|null
     */
    public function getResource()
    {
        $resourceNamespace = null;

        if (isset($this->httpResource)) {
            $resourceNamespace = $this->httpResource;
        }

        if (isset($this->resource)) {
            $resourceNamespace = $this->resource;
        }

        return $resourceNamespace;
    }

    /**
     * Get the HTTP request class for the model.
     *
     * @return string|null
     */
    public function getRequest()
    {
        $requestNamespace = null;

        if (isset($this->httpRequest)) {
            $requestNamespace = $this->httpRequest;
        }

        if (isset($this->request)) {
            $requestNamespace = $this->request;
        }

        return $requestNamespace;
    }

    /**
     * Get the HTTP filter class for the model.
     *
     * @return string|null
     */
    public function getFilter()
    {
        $filterNamespace = null;

        if (isset($this->httpFilter)) {
            $filterNamespace = $this->httpFilter;
        }

        if (isset($this->filter)) {
            $filterNamespace = $this->filter;
        }

        return $filterNamespace;
    }

    /**
     * Find a model by either UUID or public_id.
     *
     * Looks up by {@see static::UUID_COLUMN} when the identifier is a valid UUID,
     * otherwise falls back to {@see static::PUBLIC_ID_COLUMN}.
     *
     * @param self|string|null $identifier  the UUID/public_id string, or a model instance (returned as-is)
     * @param array            $with        relationships to eager load
     * @param array            $columns     columns to select (default ['*'])
     * @param bool             $withTrashed include soft-deleted rows when the model uses SoftDeletes
     *
     * @return static|null the found model instance or null if none match
     */
    public static function findById(self|string|null $identifier, array $with = [], array $columns = ['*'], bool $withTrashed = false): ?self
    {
        if ($identifier instanceof self) {
            return $identifier;
        }

        if ($identifier === null || $identifier === '') {
            return null;
        }

        /** @var Builder $query */
        $query = static::query()->with($with)->select($columns);

        // Include soft-deleted rows if requested and supported.
        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        $column = Str::isUuid($identifier) ? static::UUID_COLUMN : static::PUBLIC_ID_COLUMN;

        return $query->where($column, $identifier)->first();
    }

    /**
     * Find a model by UUID or public_id, or throw a 404-style exception.
     *
     * Behaves like {@see findById()} but throws a ModelNotFoundException when not found.
     *
     * @param self|string|null $identifier  the UUID/public_id string, or a model instance (returned as-is)
     * @param array            $with        relationships to eager load
     * @param array            $columns     columns to select (default ['*'])
     * @param bool             $withTrashed include soft-deleted rows when the model uses SoftDeletes
     *
     * @return static
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function findByIdOrFail(self|string|null $identifier, array $with = [], array $columns = ['*'], bool $withTrashed = false): self
    {
        // Fast path if already a model instance
        if ($identifier instanceof self) {
            return $identifier;
        }

        $result = static::findById($identifier, $with, $columns, $withTrashed);

        if ($result === null) {
            /** @var class-string<static> $cls */
            $cls = static::class;
            throw (new static())->newModelQuery()->getModel()->newQuery()->getModel()::query()->getModel()::query()->getModelNotFoundException($cls, [$identifier]);
        }

        return $result;
    }
}
