<?php

namespace Fleetbase\Auth\OAuthServer\Repositories;

use Fleetbase\Auth\OAuthServer\Entities\ScopeEntity;
use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Models\OAuthClient;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier($identifier)
    {
        return OAuthScopes::has($identifier) ? new ScopeEntity($identifier) : null;
    }

    /**
     * Keep only scopes that are registered and that the client was registered for.
     *
     * The consent step already refuses a request for anything else with `invalid_scope`; this is
     * the backstop at token time, in case a client's allowed scopes were narrowed after a code
     * or refresh token was issued.
     */
    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null)
    {
        $client = OAuthClient::find($clientEntity->getIdentifier());
        if (!$client) {
            return [];
        }

        return array_values(array_filter($scopes, function ($scope) use ($client) {
            return OAuthScopes::has($scope->getIdentifier()) && $client->allowsScope($scope->getIdentifier());
        }));
    }
}
