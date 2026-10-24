<?php

namespace Fleetbase\Auth\OAuthServer\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    /**
     * @param array<int, string> $redirectUris
     */
    public function __construct(string $identifier, string $name, array $redirectUris, bool $confidential)
    {
        $this->setIdentifier($identifier);
        $this->name           = $name;
        $this->redirectUri    = $redirectUris;
        $this->isConfidential = $confidential;
    }
}
