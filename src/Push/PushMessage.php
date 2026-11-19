<?php

namespace Fleetbase\Push;

/**
 * Provider-agnostic push notification payload.
 *
 * Notifications build a PushMessage and the PushAppChannel renders it
 * into the FCM (Android) or APNs (iOS) wire format for each device.
 */
class PushMessage
{
    public string $title;
    public string $body;
    public array $data             = [];
    public ?int $badge             = 1;
    public ?string $sound          = 'default';
    public ?string $image          = null;
    public ?string $category       = null;
    public ?string $collapseKey    = null;
    public ?string $analyticsLabel = null;

    public function __construct(string $title, string $body, array $data = [])
    {
        $this->title = $title;
        $this->body  = $body;
        $this->data  = $data;
    }

    public static function create(string $title, string $body, array $data = []): self
    {
        return new static($title, $body, $data);
    }

    public function data(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function badge(?int $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    public function sound(?string $sound): self
    {
        $this->sound = $sound;

        return $this;
    }

    public function image(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function category(?string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function collapseKey(?string $collapseKey): self
    {
        $this->collapseKey = $collapseKey;

        return $this;
    }

    public function analyticsLabel(?string $analyticsLabel): self
    {
        $this->analyticsLabel = $analyticsLabel;

        return $this;
    }

    /**
     * FCM requires every data value to be a string.
     */
    public function stringData(): array
    {
        $data = [];
        foreach ($this->data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $data[(string) $key] = is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : json_encode($value);
        }

        return $data;
    }

    /**
     * Render the FCM HTTP v1 message body (without a target).
     */
    public function toFcmArray(array $options = []): array
    {
        $androidChannelId = $options['android_channel_id'] ?? null;
        $color            = $options['android_color'] ?? '#4391EA';

        $notification = array_filter([
            'title' => $this->title,
            'body'  => $this->body,
            'image' => $this->image,
        ], fn ($value) => $value !== null);

        $androidNotification = array_filter([
            'channel_id'   => $androidChannelId,
            'sound'        => $this->sound,
            'color'        => $color,
            'click_action' => $this->category,
            'tag'          => $this->collapseKey,
        ], fn ($value) => $value !== null && $value !== '');

        $aps = array_filter([
            'sound'    => $this->sound,
            'badge'    => $this->badge,
            'category' => $this->category,
        ], fn ($value) => $value !== null);

        $message = [
            'notification' => $notification,
            'data'         => $this->stringData(),
            'android'      => array_filter([
                'priority'     => 'high',
                'collapse_key' => $this->collapseKey,
                'notification' => $androidNotification,
            ], fn ($value) => $value !== null && $value !== []),
            'apns' => [
                'headers' => ['apns-priority' => '10', 'apns-push-type' => 'alert'],
                'payload' => ['aps' => $aps],
            ],
        ];

        if ($this->analyticsLabel) {
            $message['fcm_options'] = ['analytics_label' => $this->analyticsLabel];
        }

        if (empty($message['data'])) {
            unset($message['data']);
        }

        return $message;
    }

    /**
     * Render the APNs payload for pushok.
     */
    public function toApnPayload(): \Pushok\Payload
    {
        $alert = \Pushok\Payload\Alert::create()
            ->setTitle($this->title)
            ->setBody($this->body);

        $payload = \Pushok\Payload::create()
            ->setAlert($alert)
            ->setPushType('alert');

        if ($this->sound !== null) {
            $payload->setSound($this->sound);
        }

        if ($this->badge !== null) {
            $payload->setBadge($this->badge);
        }

        if ($this->category !== null) {
            $payload->setCategory($this->category);
        }

        if ($this->image !== null) {
            $payload->setMutableContent(true);
        }

        foreach ($this->data as $key => $value) {
            if ($value === null || $key === 'aps') {
                continue;
            }

            $payload->setCustomValue((string) $key, $value);
        }

        return $payload;
    }
}
