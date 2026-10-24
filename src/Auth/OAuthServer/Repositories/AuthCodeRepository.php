<?php

namespace Fleetbase\Auth\OAuthServer\Repositories;

use Fleetbase\Auth\OAuthServer\Entities\AuthCodeEntity;
use Fleetbase\Auth\OAuthServer\IssueContext;
use Fleetbase\Models\OAuthAccessToken;
use Fleetbase\Models\OAuthAuthCode;
use Fleetbase\Models\OAuthRefreshToken;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(protected IssueContext $context)
    {
    }

    public function getNewAuthCode()
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity)
    {
        if (!$this->context->grantUuid) {
            throw new \LogicException('An authorization code can only be issued under a grant.');
        }

        OAuthAuthCode::create([
            'id'           => $authCodeEntity->getIdentifier(),
            'grant_uuid'   => $this->context->grantUuid,
            'client_id'    => $authCodeEntity->getClient()->getIdentifier(),
            'user_uuid'    => $authCodeEntity->getUserIdentifier(),
            'company_uuid' => $this->context->companyUuid,
            'scopes'       => array_map(fn ($scope) => $scope->getIdentifier(), $authCodeEntity->getScopes()),
            'nonce'        => $this->context->nonce,
            'auth_time'    => $this->context->authTime,
            'revoked'      => false,
            'expires_at'   => $authCodeEntity->getExpiryDateTime(),
            'created_at'   => now(),
        ]);
    }

    public function revokeAuthCode($codeId)
    {
        OAuthAuthCode::where('id', $codeId)->update(['revoked' => true]);
    }

    /**
     * A code that was already redeemed is being presented again. RFC 6749 §4.1.2: the server
     * SHOULD revoke every token issued from it, since one of the two holders is an attacker.
     */
    public function isAuthCodeRevoked($codeId)
    {
        $code = OAuthAuthCode::find($codeId);
        if (!$code) {
            return true;
        }

        if ($code->revoked) {
            $tokenIds = OAuthAccessToken::where('auth_code_id', $codeId)->pluck('id')->all();
            if ($tokenIds) {
                OAuthAccessToken::whereIn('id', $tokenIds)->update(['revoked' => true, 'updated_at' => now()]);
                OAuthRefreshToken::whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
            }

            return true;
        }

        return false;
    }
}
