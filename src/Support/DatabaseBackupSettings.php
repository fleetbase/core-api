<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Setting;

/**
 * Effective database backup settings.
 *
 * The environment (config/database-backups.php) supplies the defaults; a system administrator
 * overrides them from the console, which stores a single `system.database-backups` setting.
 * The scheduler reads these on every `schedule:run`, so a change applies from the next minute.
 */
class DatabaseBackupSettings
{
    public const SETTING_KEY = 'database-backups';

    public const FREQUENCIES = ['hourly', 'every_six_hours', 'every_twelve_hours', 'daily', 'weekly'];

    /**
     * Settings from the environment alone, before any administrator override.
     */
    public static function defaults(): array
    {
        return static::normalize([
            'enabled'           => config('database-backups.enabled', false),
            'frequency'         => config('database-backups.frequency', 'daily'),
            'time'              => config('database-backups.time', '00:00'),
            'day_of_week'       => config('database-backups.day_of_week', 0),
            'disk'              => config('database-backups.disk', 's3'),
            'bucket'            => config('database-backups.bucket'),
            'path'              => config('database-backups.path', ''),
            'connections'       => config('database-backups.connections', ['mysql', 'sandbox']),
            'retention_days'    => config('database-backups.retention_days', 30),
            'retention_count'   => config('database-backups.retention_count'),
            'min_size_bytes'    => config('database-backups.min_size_bytes', 1024),
            'notify_on_failure' => config('database-backups.notify_on_failure', false),
            'notify_emails'     => config('database-backups.notify_emails', []),
        ]);
    }

    /**
     * The effective settings: the environment defaults with the stored override applied.
     *
     * A missing database (a fresh install, unit tests) falls back to the defaults rather than
     * failing whatever asked — most importantly the scheduler.
     */
    public static function settings(): array
    {
        try {
            $stored = Setting::where('key', 'system.' . static::SETTING_KEY)->value('value');
        } catch (\Throwable $e) {
            $stored = null;
        }

        return static::normalize(array_merge(static::defaults(), is_array($stored) ? $stored : []));
    }

    /**
     * Persist administrator settings.
     */
    public static function store(array $settings): array
    {
        $settings = static::normalize(array_merge(static::defaults(), $settings));

        Setting::configureSystem(static::SETTING_KEY, $settings);

        return $settings;
    }

    /**
     * Drop the stored override so the environment defaults apply again.
     */
    public static function reset(): array
    {
        Setting::where('key', 'system.' . static::SETTING_KEY)->delete();

        return static::settings();
    }

    /**
     * Coerce settings into their canonical shape.
     */
    public static function normalize(array $settings): array
    {
        $frequency = in_array($settings['frequency'] ?? null, static::FREQUENCIES, true) ? $settings['frequency'] : 'daily';
        $time      = is_string($settings['time'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $settings['time']) ? $settings['time'] : '00:00';
        $bucket    = trim((string) ($settings['bucket'] ?? ''));

        return [
            'enabled'           => filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'frequency'         => $frequency,
            'time'              => $time,
            'day_of_week'       => min(6, max(0, (int) ($settings['day_of_week'] ?? 0))),
            'disk'              => (string) ($settings['disk'] ?? 's3') ?: 's3',
            'bucket'            => $bucket === '' ? null : $bucket,
            'path'              => trim((string) ($settings['path'] ?? ''), "/ \t\n\r"),
            'connections'       => static::stringList($settings['connections'] ?? []),
            'retention_days'    => static::positiveIntOrNull($settings['retention_days'] ?? null),
            'retention_count'   => static::positiveIntOrNull($settings['retention_count'] ?? null),
            'min_size_bytes'    => max(0, (int) ($settings['min_size_bytes'] ?? 1024)),
            'notify_on_failure' => filter_var($settings['notify_on_failure'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'notify_emails'     => static::stringList($settings['notify_emails'] ?? []),
        ];
    }

    /**
     * The cron expression for the configured frequency, in UTC.
     */
    public static function cronExpression(array $settings): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $settings['time']));

        return match ($settings['frequency']) {
            'hourly'             => "{$minute} * * * *",
            'every_six_hours'    => $minute . ' ' . implode(',', [$hour % 6, $hour % 6 + 6, $hour % 6 + 12, $hour % 6 + 18]) . ' * * *',
            'every_twelve_hours' => $minute . ' ' . implode(',', [$hour % 12, $hour % 12 + 12]) . ' * * *',
            'weekly'             => "{$minute} {$hour} * * {$settings['day_of_week']}",
            default              => "{$minute} {$hour} * * *",
        };
    }

    /**
     * Register the backup on the scheduler when backups are enabled.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public static function schedule($schedule, ?array $settings = null): void
    {
        $settings ??= static::settings();

        if (!$settings['enabled']) {
            return;
        }

        $schedule->command('db:backup --no-interaction --trigger=scheduled')
            ->cron(static::cronExpression($settings))
            ->timezone('UTC')
            ->name('database-backup')
            ->withoutOverlapping(240);
    }

    /**
     * The filesystem disks a backup can be written to.
     */
    public static function disks(): array
    {
        $disks = [];
        foreach ((array) config('filesystems.disks', []) as $name => $disk) {
            $disks[] = ['name' => (string) $name, 'driver' => (string) data_get($disk, 'driver', '')];
        }

        return $disks;
    }

    /**
     * The database connections that can be dumped: those using a MySQL-compatible driver.
     */
    public static function connections(): array
    {
        $connections = [];
        foreach ((array) config('database.connections', []) as $name => $connection) {
            if (in_array(data_get($connection, 'driver'), ['mysql', 'mariadb'], true)) {
                $connections[] = (string) $name;
            }
        }

        return $connections;
    }

    protected static function stringList($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_unique(array_filter(array_map(fn ($item) => trim((string) $item), (array) $value), fn ($item) => $item !== '')));
    }

    protected static function positiveIntOrNull($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
