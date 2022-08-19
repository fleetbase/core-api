<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasOptionsAttributes;
// use Spatie\Activitylog\Traits\CausesActivity;
// use Spatie\Activitylog\Traits\LogsActivity;
// use Spatie\Sluggable\SlugOptions;
// use Spatie\Sluggable\HasSlug;
use Fleetbase\Traits\Searchable;
// use GeneaLabs\LaravelModelCaching\Traits\Cachable;
// use Laravel\Cashier\Billable;

class Company extends Model
{
    use HasUuid, HasPublicId, TracksApiCredential, HasApiModelBehavior, HasOptionsAttributes, Searchable;

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
     * The type of public Id to generate
     *
     * @var string
     */
    protected $publicIdType = 'company';

    /**
     * These attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = [];

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
        'created_at', // syncable
        'updated_at', // syncable
    ];

    /**
     * Dynamic attributes that are appended to object
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
        'options' => Json::class
    ];

    /**
     * The attributes that should be cast to dates.
     *
     * @var array
     */
    protected $dates = ['trial_ends_at'];

    /**
     * Properties which activity needs to be logged
     *
     * @var array
     */
    protected static $logAttributes = '*';

    /**
     * Do not log empty changed
     *
     * @var boolean
     */
    protected static $submitEmptyLogs = false;

    /**
     * The name of the subject to log
     *
     * @var string
     */
    protected static $logName = 'company';

    // /**
    //  * Get the options for generating the slug.
    //  */
    // public function getSlugOptions(): SlugOptions
    // {
    //     return SlugOptions::create()
    //         ->generateSlugsFrom('name')
    //         ->saveSlugsTo('slug');
    // }

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
     * @return \Fleetbase\Models\Company
     */
    public function setStatus($status = 'active')
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Sets the owner of the company
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
        return $this->logo->s3url ?? 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png';
    }

    /**
     * @return string
     */
    public function getBackdropUrlAttribute()
    {
        return $this->backdrop->s3url ?? 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png';
    }

    /**
     * Determines if the currently authenticated user is the owner of the company.
     *
     * @return boolean
     */
    public function getIsOwnerAttribute()
    {
        return auth()->check() && auth()->user()->uuid === $this->owner_uuid;
    }

    /**
     * Determines if the user passed is the owner of the company.
     *
     * @method isOwner
     * @return boolean
     */
    public function isOwner(User $user)
    {
        return $user->uuid === $this->owner_uuid;
    }

    public function routeNotificationForTwilio()
    {
        return $this->phone;
    }

    public static function currentSession()
    {
        $id = session('company');

        if ($id) {
            return static::where('uuid', $id)->first();
        }

        return null;
    }

    public function addUser(?User $user)
    {
        return CompanyUser::create([
            'company_uuid' => $this->uuid,
            'user_uuid' => $user->uuid,
            'status' => 'active'
        ]);
    }
}
