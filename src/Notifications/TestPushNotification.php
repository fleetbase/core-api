<?php

namespace Fleetbase\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidFcmOptions;
use NotificationChannels\Fcm\Resources\AndroidNotification;
use NotificationChannels\Fcm\Resources\ApnsConfig;
use NotificationChannels\Fcm\Resources\ApnsFcmOptions;

/**
 * Class TestPushNotification
 *
 * A test push notification class for sending notifications via FCM and APN channels.
 *
 * @package Fleetbase\Notifications
 */
class TestPushNotification extends Notification
{
    use Queueable;

    /**
     * The title of the notification.
     *
     * @var string
     */
    public string $title;

    /**
     * The message body of the notification.
     *
     * @var string
     */
    public string $message;

    /**
     * Additional data to be sent with the notification.
     *
     * @var array
     */
    public array $data = [];

    /**
     * TestPushNotification constructor.
     *
     * @param string $title   The title of the notification.
     * @param string $message The message body of the notification.
     */
    public function __construct(string $title, string $message)
    {
        $this->title = $title;
        $this->message = $message;
        $this->data = [
            'id' => uniqid(),
            'message' => 'Test Push Notification',
            'type' => 'test',
            'date' => Carbon::now()->toDateTimeString()
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
     * @param  mixed  $notifiable
     * @return array
     */
    public function toFcm($notifiable)
    {
        $notification = \NotificationChannels\Fcm\Resources\Notification::create()
            ->setTitle($this->title)
            ->setBody($this->message);

        $message = FcmMessage::create()
            ->setData($this->data)
            ->setNotification($notification)
            ->setAndroid(
                AndroidConfig::create()
                    ->setFcmOptions(AndroidFcmOptions::create()->setAnalyticsLabel('analytics'))
                    ->setNotification(AndroidNotification::create()->setColor('#4391EA'))
            )->setApns(
                ApnsConfig::create()
                    ->setFcmOptions(ApnsFcmOptions::create()->setAnalyticsLabel('analytics_ios'))
            );

        return $message;
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toApn($notifiable)
    {
        $message = ApnMessage::create()
            ->badge(1)
            ->title($this->title)
            ->body($this->message);

        foreach ($this->data as $key => $value) {
            $message->custom($key, $value);
        }

        return $message;
    }
}
