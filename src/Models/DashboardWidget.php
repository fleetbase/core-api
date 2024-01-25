<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;

class DashboardWidget extends Model
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
    protected $table = 'dashboard_widgets';

    protected $fillable = [
        'name',
        'component',
        'grid_options',
        'options',
        'dashboard_uuid',
    ];

    protected $casts = [
        'grid_options' => Json::class,
        'options' => Json::class,
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class);
    }

}

