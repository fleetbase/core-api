<?php

namespace Fleetbase\Support\SocketCluster;

use Fleetbase\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Effective socket server settings: the administrator's stored values over the environment.
 *
 * Each field is its own `system.socket.*` setting; a missing (or cleared) one falls back to the
 * environment, which is how the socket server behaved before these settings existed. The auth
 * key is never stored here: it stays in SOCKETCLUSTER_AUTH_KEY, and only whether it is set is
 * reported.
 */
class SocketSettings
{
    public const SETTING_PREFIX = 'system.socket.';

    public const FIELDS = ['enabled', 'mode', 'token_ttl', 'origins', 'publisher_origin'];

    public const MODES = ['off', 'log', 'enforce'];

    public const MIN_TOKEN_TTL = 60;

    /**
     * When the stored settings last changed; written on every save.
     */
    public const UPDATED_AT = 'updated_at';

    public const CACHE_KEY = 'socket_settings:stored';

    /**
     * How long the stored settings are cached, in seconds. Saving clears the cache at once.
     */
    public const CACHE_TTL = 60;

    /**
     * The values from the environment alone.
     *
     * `mode` and `origins` are null when the API's environment does not set them: the socket
     * server then uses its own environment and defaults.
     */
    public static function defaults(): array
    {
        $ttl     = (int) config('broadcasting.connections.socketcluster.token_ttl', SocketToken::DEFAULT_TTL);
        $options = config('broadcasting.connections.socketcluster.socket_options');
        $options = is_string($options) ? json_decode($options, true) : $options;

        return [
            'enabled'          => filter_var(config('broadcasting.connections.socketcluster.auth_enabled', false), FILTER_VALIDATE_BOOLEAN),
            'mode'             => static::normalizeMode(config('broadcasting.connections.socketcluster.auth_mode')),
            'token_ttl'        => $ttl > 0 ? $ttl : SocketToken::DEFAULT_TTL,
            'origins'          => static::normalizeString(is_array($options) ? ($options['origins'] ?? null) : null),
            'publisher_origin' => static::normalizeString(config('broadcasting.connections.socketcluster.options.headers.Origin')),
        ];
    }

    /**
     * The stored settings, keyed by field, without the `system.socket.` prefix.
     *
     * Read on every broadcast and token, so it is cached; store() clears it. An unreachable
     * settings table or cache (an install in progress, unit tests) reads as nothing stored.
     */
    public static function stored(): array
    {
        try {
            $rows = Cache::remember(static::CACHE_KEY, static::CACHE_TTL, function () {
                return Setting::where('key', 'like', static::SETTING_PREFIX . '%')->get(['key', 'value'])->pluck('value', 'key')->all();
            });
        } catch (\Throwable $e) {
            $rows = [];
        }

        $stored = [];
        foreach ((array) $rows as $key => $value) {
            $stored[substr((string) $key, strlen(static::SETTING_PREFIX))] = $value;
        }

        return $stored;
    }

    /**
     * The effective settings, where each came from, and whether the auth key is configured.
     *
     * @return array{enabled: bool, mode: ?string, token_ttl: int, origins: ?string, publisher_origin: ?string, key_configured: bool, sources: array<string, string>}
     */
    public static function effective(): array
    {
        $defaults  = static::defaults();
        $stored    = static::normalize(static::stored());
        $effective = [];
        $sources   = [];

        foreach (static::FIELDS as $field) {
            $fromSetting       = $stored[$field] !== null;
            $effective[$field] = $fromSetting ? $stored[$field] : $defaults[$field];
            $sources[$field]   = $fromSetting ? 'setting' : 'env';
        }

        $effective['key_configured'] = SocketToken::key() !== null;
        $effective['sources']        = $sources;

        return $effective;
    }

    /**
     * When the stored settings last changed, or null if they never have.
     */
    public static function updatedAt(): ?string
    {
        return static::normalizeString(static::stored()[static::UPDATED_AT] ?? null);
    }

    /**
     * Apply a change to some fields; a null value clears the field back to the environment.
     *
     * Fields not in the change are left as they are. Returns the stored values before and
     * after the change, normalized, for the activity log.
     *
     * @return array{before: array, after: array}
     */
    public static function store(array $changes): array
    {
        $before  = static::normalize(static::stored());
        $changes = array_intersect_key(static::normalize($changes), array_flip(array_intersect(static::FIELDS, array_keys($changes))));

        foreach ($changes as $field => $value) {
            if ($value === null) {
                Setting::where('key', static::SETTING_PREFIX . $field)->delete();
            } else {
                Setting::configure(static::SETTING_PREFIX . $field, $value);
            }
        }

        Setting::configure(static::SETTING_PREFIX . static::UPDATED_AT, Carbon::now()->toIso8601ZuluString());
        static::forget();

        return ['before' => $before, 'after' => array_merge($before, $changes)];
    }

    /**
     * Drop the cached stored settings so the next read sees the database.
     */
    public static function forget(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    /**
     * Coerce stored or submitted values to their canonical types; anything invalid becomes null.
     */
    public static function normalize(array $values): array
    {
        $ttl = $values['token_ttl'] ?? null;

        return [
            'enabled'          => is_null($values['enabled'] ?? null) ? null : filter_var($values['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'mode'             => static::normalizeMode($values['mode'] ?? null),
            'token_ttl'        => is_numeric($ttl) && (int) $ttl >= self::MIN_TOKEN_TTL && (int) $ttl <= SocketToken::MAX_TTL ? (int) $ttl : null,
            'origins'          => static::normalizeString($values['origins'] ?? null),
            'publisher_origin' => static::normalizeOrigin($values['publisher_origin'] ?? null),
        ];
    }

    /**
     * Validation rules for a save. Every field is optional and nullable (null = use the environment).
     */
    public static function rules(): array
    {
        return [
            'enabled'          => ['sometimes', 'nullable', 'boolean'],
            'mode'             => ['sometimes', 'nullable', 'string', 'in:' . implode(',', static::MODES)],
            'token_ttl'        => ['sometimes', 'nullable', 'integer', 'min:' . self::MIN_TOKEN_TTL, 'max:' . SocketToken::MAX_TTL],
            // SocketCluster's `origins` option: one or more host:port patterns separated by spaces.
            'origins'          => ['sometimes', 'nullable', 'string', 'max:1024', 'regex:/^[\x21-\x7E]+( [\x21-\x7E]+)*$/'],
            // An origin only: scheme, host and optional port, without a path.
            'publisher_origin' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^https?:\/\/[^\/\s?#]+\/?$/i'],
        ];
    }

    protected static function normalizeMode($mode): ?string
    {
        $mode = is_string($mode) ? strtolower(trim($mode)) : null;

        return in_array($mode, static::MODES, true) ? $mode : null;
    }

    protected static function normalizeString($value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    protected static function normalizeOrigin($value): ?string
    {
        $value = static::normalizeString($value);

        return $value === null ? null : rtrim($value, '/');
    }
}
