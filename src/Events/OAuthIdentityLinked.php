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
 * Fired on first link only, not on every sign-in. Core registers no listener; this exists so
 * downstream packages (Fleetbase Cloud internals, analytics) can react without core-api
 * needing to know they are installed.
 */
class OAuthIdentityLinked
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $user;
    public $identity;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(User $user, OAuthIdentity $identity)
    {
        $this->user     = $user;
        $this->identity = $identity;
    }
}
