<?php

namespace Fleetbase\Push;

/**
 * Normalized per-token delivery outcome shared by the FCM and APNs transports.
 */
final class PushOutcome
{
    /** Accepted by the provider. */
    public const SENT = 'sent';

    /** Token is no longer valid (app uninstalled, token rotated, garbage token): prune it. */
    public const DEAD = 'dead';

    /** Token belongs to a different app/project than the channel (SenderId mismatch, DeviceTokenNotForTopic): try another channel. */
    public const WRONG_APP = 'wrong_app';

    /** APNs token was issued for the other environment (BadDeviceToken): try the other environment. */
    public const WRONG_ENVIRONMENT = 'wrong_environment';

    /** Anything else (credentials, quota, provider outage): logged, token kept. */
    public const ERROR = 'error';

    public function __construct(public string $status, public ?string $reason = null)
    {
    }

    public static function sent(): self
    {
        return new self(self::SENT);
    }

    public static function dead(?string $reason = null): self
    {
        return new self(self::DEAD, $reason);
    }

    public static function wrongApp(?string $reason = null): self
    {
        return new self(self::WRONG_APP, $reason);
    }

    public static function wrongEnvironment(?string $reason = null): self
    {
        return new self(self::WRONG_ENVIRONMENT, $reason);
    }

    public static function error(?string $reason = null): self
    {
        return new self(self::ERROR, $reason);
    }

    public function is(string $status): bool
    {
        return $this->status === $status;
    }
}
