<?php

namespace Fleetbase\Events;

use Fleetbase\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An external OAuth identity was removed from a Fleetbase user.
 *
 * Carries the provider id rather than the model: the row is already gone by the time this
 * fires, because identities are hard-deleted.
 */
class OAuthIdentityUnlinked
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $user;
    public $provider;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(User $user, string $provider)
    {
        $this->user     = $user;
        $this->provider = $provider;
    }
}
