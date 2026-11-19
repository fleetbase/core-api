<?php

namespace Fleetbase\Push\Transports;

use Fleetbase\Push\Contracts\PushProvider;
use Fleetbase\Push\FirebaseMessagingFactory;
use Fleetbase\Push\PushConfigurationException;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\SendReport;

/**
 * Firebase Cloud Messaging (HTTP v1): sends to Android devices, and to iOS apps that get their
 * push token from the Firebase SDK, with a Firebase project's service account. Settings:
 * `credentials` (the service account JSON) and optionally `android_channel_id`.
 *
 * No package name or bundle ID is needed: the token says which of the project's apps it
 * belongs to.
 */
class FcmTransport implements PushProvider
{
    public function __construct(protected FirebaseMessagingFactory $factory)
    {
    }

    public function platforms(): array
    {
        return ['android'];
    }

    /**
     * FCM registration tokens are `<instance id>:<token>` in URL-safe characters; APNs tokens
     * are hex, without a colon.
     */
    public function recognizes(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+:[A-Za-z0-9_-]+$/', $token);
    }

    public function secrets(): array
    {
        return ['credentials'];
    }

    public function configure(array $input, ?array $saved = null): ?array
    {
        $credentials = $input['credentials'] ?? null;
        $channel     = trim((string) ($input['android_channel_id'] ?? ''));
        if (empty($credentials)) {
            if (empty($saved['credentials'])) {
                if ($channel === '') {
                    return null;
                }

                throw new PushConfigurationException('Add the Firebase service account JSON, or clear the notification channel.');
            }

            return array_filter(['credentials' => null, 'android_channel_id' => $channel ?: null]);
        }

        $text    = is_string($credentials) ? trim($credentials) : null;
        $account = $text !== null ? json_decode($text, true) : $credentials;
        if ($text !== null && (str_starts_with($text, '<') || str_contains($text, '<plist'))) {
            throw new PushConfigurationException('That looks like GoogleService-Info.plist, the file your iOS app is built with. Paste the service account key instead: Firebase console → Project settings → Service accounts → Generate new private key.');
        }
        if (is_array($account) && isset($account['project_info'], $account['client'])) {
            throw new PushConfigurationException('That looks like google-services.json, the file your Android app is built with. Paste the service account key instead: Firebase console → Project settings → Service accounts → Generate new private key.');
        }
        if (!is_array($account) || empty($account['project_id']) || empty($account['client_email']) || empty($account['private_key'])) {
            throw new PushConfigurationException('Paste the whole service account JSON file from Firebase. It has project_id, client_email and private_key in it.');
        }

        return array_filter(['credentials' => json_encode($account), 'android_channel_id' => $channel ?: null]);
    }

    public function summary(array $config): array
    {
        $account = $config['credentials'] ?? null;
        $account = is_string($account) ? json_decode($account, true) : $account;

        return [
            'project_id'         => data_get($account, 'project_id'),
            'client_email'       => data_get($account, 'client_email'),
            'android_channel_id' => $config['android_channel_id'] ?? null,
            'has_credentials'    => !empty($config['credentials']),
        ];
    }

    public function environments(PushCredentials $credentials, ?string $deviceEnvironment = null): array
    {
        return [null];
    }

    public function send(PushCredentials $credentials, PushMessage $message, array $tokens, ?string $environment = null): array
    {
        if (empty($tokens)) {
            return [];
        }

        $payload = $message->toFcmArray([
            'android_channel_id' => $credentials->config['android_channel_id'] ?? null,
            'android_color'      => $credentials->config['android_color'] ?? null,
        ]);

        $report   = $this->factory->make($credentials)->sendMulticast(CloudMessage::fromArray($payload), array_values($tokens));
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
