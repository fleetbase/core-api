<?php

namespace Fleetbase\Support\SocketCluster;

/**
 * The outcome of a channel subscription check, with how long it may be cached.
 */
final class ChannelDecision
{
    public const ALLOW_TTL = 300;

    public const DENY_TTL = 30;

    public function __construct(
        public readonly bool $allow,
        public readonly int $ttl,
        public readonly string $reason,
    ) {
    }

    public static function allowed(string $reason, int $ttl = self::ALLOW_TTL): self
    {
        return new self(true, $ttl, $reason);
    }

    public static function denied(string $reason, int $ttl = self::DENY_TTL): self
    {
        return new self(false, $ttl, $reason);
    }

    public static function fromArray(array $decision): self
    {
        return new self((bool) ($decision['allow'] ?? false), (int) ($decision['ttl'] ?? self::DENY_TTL), (string) ($decision['reason'] ?? 'denied'));
    }

    /**
     * A copy whose cache lifetime never outlives the given number of seconds.
     */
    public function capTtl(int $seconds): self
    {
        return new self($this->allow, max(1, min($this->ttl, $seconds)), $this->reason);
    }

    public function toArray(): array
    {
        return [
            'allow'  => $this->allow,
            'ttl'    => $this->ttl,
            'reason' => $this->reason,
        ];
    }
}
