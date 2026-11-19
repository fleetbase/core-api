<?php

namespace Fleetbase\Push\Concerns;

use Fleetbase\Push\PushMessage;

/**
 * `toPushApp()` for a notification that already has `$title`, `$message` and `$data` for its
 * APNs and FCM pushes. Add `PushAppChannel::class` to its `via()` and set `$pushCategory` if
 * the iOS category isn't `view_order`.
 */
trait PushesToApps
{
    public function toPushApp($notifiable): ?PushMessage
    {
        $title = (string) ($this->title ?? '');
        $body  = (string) ($this->message ?? '');
        if ($title === '' && $body === '') {
            return null;
        }

        return PushMessage::create($title, $body, (array) ($this->data ?? []))->category($this->pushCategory ?? 'view_order');
    }
}
