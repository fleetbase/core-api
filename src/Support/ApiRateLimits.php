<?php

namespace Fleetbase\Support;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Effective API rate-limit settings and the identity of the consumer behind a credential.
 *
 * The environment (config/api.php) supplies the defaults; a system administrator can
 * override them from the console, which stores a single `system.rate-limits` setting.
 * Per-organization overrides raise, lower or lift the limit for one tenant's consumers.
 */
class ApiRateLimits
{
    public const SETTING_KEY = 'rate-limits';

    public const SETTINGS_CACHE_KEY = 'api_rate_limits:settings';

    public const CONSUMER_CACHE_PREFIX = 'api_rate_limits:consumer:';

    /**
     * How long a credential's resolved identity is cached, in seconds.
     */
    public const CONSUMER_CACHE_TTL = 600;

    /**
     * Settings from the environment alone, before any administrator override.
     */
    public static function defaults(): array
    {
        return [
            'enabled'         => config('api.throttle.enabled', true) !== false,
            'max_attempts'    => (int) config('api.throttle.max_attempts', 120),
            'decay_minutes'   => (int) config('api.throttle.decay_minutes', 1),
            'track_consumers' => config('api.throttle.track_consumers', true) !== false,
            'overrides'       => [],
        ];
    }

    /**
     * The effective settings: the environment defaults with the stored override applied.
     *
     * Read on every throttled request, so it is cached; saving through store() clears it.
     * A missing database or cache (unit tests, a broken install) falls back to the defaults
     * rather than failing the request.
     */
    public static function settings(): array
    {
        try {
            $stored = Cache::remember(static::SETTINGS_CACHE_KEY, 60, function () {
                return Setting::where('key', 'system.' . static::SETTING_KEY)->value('value') ?? [];
            });
        } catch (\Throwable $e) {
            $stored = [];
        }

        return static::normalize(array_merge(static::defaults(), is_array($stored) ? $stored : []));
    }

    /**
     * Persist administrator settings and make them effective immediately.
     */
    public static function store(array $settings): array
    {
        $settings = static::normalize(array_merge(static::defaults(), $settings));

        Setting::configureSystem(static::SETTING_KEY, $settings);
        Cache::forget(static::SETTINGS_CACHE_KEY);

        return $settings;
    }

    /**
     * Drop the stored override so the environment defaults apply again.
     */
    public static function reset(): array
    {
        Setting::where('key', 'system.' . static::SETTING_KEY)->delete();
        Cache::forget(static::SETTINGS_CACHE_KEY);

        return static::settings();
    }

    /**
     * Coerce settings into their canonical shape.
     */
    public static function normalize(array $settings): array
    {
        $overrides = [];
        foreach ((array) ($settings['overrides'] ?? []) as $override) {
            $companyUuid = data_get($override, 'company_uuid');
            if (!is_string($companyUuid) || $companyUuid === '') {
                continue;
            }

            $maxAttempts             = data_get($override, 'max_attempts');
            $overrides[$companyUuid] = [
                'company_uuid' => $companyUuid,
                'unlimited'    => (bool) data_get($override, 'unlimited', false),
                'max_attempts' => is_numeric($maxAttempts) ? max(1, (int) $maxAttempts) : null,
                'note'         => (string) data_get($override, 'note', ''),
            ];
        }

        return [
            'enabled'         => (bool) ($settings['enabled'] ?? true),
            'max_attempts'    => max(1, (int) ($settings['max_attempts'] ?? 120)),
            'decay_minutes'   => max(1, (int) ($settings['decay_minutes'] ?? 1)),
            'track_consumers' => (bool) ($settings['track_consumers'] ?? true),
            'overrides'       => array_values($overrides),
        ];
    }

