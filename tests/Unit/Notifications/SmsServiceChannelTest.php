<?php

use Fleetbase\Notifications\Channels\SmsServiceChannel;
use Fleetbase\Services\SmsService;
use Illuminate\Notifications\Notification;

class SmsServiceChannelTestService extends SmsService
{
    public array $sent = [];

    public function send(string $to, string $text, array $options = [], ?string $provider = null): array
    {
        $this->sent[] = compact('to', 'text', 'options');

        return ['success' => true];
    }
}

class SmsServiceChannelTestNotification extends Notification
{
    public string $smsPurpose = 'storefront_order_alert';

    public function __construct(public mixed $message)
    {
    }

    public function toSms($notifiable): mixed
    {
        return $this->message;
    }
}

function sms_service_channel_service(): SmsServiceChannelTestService
{
    bind_test_container(['sms.default_provider' => 'twilio']);

    return new SmsServiceChannelTestService();
}

test('sms service channel sends a notification text through SmsService with its purpose', function () {
    $service = sms_service_channel_service();
    $channel = new SmsServiceChannel($service);

    $notifiable = new class {
        public ?string $company_uuid = 'company-1';

        public function routeNotificationFor(string $driver, $notification = null): ?string
        {
            return $driver === 'twilio' ? '+447400100123' : null;
        }
    };

    $channel->send($notifiable, new SmsServiceChannelTestNotification('New order!'));
    $channel->send($notifiable, new SmsServiceChannelTestNotification((object) ['content' => 'From an object']));

    expect($service->sent)->toHaveCount(2)
        ->and($service->sent[0])->toBe(['to' => '+447400100123', 'text' => 'New order!', 'options' => ['purpose' => 'storefront_order_alert', 'company_uuid' => 'company-1']])
        ->and($service->sent[1]['text'])->toBe('From an object');
});

test('sms service channel falls back to a phone attribute and sends nothing without a number or text', function () {
    $service = sms_service_channel_service();
    $channel = new SmsServiceChannel($service);

    $withPhone = new class {
        public string $phone = '+447400100124';
    };
    $silent = new class {
        public ?string $phone = null;
    };

    $channel->send($withPhone, new SmsServiceChannelTestNotification('Hello'));
    $channel->send($silent, new SmsServiceChannelTestNotification('Hello'));
    $channel->send($withPhone, new SmsServiceChannelTestNotification(''));
    $channel->send($withPhone, new class extends Notification {
    });

    expect($service->sent)->toHaveCount(1)
        ->and($service->sent[0]['to'])->toBe('+447400100124')
        ->and($service->sent[0]['options']['company_uuid'])->toBeNull();
});
