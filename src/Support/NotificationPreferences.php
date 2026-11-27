<?php

namespace Fleetbase\Support;

use Fleetbase\Models\User;

/**
 * What a person has asked to be told about on their phone.
 *
 * Kept on the user (`meta.notification_preferences`) so every package that
 * notifies a person reads the same answer. A category the person has switched
 * off loses its push and mail channels and keeps `broadcast`: an app that is
 * open still learns what changed, it just is not rung about it.
 *
 * Everything is on until someone turns it off, so a person who has never
 * opened the setting is notified as before.
 */
class NotificationPreferences
{
    public const META_KEY = 'notification_preferences';

    /** The categories a driver app offers, each on by default. */
    public const CATEGORIES = ['new_work', 'order_changes', 'messages', 'offers'];

    /** Channels that only tell an app already listening; never silenced. */
    public const QUIET_CHANNELS = ['broadcast', 'database'];

    public static function userFor($notifiable): ?User
    {
        if ($notifiable instanceof User) {
            return $notifiable;
        }

        if (is_object($notifiable) && !empty($notifiable->user_uuid)) {
            $user = method_exists($notifiable, 'relationLoaded') && $notifiable->relationLoaded('user') ? $notifiable->user : null;

            return $user instanceof User ? $user : User::where('uuid', $notifiable->user_uuid)->first();
        }

        return null;
    }

    /**
     * Every category with the person's answer.
     *
     * @return array<string, bool>
     */
    public static function for(?User $user): array
    {
        $stored = $user ? (array) data_get($user->meta, static::META_KEY, []) : [];
        $out    = [];
        foreach (static::CATEGORIES as $category) {
            $out[$category] = array_key_exists($category, $stored) ? (bool) $stored[$category] : true;
        }

        return $out;
    }

    /**
     * Saves the categories given and leaves the rest as they were.
     *
     * @return array<string, bool>
     */
    public static function update(User $user, array $changes): array
    {
        $preferences = static::for($user);
        foreach (static::CATEGORIES as $category) {
            if (array_key_exists($category, $changes)) {
                $preferences[$category] = filter_var($changes[$category], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $meta                    = (array) $user->meta;
        $meta[static::META_KEY] = $preferences;
        $user->meta              = $meta;
        $user->saveQuietly();

        return $preferences;
    }

    public static function allows($notifiable, string $category): bool
    {
        try {
            return static::for(static::userFor($notifiable))[$category] ?? true;
        } catch (\Throwable $e) {
            // A preference that cannot be read must not cost anyone a notification.
            return true;
        }
    }

    /**
     * The channels a notification of this category may use for this person.
     */
    public static function channels($notifiable, string $category, array $channels): array
    {
        if (static::allows($notifiable, $category)) {
            return $channels;
        }

        return array_values(array_filter($channels, fn ($channel) => in_array($channel, static::QUIET_CHANNELS, true)));
    }
}
