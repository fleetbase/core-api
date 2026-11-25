<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformApi
{
    public const TOKEN_HASH_KEY       = 'platform_api.token_hash';
    public const TOKEN_ROTATED_AT_KEY = 'platform_api.token_rotated_at';
    public const TOKEN_LAST_USED_KEY  = 'platform_api.token_last_used_at';
    public const TOKEN_ENCRYPTED_KEY  = 'platform_api.token_encrypted';
    public const TOKEN_PREFIX         = 'flb_platform_';

    /**
     * How often, in seconds, a use of the token is written down. Every app signed in
     * through the token presents it, and each write clears the system settings cache.
     */
    public const USED_WRITE_INTERVAL = 300;

    /** Request attribute set once a request has authenticated with the platform token. */
    public const REQUEST_ATTRIBUTE = 'fleetbase.platform_api';

    public static function generateToken(): string
    {
        return static::TOKEN_PREFIX . Str::random(64);
    }

    public static function rotateToken(): string
    {
        $token = static::generateToken();
        $now   = Carbon::now()->toISOString();

        Setting::configureSystem(static::TOKEN_HASH_KEY, Hash::make($token));
        // Kept encrypted as well, so the instance can hand the token to an app it is
        // linking (see token()). The token only opens the sign-in routes and already
        // ships inside every app built for the instance.
        Setting::configureSystem(static::TOKEN_ENCRYPTED_KEY, Crypt::encryptString($token));
        Setting::configureSystem(static::TOKEN_ROTATED_AT_KEY, $now);
        Setting::configureSystem(static::TOKEN_LAST_USED_KEY, null);

        return $token;
    }

    public static function revokeToken(): void
    {
        Setting::configureSystem(static::TOKEN_HASH_KEY, null);
        Setting::configureSystem(static::TOKEN_ENCRYPTED_KEY, null);
        Setting::configureSystem(static::TOKEN_ROTATED_AT_KEY, null);
        Setting::configureSystem(static::TOKEN_LAST_USED_KEY, null);
    }

    /**
     * The current token in plain text, for handing to an app that is being linked to
     * this instance. Null when no token is configured, or when the current one was
     * issued before tokens were kept retrievable: rotating it once makes it so.
     */
    public static function token(): ?string
    {
        $encrypted = Setting::system(static::TOKEN_ENCRYPTED_KEY);
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            return null;
        }

        // Only ever the token that is in force: a copy left behind by an older one is not returned.
        return static::validateToken($token) ? $token : null;
    }

    public static function isConfigured(): bool
    {
        return is_string(static::tokenHash()) && static::tokenHash() !== '';
    }

    public static function validateToken(?string $token): bool
    {
        $hash = static::tokenHash();

        return is_string($token) && $token !== '' && is_string($hash) && $hash !== '' && Hash::check($token, $hash);
    }

    /**
     * Whether a credential is shaped like a platform token, valid or not.
     */
    public static function isPlatformToken(?string $token): bool
    {
        return is_string($token) && Str::startsWith($token, static::TOKEN_PREFIX);
    }

    /**
     * Whether the request authenticated with the platform token rather than an
     * organization's API credential or a user's access token. Such a request has no
     * organization and no user.
     */
    public static function authenticated(?Request $request = null): bool
    {
        $request ??= request();

        return $request instanceof Request && $request->attributes->get(static::REQUEST_ATTRIBUTE) === true;
    }

    public static function markAuthenticated(Request $request): void
    {
        $request->attributes->set(static::REQUEST_ATTRIBUTE, true);
    }

    /**
     * Records that the token was used, at most once per USED_WRITE_INTERVAL.
     */
    public static function markUsed(): void
    {
        $now      = Carbon::now();
        $lastUsed = Setting::system(static::TOKEN_LAST_USED_KEY);

        if (is_string($lastUsed) && $lastUsed !== '') {
            try {
                $elapsed = $now->getTimestamp() - Carbon::parse($lastUsed)->getTimestamp();
                if ($elapsed >= 0 && $elapsed < static::USED_WRITE_INTERVAL) {
                    return;
                }
            } catch (\Throwable $e) {
                // An unreadable timestamp is replaced below.
            }
        }

        Setting::configureSystem(static::TOKEN_LAST_USED_KEY, $now->toISOString());
    }

    public static function status(): array
    {
        return [
            'configured'   => static::isConfigured(),
            'retrievable'  => static::token() !== null,
            'rotated_at'   => Setting::system(static::TOKEN_ROTATED_AT_KEY),
            'last_used_at' => Setting::system(static::TOKEN_LAST_USED_KEY),
        ];
    }

    public static function tokenHash(): ?string
    {
        $hash = Setting::system(static::TOKEN_HASH_KEY);

        return is_string($hash) ? $hash : null;
    }
}
