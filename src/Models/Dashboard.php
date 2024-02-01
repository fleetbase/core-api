<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Facades\Session;
use Fleetbase\Models\DashboardWidget;

class Dashboard extends Model
{
    use HasUuid;
    use HasApiModelBehavior;
    use Searchable;
    use Filterable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'dashboards';

    protected $fillable = [
        'name',
        'owner_uuid'
    ];
    
    protected $with = ['widgets'];
    
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($dashboard) {
            $isDefaultDashboard = $dashboard->owner_uuid === 'system';

            if ($isDefaultDashboard) {
                return;
            }

            $userId = Session::has('user') ? Session::get('user') : null;
            
            // Set the owner UUID for the dashboard during creation
            $dashboard->owner_uuid = $userId;
            $dashboard->is_default = true;
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function widgets()
    {
        return $this->hasMany(DashboardWidget::class);
    }

}

