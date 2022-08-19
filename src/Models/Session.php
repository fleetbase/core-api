<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasUuid;
use Fleetbase\Support\Utils;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialTrait;
use Grimzy\LaravelMysqlSpatial\Types\Geometry;

class Session extends Model
{
    use HasUuid, SpatialTrait;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'sessions';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = ['ip_address', 'user_agent', 'origin_app'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_uuid',
        'company_uuid',
        'token_hash',
        'ip_address',
        'origin_app',
        'x_forwarded_for',
        'user_agent',
        'isp',
        'hostname',
        'timezone',
        'currency',
        'country',
        'region',
        'city',
        'postal_code',
        'location',
        'longitude',
        'latitude',
    ];

    /**
     * The attributes that are spatial columns.
     * 
     * @var array
     */
    protected $spatialFields = [
        'location'
    ];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * Convert coordinates to spatial geometry
     *
     * @param Object $coordinates
     * @return void
     */
    public function setLocationAttribute($location) {

        if(Utils::exists($location, 'coordinates')) {
            $location['coordinates'] = array_map(function($coord) {
                return (float) $coord;
            }, Utils::get($location, 'coordinates'));
        }

        if(Utils::exists($location, 'bbox')) {
            $location['bbox'] = array_map(function($coord) {
                return (float) $coord;
            }, Utils::get($location, 'bbox'));
        }

        $location = Geometry::fromJson(json_encode($location));

        $this->attributes['location'] = $location;
    }

    /**
     * The user of this session
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user of this session
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
