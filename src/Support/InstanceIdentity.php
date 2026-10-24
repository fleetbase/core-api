<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;
use Illuminate\Support\Str;

/**
 * This install's own identity: an ID and a secret created at runtime and
 * stored in the database (system setting `system.instance`).
 *
 * Not `.fleetbase-id` (config fleetbase.instance_id): that file is written
 * when the Docker image is built, so every install of a tag shares it, and it
 * is lost when the container is rebuilt. Services that need to tell installs
 * apart, such as Fleetbase Hosted Routing's install-time keys, use this
 * instead. The secret proves to such a service that a later request comes
 * from the same install; it is never logged or shown.
 */
class InstanceIdentity
{
    public const SETTING_KEY = 'system.instance';

    /**
     * The install's identity, created on first use.
     *
     * @return array{id: string, secret: string, created_at: string}
     */
    public static function get(): array
    {
        $identity = Setting::lookup(static::SETTING_KEY);
        if (static::isValid($identity)) {
            return $identity;
        }

        $identity = [
            'id'         => (string) Str::uuid(),
            'secret'     => Str::random(64),
            'created_at' => now()->toIso8601String(),
        ];

        // firstOrCreate on the unique key (createOrFirst underneath): two
        // processes racing on first use end up with the row written first.
        $setting = Setting::firstOrCreate(['key' => static::SETTING_KEY], ['value' => $identity]);

        if (!static::isValid($setting?->value)) {
            // A malformed row (edited by hand): replace it.
            $setting = Setting::configure(static::SETTING_KEY, $identity);
        }

        return $setting->value;
    }

    public static function id(): string
    {
        return static::get()['id'];
    }

    public static function secret(): string
    {
        return static::get()['secret'];
    }

    protected static function isValid($identity): bool
    {
        return is_array($identity) && !empty($identity['id']) && !empty($identity['secret']);
    }
}
