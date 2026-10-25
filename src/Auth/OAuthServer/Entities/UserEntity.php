<?php

namespace Fleetbase\Auth\OAuthServer\Entities;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * The signed-in user approving a request. The identifier is the user's uuid.
 */
class UserEntity implements UserEntityInterface
{
    use EntityTrait;

    public function __construct(string $uuid)
    {
        $this->setIdentifier($uuid);
    }
}
