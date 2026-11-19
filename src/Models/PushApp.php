<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushProviders;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Str;

/**
 * One of an organization's own mobile apps, with the settings of each push provider its pushes
 * are sent through. Any app can be one: a customer app, a driver app, or an app built through
 * AppHelm. The app registers devices with the push app's `key`, and the PushAppChannel then
 * sends to those devices with these settings.
 *
 * `providers` holds the settings by provider key (see PushProviders): `apn` for Apple
 * (`key_id`, `team_id`, `app_bundle_id`, `private_key_content`, `environment`) and `fcm` for
 * Firebase (`credentials`, `android_channel_id`). One push app covers an app on both
 * platforms, whatever its iOS bundle ID and Android package name. They're stored encrypted
 * and never serialized; `summaries` shows them without their secrets.
 */
class PushApp extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;

    public const KEY_PREFIX = 'push_app_';

    protected $publicIdType = 'push_app';

    protected $table = 'push_apps';

    protected $fillable = ['company_uuid', 'created_by_uuid', 'name', 'providers', 'status', 'owner_uuid', 'owner_type', 'meta'];

    protected $casts = [
        'providers' => 'encrypted:array',
        'meta'      => Json::class,
    ];

    protected $hidden = ['providers'];

    protected $appends = ['summaries'];

    protected $searchableColumns = ['name', 'key'];

    protected static function booted(): void
    {
        static::creating(function (PushApp $app) {
            $app->key ??= static::generateKey();
            $app->status ??= 'active';
        });

        // Editing an app without re-entering its secrets keeps the secrets it has.
        static::saving(function (PushApp $app) {
            if (!$app->exists || !$app->isDirty('providers')) {
                return;
            }
            $saved          = (array) $app->getOriginal('providers');
            $providers      = app(PushProviders::class);
            $app->providers = collect((array) $app->providers)
                ->map(fn ($config, $key) => static::keepSecrets($config, $saved[$key] ?? null, $providers->get($key)?->secrets() ?? []))
                ->filter(fn ($config) => $config !== null)
                ->all();
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
     * The saved settings of one provider, or null when it isn't set up.
     */
    public function provider(string $key): ?array
    {
        $config = ((array) $this->providers)[PushProviders::normalize($key)] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * The app with one provider's settings replaced, or removed when `$config` is null.
     */
    public function setProvider(string $key, ?array $config): static
    {
        $providers = (array) $this->providers;
        $key       = PushProviders::normalize($key);
        if ($config === null) {
            unset($providers[$key]);
        } else {
            $providers[$key] = $config;
        }
        $this->providers = $providers;

        return $this;
    }

    /**
     * The credentials for a provider, or null when it isn't set up or is missing its secrets.
     */
    public function credentials(string $key): ?PushCredentials
    {
        $key      = PushProviders::normalize($key);
        $provider = app(PushProviders::class)->get($key);
        $config   = $this->provider((string) $key);
        if (!$provider || !$config) {
            return null;
        }
        foreach ($provider->secrets() as $secret) {
            if (empty($config[$secret])) {
                return null;
            }
        }

        return new PushCredentials((string) ($this->public_id ?? $this->uuid), (string) $this->name, $key, $config);
    }

    /**
     * What the console shows of each provider's settings, by provider key: everything but
     * the secrets.
     */
    public function getSummariesAttribute(): array
    {
        $providers = app(PushProviders::class);
        $summaries = [];
        foreach ((array) $this->providers as $key => $config) {
            $provider = $providers->get($key);
            if ($provider && is_array($config)) {
                $summaries[$key] = $provider->summary($config);
            }
        }

        return $summaries;
    }
}
