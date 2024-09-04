<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Company extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasOptionsAttributes;
    use HasSlug;
    use Searchable;
    use SendsWebhooks;
    use Notifiable;

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'companies';

    /**
     * The HTTP resource to use for responses.
     *
     * @var string
     */
    public $resource = \Fleetbase\Http\Resources\Organization::class;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'company';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'description'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid', // syncable
        'public_id', // syncable
        'stripe_customer_id',
        'stripe_connect_id',
        'name',
        'owner_uuid',
        'logo_uuid',
        'backdrop_uuid',
        'place_uuid',
        'website_url',
        'description',
        'options',
        'type',
        'currency',
        'country',
        'phone',
        'timezone',
        'plan',
        'status',
        'slug',
        'trial_ends_at',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['logo_url', 'backdrop_url'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'options'       => Json::class,
        'trial_ends_at' => 'datetime',
    ];

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
    protected static $logName = 'company';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class)->whereHas('anyCompanyUser', function ($query) {
            $query->where('company_uuid', $this->uuid);
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_users');
    }

    public function companyUsers(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, CompanyUser::class, 'company_uuid', 'uuid', 'uuid', 'user_uuid');
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function backdrop(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function loadCompanyOwner(): self
    {
        $this->loadMissing(['owner', 'creator']);
        $owner = $this->owner ?? $this->creator;

        if ($owner) {
            $this->setRelation('owner', $owner);

            return $this;
        }

        if (Str::isUuid($this->owner_uuid)) {
            $owner = User::where('uuid', $this->owner_uuid)->first();
            if ($owner) {
                $this->setRelation('owner', $owner);

                return $this;
            }
        }

        return $this;
    }

    /**
     * Assigns the owner of the company.
     *
     * @method assignOwner
     *
     * @return void
     */
    public function assignOwner(User $user)
    {
        $this->owner_uuid = $user->uuid;
        $this->save();
    }

    /**
     * Set the owner of the company.
     *
     * @method setOwner
     *
     * @return Company
     */
    public function setOwner(User $user)
    {
        $this->owner_uuid = $user->uuid;

        return $this;
    }

    /**
     * Set the status of the company.
     *
     * @method setStatus
     *
     * @return Company
     */
    public function setStatus($status = 'active')
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Sets the owner of the company.
     */
    public function activate()
    {
        return $this->setStatus('active');
    }

    /**
     * @return string
     */
    public function getLogoUrlAttribute()
    {
        return $this->logo->url ?? 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png';
    }

    /**
     * @return string
     */
    public function getBackdropUrlAttribute()
    {
        return $this->backdrop->url ?? 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png';
    }

    /**
     * Determines if the currently authenticated user is the owner of the company.
     *
     * @return bool
     */
    public function getIsOwnerAttribute()
    {
        return auth()->check() && auth()->user()->uuid === $this->owner_uuid;
    }

    /**
     * Checks if a user is the owner of this company.
     *
     * @method isOwner
     *
     * @param \Fleetbae\Models\User
     *
     * @return bool
     */
    public function isOwner(User $user)
    {
        return $user->uuid === $this->owner_uuid;
    }

    /**
     * Company field that should be used for Twilio notifications.
     *
     * @return string
     */
    public function routeNotificationForTwilio()
    {
        return $this->phone;
    }

    /**
     * Uses the current session to get the current company model instance.
     *
     * @return Company|null
     */
    public static function currentSession()
    {
        $id = session('company');

        if ($id) {
            return static::where('uuid', $id)->first();
        }

        return null;
    }

    /**
     * Adds a new user to this company.
     *
     * @return void
     */
    public function addUser(?User $user)
    {
        return CompanyUser::create([
            'company_uuid' => $this->uuid,
            'user_uuid'    => $user->uuid,
            'status'       => 'active',
        ]);
    }

    /**
     * Get the latest last login of any user in the company.
     */
    public function getLastUserLogin()
    {
        return $this->companyUsers()->max('last_login');
    }
}
