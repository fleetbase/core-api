<?php

namespace Fleetbase\Support\Sms;

use Fleetbase\Notifications\SmsGuardAlert;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Pauses SMS for one tenant when its traffic looks like pumping, and tells the admins.
 *
 * Signals, each read from the guard's own counters after a send was reserved:
 *  - the company's daily budget is used up;
 *  - the company's hourly budget was used up twice in a day;
 *  - one tenant texted a burst of distinct new numbers inside ten minutes.
 *
 * A pause is scoped (platform, one company, or one store/network) and timed; a tenant
 * paused automatically more than `auto_pauses_before_manual` times in a day stays paused
 * until an administrator lifts it. Pauses live in the cache for the fast path and in the
 * `system.sms-guard` setting so they survive a cache flush and show on the admin page.
 */
class SmsBreaker
{
    public const SCOPE_PLATFORM = 'platform';

    public const SIGNAL_COMPANY_DAILY_BUDGET   = 'company_daily_budget';
    public const SIGNAL_COMPANY_HOURLY_REPEAT  = 'company_hourly_budget_repeated';
    public const SIGNAL_BUDGET_THRESHOLD       = 'company_budget_threshold';
    public const SIGNAL_DISTINCT_DESTINATIONS  = 'distinct_destinations';
    public const SIGNAL_PLATFORM_THRESHOLD     = 'platform_budget_threshold';

    public const DISTINCT_WINDOW = 600;
    public const ALERT_WINDOW    = 3600;

    public function __construct(protected CacheRepository $cache)
    {
    }

    /**
     * The pause in force for a scope, or null. `until` is a unix timestamp, or null for a
     * pause that waits for an administrator.
     *
     * @return array{until: ?int, reason: string, auto: bool, company_uuid: ?string, at: int}|null
     */
    public function pausedUntil(string $scope): ?array
    {
        $pause = $this->cache->get($this->key('pause:' . $scope));
        if (!is_array($pause)) {
            $pause = SmsPolicy::settings()['pauses'][$scope] ?? null;
        }

        if (!is_array($pause)) {
            return null;
        }

        if (($pause['until'] ?? null) !== null && (int) $pause['until'] <= time()) {
            return null;
        }

        return $pause;
    }

    /**
     * Pause a scope for `$minutes`, or until unpaused when null.
     */
    public function pause(string $scope, ?int $minutes, string $reason, bool $auto = false, ?string $companyUuid = null): array
    {
        $pause = [
            'until'        => $minutes === null ? null : time() + ($minutes * 60),
            'reason'       => $reason,
            'auto'         => $auto,
            'company_uuid' => $companyUuid,
            'at'           => time(),
        ];

        if ($minutes === null) {
            $this->cache->forever($this->key('pause:' . $scope), $pause);
        } else {
            $this->cache->put($this->key('pause:' . $scope), $pause, $minutes * 60);
        }

        try {
            SmsPolicy::update(['pauses' => [$scope => $pause]]);
        } catch (\Throwable $e) {
            Log::warning('sms.pause_not_persisted', ['scope' => $scope, 'error' => $e->getMessage()]);
        }

        Log::warning('sms.paused', array_merge($pause, ['scope' => $scope]));

        return $pause;
    }

    public function unpause(string $scope): void
    {
        $this->cache->forget($this->key('pause:' . $scope));

        try {
            SmsPolicy::update(['pauses' => [$scope => null]]);
        } catch (\Throwable $e) {
            Log::warning('sms.unpause_not_persisted', ['scope' => $scope, 'error' => $e->getMessage()]);
        }

        Log::info('sms.unpaused', ['scope' => $scope]);
    }

    /**
     * Current pauses from the policy, expired ones dropped.
     */
    public function pauses(): array
    {
        return array_filter(SmsPolicy::settings()['pauses'], fn ($pause) => $pause['until'] === null || $pause['until'] > time());
    }

    /**
     * Read the counters after a reserved send and trip whatever crossed a line.
     *
     * @param array<int, array{key: string, max: int, decay: int, reason: string, scope: string, window: string}> $counters the guard's counters for this send
     */
    public function observe(SmsContext $context, array $limits, array $counters, RateLimiter $limiter, bool $monitor, array $settings): void
    {
        $breaker = $settings['breaker'];

        if ($context->companyUuid && $limits['company']) {
            $this->observeCompanyBudget($context, $counters, $limiter, $breaker, $monitor);
        }

        if (!$limits['trusted']) {
            $this->observeDistinctDestinations($context, $breaker, $monitor);
        }

        $this->observePlatform($context, $counters, $limiter, $breaker, $monitor);
    }

    protected function observeCompanyBudget(SmsContext $context, array $counters, RateLimiter $limiter, array $breaker, bool $monitor): void
    {
        $scope = $context->companyScope();
        $day   = $this->counter($counters, 'company', 'per_day');
        $hour  = $this->counter($counters, 'company', 'per_hour');

        if ($day) {
            $attempts = $limiter->attempts($day['key']);

            if ($attempts >= $day['max']) {
                $this->trip($scope, self::SIGNAL_COMPANY_DAILY_BUDGET, $context, $breaker, $monitor, ['sends_today' => $attempts, 'budget' => $day['max']]);
            } else {
                foreach ($breaker['alert_percentages'] as $percent) {
                    if ($attempts >= (int) ceil($day['max'] * $percent / 100) && $this->once('alert:' . $scope . ':day:' . $percent . ':' . intdiv(time(), 86400), 86400)) {
                        $this->alert(self::SIGNAL_BUDGET_THRESHOLD, $context, ['percent' => $percent, 'sends_today' => $attempts, 'budget' => $day['max'], 'scope' => $scope, 'mode' => $monitor ? SmsPolicy::MODE_MONITOR : SmsPolicy::MODE_ENFORCE]);
                    }
                }
            }
        }

        if ($hour && $limiter->attempts($hour['key']) >= $hour['max']) {
            if ($this->once('hour-trip:' . $scope . ':' . intdiv(time(), 3600), 86400)) {
                $trips = $this->increment('hour-trips:' . $scope, 86400);
                if ($trips >= 2) {
                    $this->trip($scope, self::SIGNAL_COMPANY_HOURLY_REPEAT, $context, $breaker, $monitor, ['hours_at_budget_today' => $trips, 'budget' => $hour['max']]);
                }
            }
        }
    }

