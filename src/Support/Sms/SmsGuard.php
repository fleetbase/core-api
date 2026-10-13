<?php

namespace Fleetbase\Support\Sms;

use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\Models\SmsSend;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * The one place every SMS passes through before it costs money.
 *
 * `reserve()` runs, in order: the policy switch, destination validation, pauses, the
 * per-destination cooldown and caps, the per-IP layer, the store/network and company
 * budgets, then the platform bucket. Only when all of them pass are the counters hit and
 * a reservation returned; a refusal throws {@see SmsBlockedException} with the status the
 * caller should answer with. In monitor mode nothing is refused, but every refusal that
 * would have happened is logged as `sms.would_block`.
 *
 * Called from VerificationCode::generateSmsVerificationFor() before the code row is
 * written and from SmsService::send() before the provider is called; the reservation is
 * passed between them so a send is counted once.
 */
class SmsGuard
{
    public const CACHE_PREFIX = 'sms-guard:';

    /**
     * Option keys that belong to the guard, stripped before options reach a provider.
     */
    public const OPTIONS = ['purpose', 'company_uuid', 'tenant_type', 'tenant_uuid', 'source', 'ip', 'user_agent', 'subject', 'sms_reservation'];

    protected ?RateLimiter $limiter = null;

    public function __construct(protected ?CacheRepository $cache = null, protected ?SmsBreaker $breaker = null)
    {
    }

    /**
     * The guard to use: a bound instance (tests, or a host that wants its own store), else
     * one on the configured cache store.
     */
    public static function make(): static
    {
        try {
            if (app()->bound(static::class)) {
                return app(static::class);
            }
        } catch (\Throwable $e) {
            // no container: fall through to a fresh guard
        }

        return new static();
    }

    /**
     * Check a send against the policy and, when it may go ahead, count it.
     *
     * @throws SmsBlockedException when the send is refused
     */
    public function reserve(SmsContext $context): SmsReservation
    {
        $settings = SmsPolicy::settings();
        if (!$settings['enabled']) {
            return new SmsReservation($context, SmsReservation::DECISION_BYPASSED);
        }

        $monitor    = $settings['mode'] === SmsPolicy::MODE_MONITOR;
        $limits     = SmsPolicy::limitsFor($context, $settings);
        $wouldBlock = null;

        $validation = PhoneValidator::validate($context->destination, array_merge($settings['validation'], ['allowed_countries' => $limits['allowed_countries']]));
        if (!$validation['valid']) {
            $wouldBlock = $this->refuse($context, SmsBlockedException::invalidDestination((string) $validation['reason'], $context->toArray()), $monitor);
        } elseif ($validation['e164'] && $validation['e164'] !== $context->destination) {
            $context = $context->withDestination($validation['e164']);
        }

        try {
            $counters = $this->counters($context, $limits);

            if ($wouldBlock === null) {
                foreach ($this->pauseScopes($context, $limits) as $scope) {
                    if ($pause = $this->breaker()->pausedUntil($scope)) {
                        $retryAfter = $pause['until'] !== null ? max(1, (int) $pause['until'] - time()) : null;
                        $wouldBlock = $this->refuse($context, SmsBlockedException::paused($scope, $retryAfter, $context->toArray()), $monitor);
                        break;
                    }
                }
            }

            if ($wouldBlock === null) {
                foreach ($counters as $counter) {
                    if ($this->limiter()->tooManyAttempts($counter['key'], $counter['max'])) {
                        $wouldBlock = $this->refuse($context, SmsBlockedException::limited($counter['reason'], (int) $this->limiter()->availableIn($counter['key']), array_merge($context->toArray(), ['scope' => $counter['scope'], 'window' => $counter['window']])), $monitor);
                        break;
                    }
                }
            }

            foreach ($counters as $counter) {
                $this->limiter()->hit($counter['key'], $counter['decay']);
            }

            $this->breaker()->observe($context, $limits, $counters, $this->limiter(), $monitor, $settings, $validation['country']);
        } catch (SmsBlockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // The counter store is unreachable. Public sends fail closed: refusing to pump
            // SMS blind is the point. Trusted and internal sends (2FA, console actions) fail
            // open so staff can still sign in while the store is being fixed.
            Log::warning('sms.guard_unavailable', array_merge($context->toArray(), ['error' => $e->getMessage()]));

            if (!$monitor && $context->isPublic() && !$limits['trusted']) {
                throw SmsBlockedException::unavailable($context->toArray());
            }

            return $this->ledgered(new SmsReservation($context, SmsReservation::DECISION_DEGRADED, null, $validation['country'], $validation['type']));
        }

        return $this->ledgered(new SmsReservation($context, $wouldBlock === null ? SmsReservation::DECISION_ALLOWED : SmsReservation::DECISION_MONITOR, $wouldBlock, $validation['country'], $validation['type']));
    }

