<?php

namespace Fleetbase\Push\Transports;

use Fleetbase\Push\FirebaseMessagingFactory;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\SendReport;

/**
 * Sends push messages to Android devices through FCM HTTP v1.
 */
class FcmTransport
{
    public function __construct(protected FirebaseMessagingFactory $factory)
    {
    }

    /**
     * @param string[] $tokens
     *
     * @return array<string, PushOutcome> keyed by token
     */
    public function send(PushCredentials $channel, PushMessage $message, array $tokens): array
    {
        if (empty($tokens)) {
            return [];
        }

        $payload = $message->toFcmArray([
            'android_channel_id' => $channel->config['android_channel_id'] ?? null,
            'android_color'      => $channel->config['android_color'] ?? null,
        ]);

        $report   = $this->factory->make($channel)->sendMulticast(CloudMessage::fromArray($payload), array_values($tokens));
        $outcomes = [];

        foreach ($report->getItems() as $item) {
            $outcomes[$item->target()->value()] = static::outcomeFor($item);
        }

        return $outcomes;
    }

    public static function outcomeFor(SendReport $report): PushOutcome
    {
        if ($report->isSuccess()) {
            return PushOutcome::sent();
        }

        $error  = $report->error();
        $reason = $error?->getMessage();

        // 403 SENDER_ID_MISMATCH: token was issued for a different Firebase project.
        if ($error instanceof AuthenticationError && stripos((string) $reason, 'mismatch') !== false) {
            return PushOutcome::wrongApp($reason);
        }

        // 404 UNREGISTERED or 400 INVALID_ARGUMENT for the registration token.
        if ($error instanceof NotFound || $report->messageWasSentToUnknownToken() || $report->messageTargetWasInvalid()) {
            return PushOutcome::dead($reason);
        }

        if ($error instanceof InvalidArgument && stripos((string) $reason, 'registration') !== false) {
            return PushOutcome::dead($reason);
        }

        return PushOutcome::error($reason);
    }
}
