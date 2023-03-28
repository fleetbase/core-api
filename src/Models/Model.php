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
     * Adds a universal query scope `searchWhere` which performs a case insensitive like on a column.
     * If `$strict` is true, then it will use a classic `where()` on the column.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column
     * @param string $search
     * @param boolean $strict
     * @return void
     */
    public function scopeSearchWhere($query, $column, $search, $strict = false)
    {
        if ($strict === true) {
            return $query->where($column, $search);
        }

        return $query->where(DB::raw("lower($column)"), 'like', '%' . str_replace('.', '%', str_replace(',', '%', $search)) . '%');
    }

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
