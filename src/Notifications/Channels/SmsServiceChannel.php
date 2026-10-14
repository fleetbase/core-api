<?php

namespace Fleetbase\Notifications\Channels;

use Fleetbase\Services\SmsService;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification's text message through SmsService, so it passes the SMS guard
 * and lands on the ledger like every other send, instead of going straight to the Twilio
 * notification channel.
 *
 * A notification using it implements `toSms($notifiable)` (returning the message text, or
 * an object with a `content` property such as a TwilioSmsMessage) and may declare a
 * `smsPurpose` property for the guard; the notifiable supplies the number through
 * `routeNotificationFor('sms')`, falling back to `routeNotificationFor('twilio')` and a
 * `phone` attribute.
 */
class SmsServiceChannel
{
    public function __construct(protected ?SmsService $service = null)
    {
    }

    public function send($notifiable, Notification $notification): void
    {
        $to = $this->destinationFor($notifiable, $notification);
        if (blank($to)) {
            return;
        }

        $message = method_exists($notification, 'toSms') ? $notification->toSms($notifiable) : null;
        $text    = is_object($message) ? (string) ($message->content ?? '') : (string) $message;
        if ($text === '') {
            return;
        }

        $options = [
            'purpose'      => $notification->smsPurpose ?? 'notification:' . class_basename($notification),
            'company_uuid' => $notification->smsCompanyUuid ?? data_get($notifiable, 'company_uuid'),
        ];

        ($this->service ?? app(SmsService::class))->send((string) $to, $text, $options);
    }

    protected function destinationFor($notifiable, Notification $notification): ?string
    {
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            foreach (['sms', 'twilio'] as $driver) {
                $to = $notifiable->routeNotificationFor($driver, $notification);
                if (filled($to)) {
                    return (string) $to;
                }
            }
        }

        $phone = data_get($notifiable, 'phone');

        return filled($phone) ? (string) $phone : null;
    }
}
