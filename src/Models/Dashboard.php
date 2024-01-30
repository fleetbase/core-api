<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Support\Facades\Session;

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
    
    //  protected $with = ['widgets'];
    
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($dashboard) {
            // Ensure session('user') is set before trying to access it
            $userId = Session::has('user') ? Session::get('user') : null;
            
            $dashboard->owner_uuid = $userId;
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function widgets()
    {
        return $this->belongsToMany(DashboardWidget::class, 'dashboard_widgets as dw1', 'dashboard_uuid', 'uuid')
            ->withPivot('position', 'size')
            ->orderBy('dw1.position', 'asc');
    }

}

