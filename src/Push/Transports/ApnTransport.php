<?php

namespace Fleetbase\Push\Transports;

use Fleetbase\Push\ApnClientFactory;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;
use Pushok\Notification;
use Pushok\Response;

/**
 * Sends push messages to iOS devices through APNs (token based .p8 auth).
 */
class ApnTransport
{
    public function __construct(protected ApnClientFactory $factory)
    {
    }

    /**
     * @param string[] $tokens
     *
     * @return array<string, PushOutcome> keyed by token
     */
    public function send(PushCredentials $channel, string $environment, PushMessage $message, array $tokens): array
    {
        if (empty($tokens)) {
            return [];
        }

        $client  = $this->factory->make($channel, $environment);
        $payload = $message->toApnPayload();

        foreach ($tokens as $token) {
            $notification = new Notification($payload, $token);
            $notification->setHighPriority();
            if ($message->collapseKey) {
                $notification->setCollapseId(substr($message->collapseKey, 0, 64));
            }
            $client->addNotification($notification);
        }

        $outcomes = [];
        foreach ($client->push() as $response) {
            $outcomes[$response->getDeviceToken()] = static::outcomeFor($response);
        }

        return $outcomes;
    }

    public static function outcomeFor(Response $response): PushOutcome
    {
        $status = $response->getStatusCode();
        if ($status === 200) {
            return PushOutcome::sent();
        }

        $reason = $response->getErrorReason();

        return match (true) {
            $status === 410, $reason === 'Unregistered', $reason === 'ExpiredToken' => PushOutcome::dead($reason),
            $reason === 'BadDeviceToken'                                            => PushOutcome::wrongEnvironment($reason),
            $reason === 'DeviceTokenNotForTopic', $reason === 'TopicDisallowed'     => PushOutcome::wrongApp($reason),
            default                                                                 => PushOutcome::error($status . ' ' . $reason),
        };
    }
}
