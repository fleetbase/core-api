<?php

namespace Fleetbase\Events;

use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An external OAuth identity was linked to a Fleetbase user.
 *
 * Fired on first link only, not on every sign-in. Core emails the account holder about it
 * (SendOAuthIdentityLinkedNotification); downstream packages can listen too.
 */
class OAuthIdentityLinked
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** The user linked it from their account page. */
    public const METHOD_MANUAL = 'manual';

    /** Linked on sign-in because the provider's verified email matched the account. */
    public const METHOD_AUTOMATIC = 'automatic';

    /** Linked while creating the account with this provider. */
    public const METHOD_SIGNUP = 'signup';

    public $user;
    public $identity;
    public $method;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(User $user, OAuthIdentity $identity, string $method = self::METHOD_MANUAL)
    {
        $this->user     = $user;
        $this->identity = $identity;
        $this->method   = $method;
    }
}
