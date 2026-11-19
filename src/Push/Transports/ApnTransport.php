<?php

namespace Fleetbase\Push\Transports;

use Fleetbase\Push\ApnClientFactory;
use Fleetbase\Push\Contracts\PushProvider;
use Fleetbase\Push\PushConfigurationException;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;
use Pushok\Notification;
use Pushok\Response;

/**
 * Apple Push Notification service: sends to iOS devices with an app's .p8 key (token based
 * auth). Settings: `key_id`, `team_id`, `app_bundle_id` (the iOS app's bundle ID), the .p8
 * file as `private_key_content`, and optionally `environment`.
 */
class ApnTransport implements PushProvider
{
    public const FIELDS = ['key_id', 'team_id', 'app_bundle_id', 'private_key_content', 'environment'];

    public function __construct(protected ApnClientFactory $factory)
    {
    }

    public function platforms(): array
    {
        return ['ios'];
    }

    /**
     * APNs tokens don't stand out from other tokens, so iOS devices use APNs unless their
     * token is recognized as another provider's.
     */
    public function recognizes(string $token): bool
    {
        return false;
    }

    public function secrets(): array
    {
        return ['private_key_content'];
    }

    public function configure(array $input, ?array $saved = null): ?array
    {
        $config = [];
        foreach (self::FIELDS as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $config[$field] = trim($value);
            }
        }
        if (empty($config)) {
            return null;
        }

        if (empty($config['key_id'])) {
            throw new PushConfigurationException('Enter the Key ID of your Apple push key, or clear the Apple settings if the app doesn’t send pushes through Apple.');
        }
        if (!preg_match('/^[A-Z0-9]{10}$/i', $config['key_id'])) {
            throw new PushConfigurationException('The Key ID should be the 10 letters and numbers shown next to the key in your Apple Developer account, like ABC123DEFG.');
        }
        if (empty($config['team_id'])) {
            throw new PushConfigurationException('Enter your Apple Team ID. It’s under Membership details in your Apple Developer account.');
        }
        if (!preg_match('/^[A-Z0-9]{10}$/i', $config['team_id'])) {
            throw new PushConfigurationException('The Team ID should be 10 letters and numbers, like TEAM123456.');
        }
        if (empty($config['app_bundle_id'])) {
            throw new PushConfigurationException('Enter the bundle ID of your iOS app, like com.acme.driver.');
        }
        if (!preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $config['app_bundle_id'])) {
            throw new PushConfigurationException('The bundle ID should look like com.acme.driver: the Bundle Identifier of your iOS app in Xcode.');
        }
        if (empty($config['private_key_content']) && empty($saved['private_key_content'])) {
            throw new PushConfigurationException('Add the .p8 key you downloaded from Apple.');
        }
        if (!empty($config['private_key_content']) && !str_contains($config['private_key_content'], 'PRIVATE KEY')) {
            throw new PushConfigurationException('The .p8 key should be the whole contents of the .p8 file, starting with "-----BEGIN PRIVATE KEY-----".');
        }
        if (!empty($config['environment']) && !in_array($config['environment'], [ApnClientFactory::PRODUCTION, ApnClientFactory::SANDBOX], true)) {
            throw new PushConfigurationException('Choose production or sandbox, or leave it to try both.');
        }

        return $config;
    }

    public function summary(array $config): array
    {
        return [
            'key_id'        => $config['key_id'] ?? null,
            'team_id'       => $config['team_id'] ?? null,
            'app_bundle_id' => $config['app_bundle_id'] ?? null,
            'environment'   => $config['environment'] ?? null,
            'has_key'       => !empty($config['private_key_content']),
        ];
    }

    /**
     * A device that registered its environment is sent to that one only, as is an app with a
     * fixed environment. Otherwise production is tried first and a token rejected with
     * BadDeviceToken is retried in sandbox, so development and App Store builds of one app
     * share its settings.
     */
    public function environments(PushCredentials $credentials, ?string $deviceEnvironment = null): array
    {
        $environments = [ApnClientFactory::PRODUCTION, ApnClientFactory::SANDBOX];
        if (in_array($deviceEnvironment, $environments, true)) {
            return [$deviceEnvironment];
        }

        $environment = $credentials->config['environment'] ?? null;

        return in_array($environment, $environments, true) ? [$environment] : $environments;
    }

    public function send(PushCredentials $credentials, PushMessage $message, array $tokens, ?string $environment = null): array
    {
        if (empty($tokens)) {
            return [];
        }

        $client  = $this->factory->make($credentials, $environment ?? ApnClientFactory::PRODUCTION);
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
