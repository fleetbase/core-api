<?php

namespace Fleetbase\Support\Sms;

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The effective SMS guard policy: the environment's defaults (config/sms.php `guard`)
 * with the administrator's stored override applied, resolved per send into the limits
 * that apply to one tenant and purpose.
 *
 * Same shape as ApiRateLimits: the override is one `system.sms-guard` setting, cached
 * for a minute and cleared on save, so a change from the console or the `sms:*` commands
 * applies without a deployment. A missing database or cache (unit tests, a broken
 * install) falls back to the defaults rather than failing the send.
 */
class SmsPolicy
{
    public const SETTING_KEY        = 'sms-guard';
    public const SETTINGS_CACHE_KEY = 'sms_guard:settings';
    public const SETTINGS_CACHE_TTL = 60;
    public const COMPANY_CACHE_TTL  = 600;

    public const MODE_ENFORCE = 'enforce';
    public const MODE_MONITOR = 'monitor';

    public const BUCKET_PUBLIC  = 'public';
    public const BUCKET_TRUSTED = 'trusted';

    public const WINDOWS = ['per_minute' => 60, 'per_hour' => 3600, 'per_day' => 86400];

    /**
     * The policy from the environment alone.
     */
    public static function defaults(): array
    {
        return static::normalize((array) config('sms.guard', []));
    }

