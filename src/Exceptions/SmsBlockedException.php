<?php

namespace Fleetbase\Exceptions;

use Fleetbase\Support\Sms\PhoneValidator;

/**
 * The SMS guard refused a send.
 *
 * Carries the HTTP status a controller should answer with: 422 for a destination that
 * can never be texted, 429 with a Retry-After for a cooldown or budget, 423 while a
 * tenant or the platform is paused, 503 when the guard's counters are unreachable and the
 * send had to fail closed. Every sender already wraps its SMS attempt in a try/catch, so a
 * refusal falls into the same email fallback or error response as a provider failure.
 */
class SmsBlockedException extends \RuntimeException
{
    public const REASON_UNAVAILABLE = 'guard_unavailable';
    public const REASON_PAUSED      = 'paused';

    public function __construct(
        protected string $reason,
        string $message,
        protected int $status = 429,
        protected ?int $retryAfter = null,
        protected array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidDestination(string $reason, array $context = []): static
    {
        return new static($reason, PhoneValidator::messageFor($reason), 422, null, $context);
    }

    public static function limited(string $reason, int $retryAfter, array $context = []): static
    {
        return new static($reason, 'Unable to send a verification code right now. Try again in a minute.', 429, max(1, $retryAfter), $context);
    }

    public static function paused(string $scope, ?int $retryAfter, array $context = []): static
    {
        return new static(self::REASON_PAUSED, 'Text messages are temporarily unavailable. Try again later.', 423, $retryAfter, array_merge($context, ['scope' => $scope]));
    }

    public static function unavailable(array $context = []): static
    {
        return new static(self::REASON_UNAVAILABLE, 'Text messages are temporarily unavailable. Try again later.', 503, 60, $context);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function context(): array
    {
        return $this->context;
    }

    /**
     * Headers for the HTTP response: Retry-After when there is a time to wait.
     */
    public function headers(): array
    {
        return $this->retryAfter !== null ? ['Retry-After' => (string) $this->retryAfter] : [];
    }
}
