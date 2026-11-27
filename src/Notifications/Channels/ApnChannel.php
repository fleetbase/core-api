<?php

namespace Fleetbase\Notifications\Channels;

use Fleetbase\Models\UserDevice;
use Fleetbase\Support\PushNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel as BaseApnChannel;
use Pushok\Response;

/**
 * Apple pushes, sent to each device through the service its token belongs to.
 *
 * Apple runs two push services. A token issued to a development build (one run
 * from Xcode) only works on the sandbox service; a token from TestFlight or the
 * App Store only on production. The install is configured for one of them, so
 * without this a developer's phone and a customer's phone could never both be
 * reached from the same server. A device says which it is when it registers
 * (`environment`); one that does not say is sent through the configured service.
 *
 * A token Apple says is no longer valid is marked so it is not tried again.
 */
class ApnChannel extends BaseApnChannel
{
    public const SANDBOX_ENVIRONMENTS = ['development', 'sandbox', 'debug'];
    public const DEAD_TOKEN_REASONS   = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    public function send(mixed $notifiable, Notification $notification): ?array
    {
        $tokens = (array) $notifiable->routeNotificationFor('apn', $notification);
        if (empty($tokens)) {
            return null;
        }

        $message   = $notification->toApn($notifiable);
        $sandbox   = static::sandboxTokens($tokens);
        $groups    = [
            [array_values(array_diff($tokens, $sandbox)), null],
            [array_values(array_intersect($tokens, $sandbox)), false],
        ];
        $responses = [];

        foreach ($groups as [$group, $production]) {
            if (!$group) {
                continue;
            }

            try {
                // The configured service for most; Apple's sandbox for devices that registered as development builds.
                $client    = $production === null ? ($message->client ?? $this->factory->instance()) : PushNotification::getApnClient($production);
                $responses = array_merge($responses, $this->sendNotifications($client, $message, $group));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->dispatchEvents($notifiable, $notification, $responses);
        static::retireDeadTokens($responses);

        return $responses;
    }

    /** The tokens among these that belong to development builds. */
    protected static function sandboxTokens(array $tokens): array
    {
        try {
            return UserDevice::whereIn('token', $tokens)->whereIn('environment', static::SANDBOX_ENVIRONMENTS)->pluck('token')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function retireDeadTokens(array $responses): void
    {
        $dead = [];
        foreach ($responses as $response) {
            if ($response instanceof Response && $response->getStatusCode() !== Response::APNS_SUCCESS && in_array($response->getErrorReason(), static::DEAD_TOKEN_REASONS, true)) {
                $dead[] = $response->getDeviceToken();
            }
        }

        if ($dead) {
            try {
                UserDevice::whereIn('token', $dead)->update(['status' => 'invalid']);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
