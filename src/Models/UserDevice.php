<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

/**
 * A device a user's app registered for pushes. `platform` is the device's OS; `push_provider`
 * is the service that issued its token (`apn` or `fcm`, see PushProviders), which differs
 * from the platform's usual one when an iOS app gets its token from Firebase. Left empty, it's
 * worked out from the token and platform.
 */
class UserDevice extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'user_device';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'user_devices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['user_uuid', 'company_uuid', 'push_app_uuid', 'platform', 'push_provider', 'app_identifier', 'environment', 'token', 'status', 'last_seen_at'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * Dynamic attributes that are appended to object.
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
     * The push app the device registered with, if any.
     */
    public function pushApp()
    {
        return $this->belongsTo(PushApp::class, 'push_app_uuid', 'uuid');
    }

    /**
     * `ios` or `android` for the platform names apps send, or null.
     */
    public static function normalizePlatform(?string $platform): ?string
    {
        return match (strtolower(trim((string) $platform))) {
            'ios', 'iphone', 'ipad', 'ipados', 'apple', 'apn', 'apns' => 'ios',
            'android', 'fcm'                                          => 'android',
            default                                                   => null,
        };
    }

    /**
     * The tokens of the install-wide APNs or FCM channel: devices on the platform that
     * didn't register with a push app, which the PushAppChannel sends to instead.
     *
     * @param iterable<UserDevice> $devices
     */
    public static function installTokens(iterable $devices, string $platform): array
    {
        return collect($devices)
            ->filter(fn ($device) => $device->platform === $platform && empty($device->push_app_uuid))
            ->map(fn ($device) => $device->token)
            ->values()
            ->toArray();
    }
}
