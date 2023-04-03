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
use Illuminate\Support\Facades\DB;

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
}