    /**
     * Put a send that is going ahead on the ledger as reserved; settle() records how it ended.
     */
    protected function ledgered(SmsReservation $reservation): SmsReservation
    {
        $reservation->sendUuid = SmsLedger::record($reservation->context, SmsSend::STATUS_RESERVED, [
            'destination_country' => $reservation->country,
            'destination_type'    => $reservation->type,
            'meta'                => array_filter(['decision' => $reservation->decision, 'would_block' => $reservation->wouldBlock]),
        ]);

        return $reservation;
    }

    /**
     * Record how a reserved send ended.
     */
    public function settle(SmsReservation $reservation, bool $success, array $details = []): void
    {
        if ($reservation->settled) {
            return;
        }

        $reservation->settled = true;

        if (!$reservation->isGuarded()) {
            return;
        }

        $entry = array_merge($reservation->toArray(), Arr::only($details, ['provider', 'message_id', 'error']));

        if ($success) {
            Log::info('sms.sent', $entry);
        } else {
            Log::warning('sms.failed', $entry);
        }

        SmsLedger::update($reservation->sendUuid, [
            'status'              => $success ? SmsSend::STATUS_SENT : SmsSend::STATUS_FAILED,
            'provider'            => $details['provider'] ?? null,
            'provider_message_id' => isset($details['message_id']) ? (string) $details['message_id'] : null,
            'error'               => $details['error'] ?? null,
        ]);
    }

