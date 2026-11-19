<?php

namespace Fleetbase\Push;

use Pushok\AuthProvider\Token;
use Pushok\Client;

/**
 * Builds APNs (pushok) clients from an app's APNs credentials.
 */
class ApnClientFactory
{
    public const PRODUCTION = 'production';
    public const SANDBOX    = 'sandbox';

    public function make(PushCredentials $credentials, string $environment): Client
    {
        return new Client(Token::create(static::credentials($credentials)), $environment === self::PRODUCTION);
    }

    /**
     * @throws PushConfigurationException
     */
    public static function credentials(PushCredentials $credentials): array
    {
        $config = $credentials->config;
        foreach (['key_id', 'team_id', 'app_bundle_id', 'private_key_content'] as $required) {
            if (empty($config[$required])) {
                throw new PushConfigurationException('The APNs credentials of "' . $credentials->name . '" are missing "' . $required . '".');
            }
        }

        return [
            'key_id'              => trim($config['key_id']),
            'team_id'             => trim($config['team_id']),
            'app_bundle_id'       => trim($config['app_bundle_id']),
            'private_key_content' => str_replace('\\n', "\n", trim($config['private_key_content'])),
            'private_key_secret'  => $config['private_key_secret'] ?? null,
        ];
    }
}
