<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPolicies;
use Fleetbase\Traits\Expirable;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Support\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Vinkla\Hashids\Facades\Hashids;
use Spatie\Permission\Traits\HasPermissions;
use Spatie\Activitylog\Traits\LogsActivity;

class ApiCredential extends Model
{
    use HasUuid, HasApiModelBehavior, LogsActivity, Searchable, Expirable, HasPolicies, HasPermissions;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'api_credentials';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['uuid', '_key', 'user_uuid', 'company_uuid', 'name', 'key', 'secret', 'test_mode', 'api', 'browser_origins', 'last_used_at', 'expires_at'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'test_mode' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'browser_origins' => Json::class
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
     * The user the api credential was created by
     *
     * @param \Illuminate\Database\Eloquent\Relations\BelongsTo $company
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /**
     * The company the api credential belongs to
     *
     * @param \Illuminate\Database\Eloquent\Relations\BelongsTo $company
     */
    public function company()
    {
        return $this->belongsTo(Company::class)->withoutGlobalScopes();
    }

    /**
     * Set the expires at, if value is string convert to date
     * 
     * @return void
     */
    public function setExpiresAtAttribute($expiresAt)
    {
        // if expires at is null or falsy set to null
        if ($expiresAt === null || !$expiresAt) {
            $this->attributes['expires_at'] = null;
            return;
        }
        // if string and not explicit date assume relative time
        // relative options 'never', 'immediately', 'in 1 hour', 'in 24 hours', 'in 3 days', 'in 7 days'
        if (is_string($expiresAt) && !Utils::isDate($expiresAt)) {
            // if never then set to null
            if ($expiresAt === 'never') {
                $this->attributes['expires_at'] = null;
                return;
            }
            // if immediately then set to current date time
            if ($expiresAt === 'immediately') {
                $this->attributes['expires_at'] = Carbon::now()->toDatetime();
                return;
            }
            // parse relative time string to datetime
            $expiresAt = trim(str_replace('in', '', $expiresAt));
            $expiresAtTimestamp = strtotime('+ ' . $expiresAt);
            // convert timestamp to datetime
            $this->attributes['expires_at'] = Utils::toDatetime($expiresAtTimestamp);
            return;
        }
        $this->attributes['expires_at'] = Utils::toDatetime($expiresAt);
    }

    /**
     * Generate an API Key
     *
     * @return Array
     */
    public static function generateKeys($encode, $testKey = false)
    {
        $key = Hashids::encode($encode);
        $hash = Hash::make($key);
        return [
            'key' => ($testKey ? 'flb_test_' : 'flb_live_') . $key,
            'secret' => $hash,
        ];
    }

    /**
     * Apply the scope to a given Eloquent query builder and request.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function requestScope($query, $request)
    {
        $user = $request->user();
        $query->where('company_uuid', session('company') ?? data_get($user, 'company_uuid'));

        return $query;
    }
}
