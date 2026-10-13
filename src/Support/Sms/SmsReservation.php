<?php

namespace Fleetbase\Support\Sms;

/**
 * The guard's answer for one send: go ahead, and under which conditions.
 *
 * A reservation is created by {@see SmsGuard::reserve()} before any row is written or
 * provider called, travels with the send (VerificationCode hands it to SmsService in the
 * options so the send is not counted twice) and is settled with the outcome afterwards.
 */
final class SmsReservation
{
    /** every check passed */
    public const DECISION_ALLOWED = 'allowed';

    /** a check failed but the guard is in monitor mode, so the send went ahead */
    public const DECISION_MONITOR = 'monitor';

    /** the guard is disabled */
    public const DECISION_BYPASSED = 'bypassed';

    /** the counter store was unreachable; a trusted or internal send was let through */
    public const DECISION_DEGRADED = 'degraded';

    public bool $settled = false;

    /** the ledger row for this send, when the ledger could be written */
    public ?string $sendUuid = null;

    public function __construct(
        public readonly SmsContext $context,
        public readonly string $decision = self::DECISION_ALLOWED,
        public readonly ?string $wouldBlock = null,
        public readonly ?string $country = null,
        public readonly ?string $type = null,
    ) {
    }

    /**
     * Whether the guard looked at this send at all.
     */
    public function isGuarded(): bool
    {
        return $this->decision !== self::DECISION_BYPASSED;
    }

    /**
     * What a verification code row keeps about the send, so an incident can be traced
     * from the database: who it was for, where it came from and what the guard decided.
     */
    public function trace(): array
    {
        if (!$this->isGuarded()) {
            return [];
        }

        return array_filter([
            'company_uuid'        => $this->context->companyUuid,
            'tenant_type'         => $this->context->tenantType,
            'tenant_uuid'         => $this->context->tenantUuid,
            'source'              => $this->context->source,
            'ip'                  => $this->context->ip,
            'user_agent'          => $this->context->userAgent,
            'destination_country' => $this->country,
            'decision'            => $this->decision,
            'would_block'         => $this->wouldBlock,
            'sms_send_uuid'       => $this->sendUuid,
        ], fn ($value) => $value !== null);
    }

    public function toArray(): array
    {
        return array_merge($this->context->toArray(), [
            'decision'            => $this->decision,
            'would_block'         => $this->wouldBlock,
            'destination_country' => $this->country,
            'destination_type'    => $this->type,
            'sms_send_uuid'       => $this->sendUuid,
        ]);
    }
}
