<?php
namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;

class DashboardWidget extends Model
{
    protected $fillable = [
        'name',
        'component',
        'grid_options',
        'options',
        'dashboard_uuid',
    ];

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class);
    }
}
