<?php

namespace Fleetbase\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Permission\Traits\HasRoles;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\Searchable;
use Fleetbase\Models\Company;
use Fleetbase\Support\Utils;
use Illuminate\Support\Carbon;
use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasCacheableAttributes;
use Fleetbase\Traits\HasMetaAttributes;
use Illuminate\Database\QueryException;
use Laravel\Cashier\Billable;

class User extends Authenticatable
{
    use HasUuid,
        HasPublicId,
        Searchable,
        Notifiable,
        HasRoles,
        HasApiTokens,
        HasSlug,
        HasApiModelBehavior,
        HasCacheableAttributes,
        HasMetaAttributes,
        LogsActivity,
        CausesActivity,
        SoftDeletes,
        Billable;

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * Override the default primary key
     *
     * @var string
     */
    protected $primaryKey = 'uuid';

    /**
     * Primary key is non incrementing
     *
     * @var string
     */
    public $incrementing = false;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The type of public Id to generate
     *
     * @var string
     */
    protected $publicIdType = 'user';

    /**
     * The attributes that can be queried
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'email', 'phone'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid', // syncable
        'public_id', // syncable
        '_key',
        'company_uuid',
        'avatar_uuid',
        'username',
        'email',
        'password',
        'name',
        'phone',
        'date_of_birth',
        'timezone',
        'meta',
        'country',
        'ip_address',
        'last_login',
        'email_verified_at',
        'phone_verified_at',
        'slug',
        'type',
        'status',
        'created_at', // syncable
        'updated_at', // syncable
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = ['password', 'remember_token', 'secret', 'avatar', 'username', 'company', 'companies'];

    /**
     * Dynamic attributes that are appended to object
     *
     * @var array
     */
    protected $appends = [
        'avatar_url',
        'company_name',
        'is_admin',
        'types',
        // 'driver_uuid', 
        // 'session_status'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta' => Json::class,
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login' => 'datetime',
    ];

    /**
     * Properties which activity needs to be logged
     *
     * @var array
     */
    protected static $logAttributes = ['name', 'email', 'timezone', 'country', 'phone', 'status'];

    /**
     * Do not log empty changed
     *
     * @var boolean
     */
    protected static $submitEmptyLogs = false;

    /**
     * We only want to log changed attributes
     *
     * @var boolean
     */
    protected static $logOnlyDirty = true;

    /**
     * The name of the subject to log
     *
     * @var string
     */
    protected static $logName = 'user';

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
     * The company this user belongs to
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Set the company for this user
     */
    public function assignCompany(Company $company)
    {
        $this->company_uuid = $company->uuid;
        $this->save();
    }

    /**
     * Set the company for this user
     */
    public function assignCompanyFromId(?string $id)
    {
        if (!Utils::isUuid($id)) {
            return;
        }

        $this->company_uuid = $id;
        $this->save();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function avatar()
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function driver()
    {
        return $this->hasOne(Driver::class)
            ->without('user')
            ->withoutGlobalScopes();
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function currentDriverSession()
    {
        return $this->hasOne(Driver::class)
            ->where('company_uuid', session('company'))
            ->without('user')
            ->withoutGlobalScopes();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function driverProfiles()
    {
        return $this->hasMany(Driver::class)
            ->without('user')
            ->withoutGlobalScopes();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function customer()
    {
        return $this->hasOne(Contact::class)
            ->where('type', 'customer')
            ->without('user')
            ->withoutGlobalScopes();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function devices()
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function companies()
    {
        return $this->hasMany(CompanyUser::class, 'user_uuid');
    }

    /**
     * @return string
     */
    public function getSessionStatusAttribute()
    {
        if (!session('company')) {
            return 'pending';
        }

        $result = $this->companies()->where('company_uuid', session('company'))->first('status');

        return $result->status ?? 'pending';
    }

    /**
     * @return string
     */
    public function findSessionStatus()
    {
        $result = $this->companies()->where('company_uuid', session('company'))->first('status');
        $status = $result->status ?? 'pending';

        $this->setAttribute('session_status', $status);

        return $status;
    }

    /**
     * Specifies the user's FCM tokens
     *
     * @return string|array
     */
    public function routeNotificationForFcm()
    {
        return $this->devices->where('platform', 'android')->map(function ($userDevice) {
            return $userDevice->token;
        })->toArray();
    }

    /**
     * Specifies the user's APNS tokens
     *
     * @return string|array
     */
    public function routeNotificationForApn()
    {
        return $this->devices->where('platform', 'ios')->map(function ($userDevice) {
            return $userDevice->token;
        })->toArray();
    }

    /**
     * Get avatar URL attribute.
     *
     * @return string
     */
    public function getAvatarUrlAttribute()
    {
        if ($this->avatar instanceof File) {
            return $this->avatar->s3url;
        }

        return static::attributeFromCache($this, 'avatar.s3url', 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png');
    }

    /**
     * Get the users's company name.
     *
     * @return string
     */
    public function getCompanyNameAttribute()
    {
        return static::attributeFromCache($this, 'company.name', 'No company name');
    }

    /**
     * Get the users's company name.
     *
     * @return string
     */
    public function getDriverUuidAttribute()
    {
        return static::attributeFromCache($this, 'driver.uuid');
    }

    /**
     * Determines if the user is admin
     *
     * @return boolean
     */
    public function isAdmin()
    {
        return $this->type === 'admin';
    }

    public function getIsAdminAttribute()
    {
        return $this->isAdmin() || in_array($this->email, ['ron@fleetbase.io', 'shiv@fleetbase.io', 'evan@fleetbase.io']);
    }

    /**
     * Hash password
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    /**
     * Get the user timezone
     */
    public function getTimezone()
    {
        return static::attributeFromCache($this, 'timezone', 'Asia/Singapore');
    }

    /**
     * Updates the users last login
     */
    public function updateLastLogin()
    {
        $this->last_login = Carbon::now()->toDateTimeString();
        $this->save();

        return $this;
    }

    /**
     * Changes the users password
     */
    public function changePassword($newPassword)
    {
        // $this->password = bcrypt($newPassword); 
        // attribute is already hashed

        $this->password = $newPassword;
        $this->save();

        return $this;
    }

    /**
     * Changes the users password
     */
    public function deactivate()
    {
        $this->status = 'inactive';
        $this->save();

        return $this;
    }

    // from base model
    public static function isSearchable()
    {
        return class_uses_recursive(\Fleetbase\Traits\Searchable::class) || (property_exists(new static, 'searchable') && static::$searchable);
    }

    public function searchable()
    {
        return static::isSearchable();
    }

    public function routeNotificationForTwilio()
    {
        return $this->phone;
    }

    public function getTypesAttribute()
    {
        $driver = false;
        $customer = false;

        try {
            $driver = $this->driver()->exists();
        } catch (QueryException $e) {
            // keep silent
        }

        try {
            $customer = $this->customer()->exists();
        } catch (QueryException $e) {
            // keep silent
        }

        $types = [$this->type];

        if ($driver) {
            $types[] = 'driver';
        }

        if ($customer) {
            $types[] = 'customer';
        }

        return collect($types)->unique()->values()->toArray();
    }
}
