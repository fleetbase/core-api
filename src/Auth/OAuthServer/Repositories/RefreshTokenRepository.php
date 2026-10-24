<?php

namespace Fleetbase\Auth\OAuthServer\Repositories;

use Fleetbase\Auth\OAuthServer\Entities\RefreshTokenEntity;
use Fleetbase\Auth\OAuthServer\IssueContext;
use Fleetbase\Models\OAuthGrant;
use Fleetbase\Models\OAuthRefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(protected IssueContext $context)
    {
    }

    public function getNewRefreshToken()
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity)
    {
        if (!$this->context->grantUuid) {
            throw new \LogicException('A refresh token can only be issued under a grant.');
        }

        OAuthRefreshToken::create([
            'id'              => $refreshTokenEntity->getIdentifier(),
            'access_token_id' => $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'grant_uuid'      => $this->context->grantUuid,
            'revoked'         => false,
            'expires_at'      => $refreshTokenEntity->getExpiryDateTime(),
            'created_at'      => now(),
        ]);
    }

    public function revokeRefreshToken($tokenId)
    {
        OAuthRefreshToken::where('id', $tokenId)->update(['revoked' => true]);
    }

    /**
     * Refresh tokens rotate on every use, so a revoked one coming back means it was copied: either
     * the client or a thief already used it. Revoke the whole grant so neither copy keeps working
     * (OAuth 2.0 Security BCP, RFC 9700 §4.14.2).
     */
    public function isRefreshTokenRevoked($tokenId)
    {
        $token = OAuthRefreshToken::find($tokenId);
        if (!$token) {
            return true;
        }

        if ($token->revoked) {
            $grant = OAuthGrant::find($token->grant_uuid);
            if ($grant && !$grant->isRevoked()) {
                $grant->revoke();
            }

            return true;
        }

        return false;
    }
}
