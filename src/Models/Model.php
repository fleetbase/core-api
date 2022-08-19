<?php

namespace Fleetbase\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Fleetbase\Traits\HasCacheableAttributes;
use Fleetbase\Traits\ClearsHttpCache;

class Model extends EloquentModel
{
    use SoftDeletes, HasCacheableAttributes, ClearsHttpCache;

    public function resolveChildRouteBinding($childType, $value, $field)
    {
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
     * Saves the model instance and returns itself
     *
     * @return \Fleetbase\Models\BaseModel
     */
    public function saveInstance()
    {
        $this->save();
        return $this;
    }

    /**
     * Allow parent model to hook into bulk insert and modify row.
     *
     * @param array $row
     * @return void
     */
    public static function onRowInsert($row)
    {
        /**
         * -- do something with the row
         */
        return $row;
    }

    /**
     * Bulk insert for model as well as generate uuid and setting created_at.
     *
     * @return boolean
     */
    public static function bulkInsert(array $rows = []): bool
    {
        for ($i = 0; $i < count($rows); $i++) {
            $rows[$i]['uuid'] = static::generateUuid();
            $rows[$i]['created_at'] = Carbon::now()->toDateTimeString();

            $rows[$i] = static::onRowInsert($rows[$i]);
        }

        return static::insert($rows);
    }

    /**
     * {@inheritdoc}
     */
    public function getQueueableRelations()
    {
        return [];
    }

    public static function isSearchable()
    {
        return class_uses_recursive(\Fleetbase\Traits\Searchable::class) || (property_exists(new static, 'searchable') && static::$searchable);
    }

    public function searchable()
    {
        return static::isSearchable();
    }

    /**
     * @return void
     */
    public static function _flushCache()
    {
        $instance = new static();

        if (method_exists($instance, 'isCachable') && $instance->isCachable()) {
            return $instance->flushCache();
        }
    }
}
