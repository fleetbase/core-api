<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformApi
{
    public const TOKEN_HASH_KEY       = 'platform_api.token_hash';
    public const TOKEN_ROTATED_AT_KEY = 'platform_api.token_rotated_at';
    public const TOKEN_LAST_USED_KEY  = 'platform_api.token_last_used_at';

    public static function generateToken(): string
    {
        return 'flb_platform_' . Str::random(64);
    }

    public static function rotateToken(): string
    {
        $token = static::generateToken();
        $now   = Carbon::now()->toISOString();

        Setting::configureSystem(static::TOKEN_HASH_KEY, Hash::make($token));
        Setting::configureSystem(static::TOKEN_ROTATED_AT_KEY, $now);
        Setting::configureSystem(static::TOKEN_LAST_USED_KEY, null);

        return $token;
    }

    public static function revokeToken(): void
    {
        Setting::configureSystem(static::TOKEN_HASH_KEY, null);
        Setting::configureSystem(static::TOKEN_ROTATED_AT_KEY, null);
        Setting::configureSystem(static::TOKEN_LAST_USED_KEY, null);
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

    public static function markUsed(): void
    {
        Setting::configureSystem(static::TOKEN_LAST_USED_KEY, Carbon::now()->toISOString());
    }

    public static function status(): array
    {
        return [
            'configured'   => static::isConfigured(),
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
