<?php

namespace Fleetbase\Notifications;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Class TestPushNotification.
 *
 * A test push notification class for sending notifications via FCM and APN channels.
 */
class TestPushNotification extends Notification
{
    use Queueable;

    /**
     * The title of the notification.
     */
    public string $title;

    /**
     * The message body of the notification.
     */
    public string $message;

    /**
     * Additional data to be sent with the notification.
     */
    public array $data = [];

    /**
     * TestPushNotification constructor.
     *
     * @param string $title   the title of the notification
     * @param string $message the message body of the notification
     */
    public function __construct(string $title, string $message)
    {
        $this->title   = $title;
        $this->message = $message;
        $this->data    = [
            'id'      => uniqid(),
            'message' => 'Test Push Notification',
            'type'    => 'test',
            'date'    => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via()
    {
        return [FcmChannel::class, ApnChannel::class];
    }

    /**
     * Get the firebase cloud message representation of the notification.
     *
     * @return array
     */
    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @return array
     */
    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'test_push_notification');
    }
}
