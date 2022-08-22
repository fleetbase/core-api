<?php

namespace Fleetbase\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Fleetbase\Traits\HasCacheableAttributes;
use Fleetbase\Traits\ClearsHttpCache;
use Fleetbase\Traits\Expandable;
use Fleetbase\Traits\Filterable;

class Model extends EloquentModel
{
    use SoftDeletes, HasCacheableAttributes, ClearsHttpCache, Expandable, Filterable;

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

    public function resolveChildRouteBinding($childType, $value, $field)
    {
    }

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
}
