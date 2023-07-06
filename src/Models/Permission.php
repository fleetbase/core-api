<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\HasUuid;
use Spatie\Permission\Models\Permission as BasePermission;

class Permission extends BasePermission
{
    use HasUuid, HasApiModelBehavior, Searchable, Filterable;

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The primary key type.
     *
     * @var string
     */
    public $keyType = 'string';

    /**
     * The column to use for generating uuid.
     *
     * @var string
     */
    public $uuidColumn = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var boolean
     */
    public $incrementing = false;

    /**
     * The attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /** @method withTrashed ghost scope for non soft deletes */
    public function scopeWithTrashed($query)
    {
        return $query;
    }
}