    protected function observeDistinctDestinations(SmsContext $context, array $breaker, bool $monitor): void
    {
        $threshold = $breaker['distinct_destinations_per_10m'];
        $scope     = $context->tenantScope() ?? $context->companyScope();
        if (!$threshold || !$scope) {
            return;
        }

        $bucket = intdiv(time(), self::DISTINCT_WINDOW);
        if (!$this->once('distinct:' . $scope . ':' . $bucket . ':' . sha1($context->destination), self::DISTINCT_WINDOW)) {
            return;
        }

        $count = $this->increment('distinct-count:' . $scope . ':' . $bucket, self::DISTINCT_WINDOW);
        if ($count >= $threshold) {
            $this->trip($scope, self::SIGNAL_DISTINCT_DESTINATIONS, $context, $breaker, $monitor, ['distinct_destinations' => $count, 'window_minutes' => self::DISTINCT_WINDOW / 60]);
        }
    }

    protected function observePlatform(SmsContext $context, array $counters, RateLimiter $limiter, array $breaker, bool $monitor): void
    {
        $hour = $this->counter($counters, 'platform', 'per_hour');
        if (!$hour) {
            return;
        }

        $attempts = $limiter->attempts($hour['key']);
        $percent  = max($breaker['alert_percentages'] ?: [80]);
        if ($attempts >= (int) ceil($hour['max'] * $percent / 100) && $this->once('alert:platform:hour:' . intdiv(time(), 3600), 3600)) {
            $this->alert(self::SIGNAL_PLATFORM_THRESHOLD, $context, ['percent' => $percent, 'sends_this_hour' => $attempts, 'budget' => $hour['max'], 'scope' => self::SCOPE_PLATFORM, 'mode' => $monitor ? SmsPolicy::MODE_MONITOR : SmsPolicy::MODE_ENFORCE]);
        }
    }

    /**
     * Pause a scope (unless monitoring) and alert once an hour per signal.
     */
    protected function trip(string $scope, string $signal, SmsContext $context, array $breaker, bool $monitor, array $data): void
    {
        if (!$monitor && $this->pausedUntil($scope)) {
            return;
        }

        $minutes = $breaker['pause_minutes'];
        $reason  = $signal;

        if (!$monitor) {
            $autoPauses = $context->companyScope() ? $this->increment('auto-pauses:' . $context->companyScope(), 86400) : 1;
            if ($autoPauses >= $breaker['auto_pauses_before_manual']) {
                $minutes = null;
                $reason .= ' (paused ' . $autoPauses . ' times today; an administrator must unpause)';
            }

            $this->pause($scope, $minutes, $reason, true, $context->companyUuid);
        }

        if ($this->once('alert:' . $scope . ':' . $signal, self::ALERT_WINDOW)) {
            $this->alert($signal, $context, array_merge($data, [
                'scope'          => $scope,
                'paused_minutes' => $minutes,
                'mode'           => $monitor ? SmsPolicy::MODE_MONITOR : SmsPolicy::MODE_ENFORCE,
            ]));
        }
    }

    /**
     * Send an alert to the administrators. Never throws: an alert that cannot be delivered
     * must not fail the send that triggered it.
     */
    protected function alert(string $signal, SmsContext $context, array $data): void
    {
        $alert = SmsGuardAlert::for($signal, $context, $data);

        Log::warning('sms.alert', array_merge(['signal' => $signal], $context->toArray(), $data));

        try {
            $this->dispatchAlert($alert);
        } catch (\Throwable $e) {
            Log::error('sms.alert_not_sent', ['signal' => $signal, 'error' => $e->getMessage()]);
        }
    }

    protected function dispatchAlert(SmsGuardAlert $alert): void
    {
        $admins      = SmsPolicy::adminUsers();
        $adminEmails = $admins->pluck('email')->filter()->map(fn ($email) => strtolower(trim((string) $email)))->all();
        $extra       = array_values(array_diff(SmsPolicy::alertRecipients(), $adminEmails));

        if ($admins->isNotEmpty()) {
            Notification::sendNow($admins, $alert);
        }

        if ($extra) {
            Notification::route('mail', $extra)->notifyNow($alert);
        }
    }

    protected function counter(array $counters, string $scope, string $window): ?array
    {
        foreach ($counters as $counter) {
            if ($counter['scope'] === $scope && $counter['window'] === $window) {
                return $counter;
            }
        }

        return null;
    }

    /**
     * True the first time a key is seen within its window.
     */
    protected function once(string $key, int $seconds): bool
    {
        return (bool) $this->cache->add($this->key($key), 1, $seconds);
    }

    protected function increment(string $key, int $seconds): int
    {
        $this->cache->add($this->key($key), 0, $seconds);

        return (int) $this->cache->increment($this->key($key));
    }

    protected function key(string $key): string
    {
        return SmsGuard::CACHE_PREFIX . $key;
    }
}
