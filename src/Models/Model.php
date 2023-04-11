<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Fleetbase\Traits\HasCacheableAttributes;
use Fleetbase\Traits\ClearsHttpCache;
use Fleetbase\Traits\Expandable;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\Insertable;
use Fleetbase\Traits\Searchable;

class Model extends EloquentModel
{
    use SoftDeletes,
        HasCacheableAttributes,
        ClearsHttpCache,
        Expandable,
        Insertable,
        Filterable;

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
     * @return boolean
     */
    public static function isSearchable()
    {
        return in_array(Searchable::class, class_uses_recursive(static::class));
    }

    /**
     * Saves the model instance and returns itself
     *
     * @return \Fleetbase\Models\Model
     */
    public function saveInstance(): Model
    {
        $this->save();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueueableRelations()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function resolveChildRouteBinding($childType, $value, $field)
    {
    }

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
}
