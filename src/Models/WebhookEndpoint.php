<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Spatie\Activitylog\Traits\LogsActivity;

class WebhookEndpoint extends Model
{
    use HasUuid;
    use HasApiModelBehavior;
    use LogsActivity;
    use Searchable;
    use Filterable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'webhook_endpoints';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['url', 'description'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['_key', 'company_uuid', 'created_by_uuid', 'updated_by_uuid', 'api_credential_uuid', 'url', 'mode', 'version', 'description', 'events', 'status'];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [
        'events' => 'array',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['is_listening_on_all_events', 'api_credential_name'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['apiCredential'];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = '*';

    /**
     * Do not log empty changed.
     *
     * @var bool
     */
    protected static $submitEmptyLogs = false;

    /**
     * The name of the subject to log.
     *
     * @var string
     */
    protected static $logName = 'webhook_endpoint';

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function apiCredential()
    {
        return $this->belongsTo(ApiCredential::class);
    }

    /**
     * Determines if webhook is listening to all events.
     */
    public function getIsListeningOnAllEventsAttribute(): bool
    {
        return count(config('api.events')) === count($this->events ?? []) || empty($this->events);
    }

    /**
     * Get the api credential name or key.
     *
     * @return string
     */
    public function getApiCredentialNameAttribute()
    {
        if (isset($this->apiCredential->name)) {
            return static::attributeFromCache($this, 'apiCredential.name', function () {
                return $this->apiCredential->name . ' (' . $this->apiCredential->key . ')';
            });
        }

        return static::attributeFromCache($this, 'apiCredential.key');
    }
}