    /**
     * The administrator's stored override, raw, or [] when there is none.
     */
    public static function stored(): array
    {
        if (!static::hasDatabase()) {
            return [];
        }

        try {
            $value = Setting::where('key', 'system.' . static::SETTING_KEY)->value('value');
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Whether Eloquent has a connection to query at all. Only a bare container (unit tests)
     * lacks one, and a query there does not fail cleanly, so the lookups are skipped.
     */
    protected static function hasDatabase(): bool
    {
        return Model::getConnectionResolver() !== null;
    }

    /**
     * The effective policy: defaults with the stored override applied.
     */
    public static function settings(): array
    {
        try {
            $stored = Cache::remember(static::SETTINGS_CACHE_KEY, static::SETTINGS_CACHE_TTL, fn () => static::stored());
        } catch (\Throwable $e) {
            $stored = [];
        }

        return static::normalize(static::merge((array) config('sms.guard', []), is_array($stored) ? $stored : []));
    }

    /**
     * Replace the stored override and make it effective at once.
     */
    public static function store(array $override): array
    {
        Setting::configureSystem(static::SETTING_KEY, $override);
        static::flush();

        return static::settings();
    }

    /**
     * Change part of the stored override, keeping the rest.
     */
    public static function update(array $changes): array
    {
        return static::store(static::merge(static::stored(), $changes));
    }

    /**
     * Drop the stored override so the environment defaults apply again.
     */
    public static function reset(): array
    {
        Setting::where('key', 'system.' . static::SETTING_KEY)->delete();
        static::flush();

        return static::settings();
    }

    public static function flush(): void
    {
        try {
            Cache::forget(static::SETTINGS_CACHE_KEY);
        } catch (\Throwable $e) {
            // the next read falls back to the database
        }
    }

    public static function isEnabled(): bool
    {
        return static::settings()['enabled'];
    }

    public static function mode(): string
    {
        return static::settings()['mode'];
    }

    /**
     * Coerce a policy into its canonical shape.
     */
    public static function normalize(array $settings): array
    {
        $limits     = (array) ($settings['limits'] ?? []);
        $validation = (array) ($settings['validation'] ?? []);
        $breaker    = (array) ($settings['breaker'] ?? []);
        $platform   = (array) ($limits['platform'] ?? []);
        $mode       = strtolower(trim((string) ($settings['mode'] ?? static::MODE_ENFORCE)));

        $purposes = [];
        foreach ((array) ($settings['purposes'] ?? []) as $purpose => $rules) {
            if (!is_string($purpose) || $purpose === '' || !is_array($rules)) {
                continue;
            }

            $purposes[$purpose] = [
                'trusted'     => (bool) ($rules['trusted'] ?? false),
                'destination' => static::destinationLimits((array) ($rules['destination'] ?? []), false),
            ];
        }

        $overrides = [];
        foreach ((array) ($settings['overrides'] ?? []) as $override) {
            $companyUuid = data_get($override, 'company_uuid');
            if (!is_string($companyUuid) || $companyUuid === '') {
                continue;
            }

            $overrides[$companyUuid] = [
                'company_uuid'      => $companyUuid,
                'unlimited'         => (bool) data_get($override, 'unlimited', false),
                'per_minute'        => static::limit(data_get($override, 'per_minute')),
                'per_hour'          => static::limit(data_get($override, 'per_hour')),
                'per_day'           => static::limit(data_get($override, 'per_day')),
                'allowed_countries' => static::upperList(data_get($override, 'allowed_countries', [])),
                'note'              => (string) data_get($override, 'note', ''),
            ];
        }

        $pauses = [];
        foreach ((array) ($settings['pauses'] ?? []) as $scope => $pause) {
            if (!is_string($scope) || $scope === '' || !is_array($pause)) {
                continue;
            }

            $pauses[$scope] = [
                'until'        => isset($pause['until']) && $pause['until'] !== null ? (int) $pause['until'] : null,
                'reason'       => (string) ($pause['reason'] ?? ''),
                'auto'         => (bool) ($pause['auto'] ?? false),
                'company_uuid' => isset($pause['company_uuid']) ? (string) $pause['company_uuid'] : null,
                'at'           => (int) ($pause['at'] ?? 0),
            ];
        }

        return [
            'enabled'      => (bool) ($settings['enabled'] ?? true),
            'mode'         => in_array($mode, [static::MODE_ENFORCE, static::MODE_MONITOR], true) ? $mode : static::MODE_ENFORCE,
            'cache_store'  => filled($settings['cache_store'] ?? null) ? (string) $settings['cache_store'] : null,
            'alert_emails' => static::emails($settings['alert_emails'] ?? []),
            'validation'   => [
                'enabled'           => (bool) ($validation['enabled'] ?? true),
                'allowed_types'     => static::upperList($validation['allowed_types'] ?? PhoneValidator::DEFAULT_ALLOWED_TYPES) ?: PhoneValidator::DEFAULT_ALLOWED_TYPES,
                'blocked_countries' => static::upperList($validation['blocked_countries'] ?? []),
                'blocked_prefixes'  => static::list($validation['blocked_prefixes'] ?? []),
            ],
            'limits' => [
                'destination' => static::destinationLimits((array) ($limits['destination'] ?? []), true),
                'ip'          => [
                    'per_15_minutes' => static::limit(data_get($limits, 'ip.per_15_minutes', 5)),
                    'per_day'        => static::limit(data_get($limits, 'ip.per_day', 20)),
                ],
                'company'   => static::windowLimits((array) ($limits['company'] ?? []), ['per_minute' => 10, 'per_hour' => 60, 'per_day' => 200]),
                'tenant'    => static::windowLimits((array) ($limits['tenant'] ?? []), []),
                'probation' => [
                    'days'    => max(0, (int) data_get($limits, 'probation.days', 7)),
                    'per_day' => static::limit(data_get($limits, 'probation.per_day', 20)),
                ],
                'platform' => [
                    static::BUCKET_PUBLIC  => static::windowLimits((array) ($platform[static::BUCKET_PUBLIC] ?? []), ['per_minute' => 100, 'per_hour' => 1500, 'per_day' => 10000]),
                    static::BUCKET_TRUSTED => static::windowLimits((array) ($platform[static::BUCKET_TRUSTED] ?? []), ['per_minute' => 100, 'per_hour' => 1500, 'per_day' => 10000]),
                ],
            ],
            'breaker' => [
                'pause_minutes'                 => max(1, (int) ($breaker['pause_minutes'] ?? 60)),
                'alert_percentages'             => array_values(array_filter(array_map('intval', static::list($breaker['alert_percentages'] ?? [50, 80])), fn ($pct) => $pct > 0 && $pct < 100)),
                'distinct_destinations_per_10m' => static::limit($breaker['distinct_destinations_per_10m'] ?? 25),
                'auto_pauses_before_manual'     => max(1, (int) ($breaker['auto_pauses_before_manual'] ?? 2)),
                'new_country_min_sends'         => static::limit($breaker['new_country_min_sends'] ?? 10),
                'new_country_min_history'       => max(0, (int) ($breaker['new_country_min_history'] ?? 50)),
                'conversion_min_issued'         => static::limit($breaker['conversion_min_issued'] ?? 20),
                'conversion_min_percent'        => max(0, min(100, (int) ($breaker['conversion_min_percent'] ?? 10))),
            ],
            'ledger' => [
                'retention_days'  => max(0, (int) data_get($settings, 'ledger.retention_days', 90)),
                'mask_after_days' => max(0, (int) data_get($settings, 'ledger.mask_after_days', 30)),
            ],
            'purposes'  => $purposes,
            'overrides' => array_values($overrides),
            'pauses'    => $pauses,
        ];
    }

    /**
     * The administrator's exception for a company, if any.
     */
    public static function overrideFor(?string $companyUuid, ?array $settings = null): ?array
    {
        if (!$companyUuid) {
            return null;
        }

        foreach (($settings ?? static::settings())['overrides'] as $override) {
            if ($override['company_uuid'] === $companyUuid) {
                return $override;
            }
        }

        return null;
    }

    public static function isTrusted(string $purpose, ?array $settings = null): bool
    {
        return (bool) data_get($settings ?? static::settings(), 'purposes.' . $purpose . '.trusted', false);
    }

    /**
     * The limits that apply to one send.
     *
     * @return array{trusted: bool, bucket: string, destination: array, ip: ?array, tenant: ?array, company: ?array, company_unlimited: bool, platform: array, allowed_countries: array, probation: bool}
     */
    public static function limitsFor(SmsContext $context, ?array $settings = null): array
    {
        $settings ??= static::settings();
        $override  = static::overrideFor($context->companyUuid, $settings);
        $purpose   = $settings['purposes'][$context->purpose] ?? ['trusted' => false, 'destination' => []];
        $trusted   = (bool) ($purpose['trusted'] ?? false);
        $probation = false;

        $destination = $settings['limits']['destination'];
        foreach ((array) ($purpose['destination'] ?? []) as $key => $value) {
            if ($value !== null) {
                $destination[$key] = $value;
            }
        }

        // Trusted purposes (2FA, admin actions) answer to their own platform bucket and the
        // destination limits only: a pumped tenant must still be able to sign its staff in.
        $company = null;
        if ($context->companyUuid && !$trusted && !($override['unlimited'] ?? false)) {
            $company = $settings['limits']['company'];

            if ($override) {
                foreach (array_keys(static::WINDOWS) as $window) {
                    if ($override[$window] !== null) {
                        $company[$window] = $override[$window];
                    }
                }
            } elseif (static::isOnProbation($context->companyUuid, $settings['limits']['probation']['days'])) {
                $probation = true;
                $limit     = $settings['limits']['probation']['per_day'];
                if ($limit !== null) {
                    $company['per_day'] = $company['per_day'] === null ? $limit : min($company['per_day'], $limit);
                }
            }
        }

        $tenant = null;
        if (!$trusted && $context->tenantUuid) {
            $tenant = array_filter($settings['limits']['tenant'], fn ($value) => $value !== null) ?: null;
        }

        $bucket = $trusted ? static::BUCKET_TRUSTED : static::BUCKET_PUBLIC;

        return [
            'trusted'           => $trusted,
            'bucket'            => $bucket,
            'destination'       => $destination,
            'ip'                => $trusted ? null : $settings['limits']['ip'],
            'tenant'            => $tenant,
            'company'           => $company,
            'company_unlimited' => (bool) ($override['unlimited'] ?? false),
            'platform'          => $settings['limits']['platform'][$bucket],
            'allowed_countries' => $override['allowed_countries'] ?? [],
            'probation'         => $probation,
        ];
    }

    /**
     * Whether a company is younger than the probation period. Unknown companies are not.
     */
    public static function isOnProbation(?string $companyUuid, int $days): bool
    {
        if (!$companyUuid || $days <= 0 || !static::hasDatabase()) {
            return false;
        }

        try {
            $createdAt = Cache::remember('sms_guard:company_created:' . $companyUuid, static::COMPANY_CACHE_TTL, function () use ($companyUuid) {
                $value = Company::withoutGlobalScopes()->where('uuid', $companyUuid)->value('created_at');

                return $value ? Carbon::parse($value)->toIso8601String() : '';
            });
        } catch (\Throwable $e) {
            return false;
        }

        return filled($createdAt) && Carbon::parse($createdAt)->gt(Carbon::now()->subDays($days));
    }

    /**
     * Where alerts go: the addresses in the policy, plus every system administrator.
     * Tenants are never alerted.
     *
     * @return array<int, string>
     */
    public static function alertRecipients(?array $settings = null): array
    {
        $emails = ($settings ?? static::settings())['alert_emails'];

        foreach (static::adminUsers() as $admin) {
            if (filled($admin->email)) {
                $emails[] = strtolower(trim((string) $admin->email));
            }
        }

        return array_values(array_unique(array_filter($emails, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
    }

    /**
     * The system administrators, for in-app notifications.
     *
     * @return Collection<int, User>
     */
    public static function adminUsers(): Collection
    {
        if (!static::hasDatabase()) {
            return new Collection();
        }

        try {
            return User::withoutGlobalScopes()->where('type', 'admin')->whereNull('deleted_at')->get();
        } catch (\Throwable $e) {
            return new Collection();
        }
    }

    /**
     * Apply an override to a base policy. Associative arrays merge key by key; lists and
     * scalars are replaced, so a stored `allowed_types` of two entries does not pick up a
     * third from the defaults.
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = static::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    protected static function destinationLimits(array $limits, bool $withDefaults): array
    {
        $defaults = $withDefaults ? ['cooldown_seconds' => 60, 'per_hour' => 3, 'per_day' => 5] : ['cooldown_seconds' => null, 'per_hour' => null, 'per_day' => null];

        return [
            'cooldown_seconds' => static::limit($limits['cooldown_seconds'] ?? $defaults['cooldown_seconds']),
            'per_hour'         => static::limit($limits['per_hour'] ?? $defaults['per_hour']),
            'per_day'          => static::limit($limits['per_day'] ?? $defaults['per_day']),
        ];
    }

    protected static function windowLimits(array $limits, array $defaults): array
    {
        $result = [];
        foreach (array_keys(static::WINDOWS) as $window) {
            $result[$window] = static::limit(array_key_exists($window, $limits) ? $limits[$window] : ($defaults[$window] ?? null));
        }

        return $result;
    }

    /**
     * A limit as an integer, or null for "no limit" (null, '', 'none', 'unlimited').
     */
    protected static function limit(mixed $value): ?int
    {
        if ($value === null || $value === '' || (is_string($value) && in_array(strtolower(trim($value)), ['none', 'unlimited', 'null'], true))) {
            return null;
        }

        return max(0, (int) $value);
    }

    protected static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), (array) $value), fn ($item) => $item !== ''));
    }

    protected static function upperList(mixed $value): array
    {
        return array_map('strtoupper', static::list($value));
    }

    protected static function emails(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map('strtolower', static::list($value)), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
    }
}