    public function breaker(): SmsBreaker
    {
        return $this->breaker ??= new SmsBreaker($this->cache());
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->cache());
    }

    /**
     * Options a provider should see: everything the caller passed minus the guard's own.
     */
    public static function providerOptions(array $options): array
    {
        return Arr::except($options, self::OPTIONS);
    }

    /**
     * The counters a send is checked against and then counted in, in check order.
     *
     * @return array<int, array{key: string, max: int, decay: int, reason: string, scope: string, window: string}>
     */
    public function counters(SmsContext $context, array $limits): array
    {
        $counters    = [];
        $destination = $limits['destination'];
        $purpose     = $context->purpose;
        $number      = $context->destination;

        if (($destination['cooldown_seconds'] ?? null) > 0) {
            $counters[] = $this->counter('dest:' . $purpose . ':' . $number . ':cooldown', 1, (int) $destination['cooldown_seconds'], 'cooldown', 'destination', 'cooldown');
        }
        if (($destination['per_hour'] ?? null) !== null) {
            $counters[] = $this->counter('dest:' . $purpose . ':' . $number . ':hour', (int) $destination['per_hour'], 3600, 'destination_limit', 'destination', 'per_hour');
        }
        if (($destination['per_day'] ?? null) !== null) {
            $counters[] = $this->counter('dest:' . $purpose . ':' . $number . ':day', (int) $destination['per_day'], 86400, 'destination_limit', 'destination', 'per_day');
        }

        if ($limits['ip'] && $context->ip) {
            $owner = $context->companyScope() ?? SmsBreaker::SCOPE_PLATFORM;
            if (($limits['ip']['per_15_minutes'] ?? null) !== null) {
                $counters[] = $this->counter('ip:' . $owner . ':' . $context->ip . ':15m', (int) $limits['ip']['per_15_minutes'], 900, 'ip_limit', 'ip', 'per_15_minutes');
            }
            if (($limits['ip']['per_day'] ?? null) !== null) {
                $counters[] = $this->counter('ip:' . $owner . ':' . $context->ip . ':day', (int) $limits['ip']['per_day'], 86400, 'ip_limit', 'ip', 'per_day');
            }
        }

        if ($limits['tenant'] && $context->tenantScope()) {
            foreach (SmsPolicy::WINDOWS as $window => $decay) {
                if (($limits['tenant'][$window] ?? null) !== null) {
                    $counters[] = $this->counter($context->tenantScope() . ':' . $window, (int) $limits['tenant'][$window], $decay, 'tenant_limit', 'tenant', $window);
                }
            }
        }

        if ($limits['company'] && $context->companyScope()) {
            foreach (SmsPolicy::WINDOWS as $window => $decay) {
                if (($limits['company'][$window] ?? null) !== null) {
                    $counters[] = $this->counter($context->companyScope() . ':' . $window, (int) $limits['company'][$window], $decay, 'company_limit', 'company', $window);
                }
            }
        }

        foreach (SmsPolicy::WINDOWS as $window => $decay) {
            if (($limits['platform'][$window] ?? null) !== null) {
                $counters[] = $this->counter('platform:' . $limits['bucket'] . ':' . $window, (int) $limits['platform'][$window], $decay, 'platform_limit', 'platform', $window);
            }
        }

        return $counters;
    }

    /**
     * Which pauses stop this send. Trusted purposes (2FA, admin actions) answer only to a
     * platform pause: a pumped tenant must not lock its own staff out of the console.
     */
    protected function pauseScopes(SmsContext $context, array $limits): array
    {
        $scopes = [SmsBreaker::SCOPE_PLATFORM];

        if ($limits['trusted']) {
            return $scopes;
        }

        if ($context->companyScope()) {
            $scopes[] = $context->companyScope();
        }

        if ($context->tenantScope()) {
            $scopes[] = $context->tenantScope();
        }

        return $scopes;
    }

    /**
     * Log a refusal and, unless monitoring, throw it. Returns the reason when monitoring.
     */
    protected function refuse(SmsContext $context, SmsBlockedException $exception, bool $monitor): string
    {
        $entry = array_merge($context->toArray(), ['reason' => $exception->reason(), 'status' => $exception->status(), 'retry_after' => $exception->retryAfter()]);

        if ($monitor) {
            Log::warning('sms.would_block', $entry);

            return $exception->reason();
        }

        Log::warning('sms.blocked', $entry);
        SmsLedger::record($context, SmsSend::STATUS_BLOCKED, ['block_reason' => $exception->reason()]);

        throw $exception;
    }

    protected function counter(string $key, int $max, int $decay, string $reason, string $scope, string $window): array
    {
        return ['key' => self::CACHE_PREFIX . $key, 'max' => $max, 'decay' => $decay, 'reason' => $reason, 'scope' => $scope, 'window' => $window];
    }

    /**
     * The cache store the counters live in. Must be shared by every worker and task (Redis
     * in production); the configured `sms.guard.cache_store` is used when it resolves.
     */
    protected function cache(): CacheRepository
    {
        if ($this->cache) {
            return $this->cache;
        }

        $cache = app('cache');
        $store = config('sms.guard.cache_store');

        if (is_object($cache) && method_exists($cache, 'store')) {
            if (filled($store)) {
                try {
                    return $this->cache = $cache->store($store);
                } catch (\Throwable $e) {
                    Log::warning('sms.guard_cache_store_unavailable', ['store' => $store, 'error' => $e->getMessage()]);
                }
            }

            return $this->cache = $cache->store();
        }

        return $this->cache = $cache;
    }
}
