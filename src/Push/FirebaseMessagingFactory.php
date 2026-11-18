<?php

namespace Fleetbase\Push;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;

/**
 * Builds a Firebase Messaging client from an app's own service account. The install-wide
 * `firebase.projects.*` config is never read or changed.
 */
class FirebaseMessagingFactory
{
    /**
     * @var array<string, Messaging>
     */
    protected static array $clients = [];

    public function make(PushCredentials $credentials): Messaging
    {
        $serviceAccount = static::serviceAccount($credentials);
        $cacheKey       = $credentials->id . ':' . md5(json_encode($serviceAccount));

        return static::$clients[$cacheKey] ??= $this->createMessaging($serviceAccount);
    }

    protected function createMessaging(array $serviceAccount): Messaging
    {
        return (new Factory())->withServiceAccount($serviceAccount)->createMessaging();
    }

    /**
     * The service account, decoded, with private-key newlines repaired if they were escaped
     * when pasted.
     *
     * @throws PushConfigurationException
     */
    public static function serviceAccount(PushCredentials $credentials): array
    {
        $account = $credentials->config['credentials'] ?? null;
        if (is_string($account)) {
            $account = json_decode(trim($account), true);
        }
        if (!is_array($account)) {
            throw new PushConfigurationException('The Firebase service account of "' . $credentials->name . '" is missing or isn\'t valid JSON.');
        }
        foreach (['project_id', 'client_email', 'private_key'] as $required) {
            if (empty($account[$required])) {
                throw new PushConfigurationException('The Firebase service account of "' . $credentials->name . '" is missing "' . $required . '".');
            }
        }

        $account['private_key'] = str_replace('\\n', "\n", $account['private_key']);
        $account['type'] ??= 'service_account';

        return $account;
    }

    public static function flush(): void
    {
        static::$clients = [];
    }
}
