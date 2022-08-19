<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\HasUuid;
use Spatie\Permission\Models\Permission as BasePermission;

class Permission extends BasePermission
{
    use HasUuid, HasApiModelBehavior, Searchable;

    /**
     * The column to use for generating uuid.
     *
     * @var string
     */
    public $uuidColumn = 'id';

    /**
     * The primary key type.
     *
     * @var string
     */
    public $keyType = 'string';

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
