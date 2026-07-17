<?php

use Fleetbase\Events\BroadcastNotificationCreated as FleetbaseBroadcastNotificationCreated;
use Fleetbase\Listeners\TriggerPublicNotificationBroadcast;
use Illuminate\Notifications\Events\BroadcastNotificationCreated as LaravelBroadcastNotificationCreated;
use Illuminate\Notifications\Notification;

if (!function_exists('event')) {
    function event(mixed $event = null): mixed
    {
        $GLOBALS['trigger_public_notification_broadcast_events'][] = $event;

        return $event;
    }
}

class TriggerPublicNotificationBroadcastNotification extends Notification
{
    public $id = 'notification-listener-1';
}

class TriggerPublicNotificationBroadcastNotifiable
{
    public string $uuid = 'notifiable-user-1';
}

beforeEach(function () {
    $GLOBALS['trigger_public_notification_broadcast_events'] = [];
});

afterEach(function () {
    $GLOBALS['trigger_public_notification_broadcast_events'] = [];
});

it('re-dispatches Laravel broadcast notifications as Fleetbase public broadcast events', function () {
    $notifiable   = new TriggerPublicNotificationBroadcastNotifiable();
    $notification = new TriggerPublicNotificationBroadcastNotification();
    $data         = ['title' => 'Route updated', 'message' => 'Driver assigned'];
    $event        = new LaravelBroadcastNotificationCreated($notifiable, $notification, $data);

    (new TriggerPublicNotificationBroadcast())->handle($event);

    expect($GLOBALS['trigger_public_notification_broadcast_events'])->toHaveCount(1)
        ->and($GLOBALS['trigger_public_notification_broadcast_events'][0])->toBeInstanceOf(FleetbaseBroadcastNotificationCreated::class)
        ->and($GLOBALS['trigger_public_notification_broadcast_events'][0]->notifiable)->toBe($notifiable)
        ->and($GLOBALS['trigger_public_notification_broadcast_events'][0]->notification)->toBe($notification)
        ->and($GLOBALS['trigger_public_notification_broadcast_events'][0]->data)->toBe($data);
});
