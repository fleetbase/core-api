<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Notifications\UserInvited;
use Fleetbase\Support\Utils;
use Fleetbase\Traits\Expandable;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasCacheableAttributes;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class User extends Authenticatable
{
    use HasUuid;
    use HasPublicId;
    use Searchable;
    use Notifiable;
    use HasRoles;
    use HasApiTokens;
    use HasSlug;
    use HasApiModelBehavior;
    use HasCacheableAttributes;
    use HasMetaAttributes;
    use HasTimestamps;
    use LogsActivity;
    use CausesActivity;
    use SoftDeletes;
    use Expandable;
    use Filterable;

    /**
     * The database connection to use.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * Override the default primary key.
     *
     * @var string
     */
    protected $primaryKey = 'uuid';

    /**
     * Primary key is non incrementing.
     *
     * @var string
     */
    public $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'user';

    /**
     * The attributes that can be queried.
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
        'uuid',
        'public_id',
        '_key',
        'company_uuid',
        'avatar_uuid',
        'username',
        'email',
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
        'type',
        'slug',
        'status',
    ];

    /**
     * Attributes which are not mass assignable.
     *
     * @var array
     */
    protected $guarded = ['password'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = ['password', 'remember_token', 'secret', 'avatar', 'username', 'company', 'companies'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'avatar_url',
        'session_status',
        'company_name',
        'is_admin',
        'types',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'              => Json::class,
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login'        => 'datetime',
    ];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = ['name', 'email', 'timezone', 'country', 'phone', 'status'];

    /**
     * Do not log empty changed.
     *
     * @var bool
     */
    protected static $submitEmptyLogs = false;

    /**
     * We only want to log changed attributes.
     *
     * @var bool
     */
    protected static $logOnlyDirty = true;

    /**
     * The name of the subject to log.
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
     * The company this user belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Set the company for this user.
     */
    public function assignCompany(Company $company)
    {
        $this->company_uuid = $company->uuid;
        $this->save();
    }

    /**
     * Set the company for this user.
     */
    public function assignCompanyFromId(?string $id)
    {
        if (!Str::isUuid($id)) {
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

        return data_get($result, 'status', 'pending');
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
     * Specifies the user's FCM tokens.
     *
     * @return string|array
     */
    public function routeNotificationForFcm()
    {
        return $this->devices->where('platform', 'android')->map(
            function ($userDevice) {
                return $userDevice->token;
            }
        )->toArray();
    }

    /**
     * Specifies the user's APNS tokens.
     *
     * @return string|array
     */
    public function routeNotificationForApn()
    {
        return $this->devices->where('platform', 'ios')->map(
            function ($userDevice) {
                return $userDevice->token;
            }
        )->toArray();
    }

    /**
     * Get avatar URL attribute.
     *
     * @return string
     */
    public function getAvatarUrlAttribute()
    {
        if ($this->avatar instanceof File) {
            return $this->avatar->url;
        }

        return data_get($this, 'avatar.url', 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png');
    }

    /**
     * Get the users's company name.
     *
     * @return string
     */
    public function getCompanyNameAttribute()
    {
        return data_get($this, 'company.name');
    }

    /**
     * Get the users's company name.
     *
     * @return string
     */
    public function getDriverUuidAttribute()
    {
        return data_get($this, 'driver.uuid');
    }

    /**
     * Checks if the user is admin.
     *
     * @return bool
     */
    public function isAdmin()
    {
        return $this->type === 'admin';
    }

    /**
     * Checks if the user is NOT admin.
     *
     * @return bool
     */
    public function isNotAdmin()
    {
        return $this->type !== 'admin';
    }

    /**
     * Adds a boolean dynamic property to check if user is an admin.
     *
     * @return void
     */
    public function getIsAdminAttribute()
    {
        return $this->isAdmin();
    }

    /**
     * Set and hash password.
     *
     * @return void
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    /**
     * Set the default status to `active`.
     *
     * @return void
     */
    public function setStatusAttribute($value = 'active')
    {
        $this->attributes['status'] = $value ?? 'active';
    }

    /**
     * Get the user timezone.
     */
    public function getTimezone(): string
    {
        return data_get($this, 'timezone', 'Asia/Singapore');
    }

    /**
     * Updates the users last login.
     */
    public function updateLastLogin(): User
    {
        $this->last_login = Carbon::now()->toDateTimeString();
        $this->save();

        return $this;
    }

    /**
     * Changes the users password.
     */
    public function changePassword($newPassword): User
    {
        $this->password = $newPassword;
        $this->save();

        return $this;
    }

    /**
     * Deactivate this user.
     */
    public function deactivate()
    {
        $this->status = 'inactive';
        $this->save();

        return $this;
    }

    /**
     * Activate this user.
     */
    public function activate()
    {
        $this->status = 'active';
        $this->save();

        return $this;
    }

    /**
     * Determines if the model is searchable.
     *
     * @return bool true if the class uses the Searchable trait or the 'searchable' property exists and is true, false otherwise
     */
    public static function isSearchable()
    {
        return class_uses_recursive(\Fleetbase\Traits\Searchable::class) || (property_exists(new static(), 'searchable') && static::$searchable);
    }

    /**
     * Accessor to check if the model instance is searchable.
     *
     * @return bool true if the model instance is searchable, false otherwise
     */
    public function searchable()
    {
        return static::isSearchable();
    }

    /**
     * Get the phone number to which the notification should be routed.
     *
     * @return string the phone number of the model instance
     */
    public function routeNotificationForTwilio()
    {
        return $this->phone;
    }

    /**
     * The channels the user receives notification broadcasts on.
     *
     * @return string
     */
    public function receivesBroadcastNotificationsOn()
    {
        return 'user.' . $this->uuid;
    }

    /**
     * Accessor to get the types associated with the model instance.
     *
     * @return array an array of types associated with the model instance
     */
    public function getTypesAttribute()
    {
        $driver   = false;
        $customer = false;

        // // if (method_exists($this, 'driver')) {
        // try {
        //     $driver = $this->driver()->exists();
        // } catch (QueryException $e) {
        //     // keep silent
        // }
        // // }

        // if (method_exists($this, 'customer')) {
        //     try {
        //         $customer = $this->customer()->exists();
        //     } catch (QueryException $e) {
        //         // keep silent
        //     }
        // }

        $types = [$this->type];

        if ($driver) {
            $types[] = 'driver';
        }

        if ($customer) {
            $types[] = 'customer';
        }

        return collect($types)->unique()->values()->toArray();
    }

    /**
     * Sends an invitation from a company to join.
     *
     * This function checks if the user is already invited to the company.
     * If not, it creates a new invitation and notifies the user.
     *
     * @param Company $company the company from which the invitation is being sent
     *
     * @return bool returns true if the invitation is successfully sent, false otherwise
     */
    public function sendInviteFromCompany(Company $company = null): bool
    {
        if ($company === null) {
            $this->load(['company']);
            $company = $this->company;
        }

        // make sure company is valid
        if (!$company instanceof Company) {
            return false;
        }

        // make sure user isn't already invited
        $isAlreadyInvited = Invite::where([
            'company_uuid' => $company->uuid,
            'subject_uuid' => $company->uuid,
            'protocol'     => 'email',
            'reason'       => 'join_company',
        ])->whereJsonContains('recipients', $this->email)->exists();

        if ($isAlreadyInvited) {
            return false;
        }

        // create invitation
        $invitation = Invite::create([
            'company_uuid'    => $company->uuid,
            'created_by_uuid' => $this->uuid,
            'subject_uuid'    => $company->uuid,
            'subject_type'    => Utils::getMutationType($company),
            'protocol'        => 'email',
            'recipients'      => [$this->email],
            'reason'          => 'join_company',
        ]);

        // notify user
        $this->notify(new UserInvited($invitation));

        return true;
    }
}
