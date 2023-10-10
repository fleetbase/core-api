<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasPermissions;

class Policy extends Model
{
    use HasUuid;
    use HasApiModelBehavior;
    use HasPermissions;
    use SoftDeletes;
    use Searchable;
    use Filterable;

    /** @__construct */
    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? config('auth.defaults.guard');

        parent::__construct($attributes);
    }

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
     * The default guard for this model.
     *
     * @var string
     */
    public $guard_name = 'api';

    /**
     * The column to use for generating uuid.
     *
     * @var string
     */
    public $uuidColumn = 'id';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'policies';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['company_uuid', 'name', 'guard_name', 'description'];

    /**
     * The guarded attributes.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The relationships that will always be appended.
     *
     * @var array
     */
    protected $with = ['permissions'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['type', 'is_mutable', 'is_deletable'];

    /**
     * Cannot set permissions to Role model directly.
     *
     * @return void
     */
    public function setPermissionsAttribute()
    {
        unset($this->attributes['permissions']);
    }

    /**
     * Check if the company_uuid attribute is set.
     *
     * @return bool
     */
    public function getIsMutableAttribute()
    {
        return isset($this->company_uuid);
    }

    /**
     * Check if the company_uuid attribute is set.
     *
     * @return bool
     */
    public function getIsDeletableAttribute()
    {
        return isset($this->company_uuid);
    }

    /**
     * Get the type of attribute based on the company_uuid.
     *
     * @return string
     */
    public function getTypeAttribute()
    {
        return empty($this->company_uuid) ? 'FLB Managed' : 'Organization Managed';
    }

    /**
     * Default guard should be `web`.
     *
     * @return void
     */
    public function setGuardNameAttribute()
    {
        $this->attributes['guard_name'] = 'web';
    }
}
