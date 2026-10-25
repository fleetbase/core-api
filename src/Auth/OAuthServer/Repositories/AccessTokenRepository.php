<?php

namespace Fleetbase\Auth\OAuthServer\Repositories;

use Fleetbase\Auth\OAuthServer\Entities\AccessTokenEntity;
use Fleetbase\Auth\OAuthServer\IssueContext;
use Fleetbase\Models\OAuthAccessToken;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(protected IssueContext $context, protected string $issuer, protected ?string $keyId = null)
    {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null)
    {
        $token = new AccessTokenEntity($this->issuer, $this->keyId);
        $token->setClient($clientEntity);
        $token->setUserIdentifier($userIdentifier);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity)
    {
        if (!$this->context->grantUuid) {
            throw new \LogicException('An access token can only be issued under a grant.');
        }

        OAuthAccessToken::create([
            'id'           => $accessTokenEntity->getIdentifier(),
            'grant_uuid'   => $this->context->grantUuid,
            'client_id'    => $accessTokenEntity->getClient()->getIdentifier(),
            'user_uuid'    => $accessTokenEntity->getUserIdentifier(),
            'company_uuid' => $this->context->companyUuid,
            'auth_code_id' => $this->context->authCodeId,
            'scopes'       => array_map(fn ($scope) => $scope->getIdentifier(), $accessTokenEntity->getScopes()),
            'revoked'      => false,
            'expires_at'   => $accessTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeAccessToken($tokenId)
    {
        OAuthAccessToken::where('id', $tokenId)->update(['revoked' => true, 'updated_at' => now()]);
    }

    public function isAccessTokenRevoked($tokenId)
    {
        $token = OAuthAccessToken::find($tokenId);

        return !$token || $token->revoked;
    }
}
