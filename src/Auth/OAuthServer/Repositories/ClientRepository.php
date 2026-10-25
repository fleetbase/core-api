<?php

namespace Fleetbase\Auth\OAuthServer\Repositories;

use Fleetbase\Auth\OAuthServer\Entities\ClientEntity;
use Fleetbase\Models\OAuthClient;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

class ClientRepository implements ClientRepositoryInterface
{
    /**
     * The only grants the provider supports.
     */
    public const GRANT_TYPES = ['authorization_code', 'refresh_token'];

    public function getClientEntity($clientIdentifier)
    {
        $client = $this->findActive($clientIdentifier);
        if (!$client) {
            return null;
        }

        return new ClientEntity($client->id, $client->name, $client->redirect_uris ?? [], $client->isConfidential());
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType)
    {
        $client = $this->findActive($clientIdentifier);
        if (!$client || !in_array($grantType, static::GRANT_TYPES, true)) {
            return false;
        }

        if ($client->isConfidential()) {
            return $client->verifySecret($clientSecret);
        }

        // A public client has no secret to present; sending one means it was misconfigured.
        return $clientSecret === null || $clientSecret === '';
    }

    public function findActive($clientIdentifier): ?OAuthClient
    {
        if (!is_string($clientIdentifier) || !preg_match('/^[0-9a-f-]{36}$/i', $clientIdentifier)) {
            return null;
        }

        return OAuthClient::where('id', $clientIdentifier)->whereNull('disabled_at')->first();
    }
}