    /**
     * The limit that applies to a consumer, or null when it is unlimited.
     */
    public static function limitFor(?string $companyUuid, ?array $settings = null): ?int
    {
        $settings ??= static::settings();

        if ($companyUuid) {
            foreach ($settings['overrides'] as $override) {
                if ($override['company_uuid'] !== $companyUuid) {
                    continue;
                }

                if ($override['unlimited']) {
                    return null;
                }

                return $override['max_attempts'] ?? $settings['max_attempts'];
            }
        }

        return $settings['max_attempts'];
    }

    /**
     * Identify who a credential belongs to, cached so the lookup is at most one query per
     * credential per cache period. Unknown credentials are cached too, so a caller sending
     * a bad key does not reach the database more often than one sending a good one.
     *
     * @param string $credential the raw credential as extracted by the throttle middleware
     */
    public static function identify(string $credential): array
    {
        try {
            return Cache::remember(static::CONSUMER_CACHE_PREFIX . sha1($credential), static::CONSUMER_CACHE_TTL, function () use ($credential) {
                return static::lookupConsumer($credential);
            });
        } catch (\Throwable $e) {
            return static::unknownConsumer($credential);
        }
    }

    /**
     * Resolve a credential to its owning organization without authenticating it.
     */
    protected static function lookupConsumer(string $credential): array
    {
        $token = trim(Str::startsWith($credential, 'Bearer ') ? Str::after($credential, 'Bearer ') : $credential);

        // Sanctum personal access tokens are "<id>|<plaintext>".
        if (Str::contains($token, '|')) {
            $accessToken = PersonalAccessToken::findToken($token);
            $user        = $accessToken?->tokenable;
            if ($user) {
                return static::describe('token', $user->name ?? $user->email ?? 'Access token', $user->company_uuid ?? null, null, $accessToken->name ?? null);
            }

            return static::unknownConsumer($credential);
        }

        // Secret keys ("$...") carry no mode prefix, so a live miss is retried on sandbox,
        // the same as AuthenticateOnceWithBasicAuth does.
        $connections = Str::startsWith($token, 'flb_test_') ? ['sandbox'] : (Str::startsWith($token, '$') ? ['mysql', 'sandbox'] : ['mysql']);
        $apiKey      = null;
        foreach ($connections as $connection) {
            $apiKey = ApiCredential::on($connection)
                ->withoutGlobalScopes()
                ->where(function ($query) use ($token) {
                    $query->where('key', $token)->orWhere('secret', $token);
                })
                ->first(['uuid', 'name', 'key', 'company_uuid', 'test_mode']);

            if ($apiKey) {
                break;
            }
        }

        if ($apiKey) {
            return static::describe('api_key', $apiKey->name ?: 'API key', $apiKey->company_uuid, $apiKey->uuid, static::mask($apiKey->key), (bool) $apiKey->test_mode);
        }

        return static::unknownConsumer($credential);
    }

    protected static function describe(string $type, string $label, ?string $companyUuid, ?string $credentialUuid = null, ?string $detail = null, bool $testMode = false): array
    {
        $company = $companyUuid ? Company::where('uuid', $companyUuid)->first(['uuid', 'public_id', 'name']) : null;

        return [
            'type'            => $type,
            'label'           => $label,
            'detail'          => $detail,
            'test_mode'       => $testMode,
            'credential_uuid' => $credentialUuid,
            'company_uuid'    => $company?->uuid ?? $companyUuid,
            'company_id'      => $company?->public_id,
            'company_name'    => $company?->name,
        ];
    }

    protected static function unknownConsumer(string $credential): array
    {
        $token = trim(Str::after($credential, ' '));

        return [
            'type'            => 'unknown',
            'label'           => 'Unrecognized credential',
            'detail'          => static::mask($token ?: $credential),
            'test_mode'       => false,
            'credential_uuid' => null,
            'company_uuid'    => null,
            'company_id'      => null,
            'company_name'    => null,
        ];
    }

    /**
     * Enough of a key to recognize it, never enough to use it.
     */
    public static function mask(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        return mb_substr($value, 0, min(12, (int) floor(mb_strlen($value) / 2))) . '…';
    }
}
