<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Support\PushNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

class ChatMessageReceived extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The chat message received.
     *
     * @var ChatMessage
     */
    public $chatMessage;

    /**
     * The chat participant receiving the notification.
     *
     * @var ChatParticipant
     */
    public $chatRecipient;

    /**
     * Notification name.
     */
    public static string $name = 'New Chat Message Received';

    /**
     * Notification description.
     */
    public static string $description = 'Notify when an new chat message has been received.';

    /**
     * Notification package.
     */
    public static string $package = 'core';

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
     * The user receiving the notification.
     *
     * @var \Fleetbase\Models\User
     */
    public $notifiable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(ChatMessage $chatMessage, ChatParticipant $chatRecipient)
    {
        $chatMessage->loadMissing(['sender', 'chatChannel']);

        $this->chatMessage     = $chatMessage;
        $this->chatRecipient   = $chatRecipient;
        $this->title           = 'Message from ' . $chatMessage->sender->user->name;
        $this->message         = $chatMessage->content;
        $this->data            = [
            'id'        => $chatMessage->public_id,
            'type'      => 'chat_message_received',
            'sender'    => $chatMessage->sender->public_id,
            'recipient' => $chatRecipient->public_id,
            'channel'   => $chatMessage->chatChannel->public_id,
        ];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast', FcmChannel::class, ApnChannel::class];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return [
            new Channel('chat_channel.' . $this->chatMessage->chatChannel->uuid),
            new Channel('chat_channel.' . $this->chatMessage->chatChannel->public_id),
            new Channel('chat_participant.' . $this->chatRecipient->uuid),
            new Channel('chat_participant.' . $this->chatRecipient->public_id),
        ];
    }

    /**
     * Get notification as array.
     *
     * @return void
     */
    public function toArray()
    {
        return [
            'title' => $this->title,
            'body'  => $this->message,
            'event' => 'chat_participent.chat_message_received',
            'data'  => [
                ...$this->data,
                'message' => [
                    'sender'    => $this->chatMessage->sender->public_id,
                    'recipient' => $this->chatRecipient->public_id,
                    'channel'   => $this->chatMessage->chatChannel->public_id,
                    'content'   => $this->chatMessage->content,
                    'sent_at'   => $this->chatMessage->created_at,
                ],
            ],
        ];
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
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'chat_message_received');
    }
}
