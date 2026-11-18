<?php

namespace Fleetbase\Push\Contracts;

use Fleetbase\Push\PushMessage;

/**
 * Implemented by notifications delivered through the PushAppChannel.
 */
interface SendsToPushApps
{
    /**
     * The push payload for the notifiable, or null to send none.
     */
    public function toPushApp($notifiable): ?PushMessage;
}
