<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Str;

/**
 * One of an organization's own mobile apps, with the APNs (iOS) and FCM (Android)
 * credentials its pushes are sent with. Any app can be one: a customer app, a driver app, or
 * an app built through AppHelm. The app registers devices with the push app's `key`, and the
 * PushAppChannel then sends to those devices with these credentials.
 *
 * `apn`: `key_id`, `team_id`, `app_bundle_id`, `private_key_content` (the .p8 key) and
 * optionally `environment`. `fcm`: `credentials` (the Firebase service account JSON) and
 * optionally `android_channel_id`. Both are stored encrypted and never serialized.
 */
class PushApp extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;

    public const KEY_PREFIX = 'push_app_';

    protected $publicIdType = 'push_app';

    protected $table = 'push_apps';

    protected $fillable = ['company_uuid', 'created_by_uuid', 'name', 'apn', 'fcm', 'status', 'owner_uuid', 'owner_type', 'meta'];

    protected $casts = [
        'apn'  => 'encrypted:array',
        'fcm'  => 'encrypted:array',
        'meta' => Json::class,
    ];

    protected $hidden = ['apn', 'fcm'];

    protected $appends = ['apn_summary', 'fcm_summary'];

    protected $searchableColumns = ['name', 'key'];

    protected static function booted(): void
    {
        static::creating(function (PushApp $app) {
            $app->key ??= static::generateKey();
            $app->status ??= 'active';
        });

        // Editing an app without re-entering its keys keeps the keys it has.
        static::saving(function (PushApp $app) {
            if ($app->exists && $app->isDirty('apn')) {
                $app->apn = static::keepSecrets($app->apn, $app->getOriginal('apn'), ['private_key_content']);
            }
            if ($app->exists && $app->isDirty('fcm')) {
                $app->fcm = static::keepSecrets($app->fcm, $app->getOriginal('fcm'), ['credentials']);
            }
        });
    }

    public static function generateKey(): string
    {
        return static::KEY_PREFIX . Str::lower(Str::random(32));
    }

    /**
     * The new settings, with each secret left empty taken from the old ones.
     */
    public static function keepSecrets(?array $new, ?array $old, array $secrets): ?array
    {
        if ($new === null) {
            return null;
        }
        foreach ($secrets as $secret) {
            if (empty($new[$secret]) && !empty($old[$secret])) {
                $new[$secret] = $old[$secret];
            }
        }

        return $new;
    }

    /**
     * The active push app with this key, in the company when one is given.
     */
    public static function findByKey(?string $key, ?string $companyUuid = null): ?self
    {
        $key = trim((string) $key);
        if ($key === '' || !str_starts_with($key, static::KEY_PREFIX)) {
            return null;
        }

        return static::where('key', $key)
            ->where('status', 'active')
            ->when($companyUuid, fn ($query) => $query->where('company_uuid', $companyUuid))
            ->first();
    }

    /**
     * The attributes a device registering with this app gets.
     */
    public function deviceAttributes(): array
    {
        return ['push_app_uuid' => $this->uuid, 'company_uuid' => $this->company_uuid, 'app_identifier' => $this->uuid];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function devices()
    {
        return $this->hasMany(UserDevice::class, 'push_app_uuid', 'uuid');
    }

    /**
     * The credentials for `apn` or `fcm`, or null when that platform isn't set up.
     */
    public function credentials(string $scheme): ?PushCredentials
    {
        $config = $scheme === 'apn' ? $this->apn : ($scheme === 'fcm' ? $this->fcm : null);
        $secret = $scheme === 'apn' ? 'private_key_content' : 'credentials';
        if (!is_array($config) || empty($config[$secret])) {
            return null;
        }

        return new PushCredentials((string) ($this->public_id ?? $this->uuid), (string) $this->name, $scheme, $config);
    }

    /**
     * What the console shows of the APNs settings: everything but the key.
     */
    public function getApnSummaryAttribute(): ?array
    {
        $apn = $this->apn;
        if (!is_array($apn)) {
            return null;
        }

        return [
            'key_id'        => $apn['key_id'] ?? null,
            'team_id'       => $apn['team_id'] ?? null,
            'app_bundle_id' => $apn['app_bundle_id'] ?? null,
            'environment'   => $apn['environment'] ?? null,
            'has_key'       => !empty($apn['private_key_content']),
        ];
    }

    /**
     * What the console shows of the FCM settings: the service account's project and email.
     */
    public function getFcmSummaryAttribute(): ?array
    {
        $fcm = $this->fcm;
        if (!is_array($fcm)) {
            return null;
        }
        $account = $fcm['credentials'] ?? null;
        $account = is_string($account) ? json_decode($account, true) : $account;

        return [
            'project_id'         => data_get($account, 'project_id'),
            'client_email'       => data_get($account, 'client_email'),
            'android_channel_id' => $fcm['android_channel_id'] ?? null,
            'has_credentials'    => !empty($fcm['credentials']),
        ];
    }
}
