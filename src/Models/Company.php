<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Notifications\Notifiable;
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
        'address_uuid',
        'website_url',
        'description',
        'options',
        'owner_uuid',
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
        'options' => Json::class,
    ];

    /**
     * The attributes that should be cast to dates.
     *
     * @var array
     */
    protected $dates = ['trial_ends_at'];

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

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_users');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function logo()
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function backdrop()
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function drivers()
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function apiCredentials()
    {
        return $this->hasMany(ApiCredential::class);
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
     * @return \Fleetbase\Models\Company
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
     * @return \Fleetbase\Models\Company
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
     * @return \Fleetbase\Models\Company|null
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
}
