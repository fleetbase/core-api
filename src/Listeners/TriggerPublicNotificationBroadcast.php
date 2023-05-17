<?php

namespace Fleetbase\Listeners;

use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Fleetbase\Events\BroadcastNotificationCreated as InternalBroadcastNotificationCreated;

class TriggerPublicNotificationBroadcast
{
    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(BroadcastNotificationCreated $event)
    {
        event(new InternalBroadcastNotificationCreated($event->notifiable, $event->notification, $event->data));
    }
}
