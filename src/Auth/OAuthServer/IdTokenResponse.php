<?php

namespace Fleetbase\Auth\OAuthServer;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

/**
 * The token endpoint's response, plus an OpenID Connect ID token when `openid` was granted.
 *
 * The ID token is an RS256 JWT signed with the same key as access tokens and published at
 * /oauth/jwks. It carries iss, sub, aud, iat, exp, auth_time, nonce (when the authorization
 * request sent one), at_hash, and the profile/email/organization claims the scopes allow.
 */
class IdTokenResponse extends BearerTokenResponse
{
    public function __construct(
        protected IssueContext $context,
        protected OAuthKeys $keys,
        protected string $issuer,
        protected \DateInterval $ttl,
    ) {
    }

    protected function getExtraParams(AccessTokenEntityInterface $accessToken)
    {
        $scopes = array_map(fn ($scope) => $scope->getIdentifier(), $accessToken->getScopes());
        $params = ['scope' => implode(' ', $scopes)];

        if (!in_array('openid', $scopes, true)) {
            return $params;
        }

        $user = User::where('uuid', $accessToken->getUserIdentifier())->first();
        if (!$user) {
            return $params;
        }

        $company = $this->context->companyUuid ? Company::where('uuid', $this->context->companyUuid)->first() : null;
        $config  = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->keys->privateKeyContents()),
            InMemory::plainText($this->keys->publicKeyContents())
        );

        $now     = new \DateTimeImmutable('@' . time());
        $builder = $config->builder()
            ->withHeader('kid', $this->keys->keyId())
            ->issuedBy($this->issuer)
            ->permittedFor($accessToken->getClient()->getIdentifier())
            ->issuedAt($now)
            ->expiresAt($now->add($this->ttl))
            ->withClaim('at_hash', static::accessTokenHash((string) $accessToken));

        foreach (OAuthClaims::forUser($user, $scopes, $company) as $claim => $value) {
            $builder = $claim === 'sub' ? $builder->relatedTo($value) : $builder->withClaim($claim, $value);
        }

        if ($this->context->authTime) {
            $builder = $builder->withClaim('auth_time', $this->context->authTime->getTimestamp());
        }

        if ($this->context->nonce) {
            $builder = $builder->withClaim('nonce', $this->context->nonce);
        }

        $params['id_token'] = $builder->getToken($config->signer(), $config->signingKey())->toString();

        return $params;
    }

    /**
     * OIDC Core §3.1.3.6: base64url of the left half of the SHA-256 of the access token.
     */
    public static function accessTokenHash(string $accessToken): string
    {
        return OAuthKeys::base64url(substr(hash('sha256', $accessToken, true), 0, 16));
    }
}
