<?php

namespace Fleetbase\Auth\OAuthServer\Entities;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * An access token, handed to the client as an RS256 JWT.
 *
 * League's AccessTokenTrait is reimplemented here only to add `iss` and the key's `kid`, so a
 * client can tell which provider and which key signed it. The claims league relies on (`jti`,
 * `aud`, `sub`, `exp`, `nbf`, `scopes`) are unchanged, so league's BearerTokenValidator reads it.
 */
class AccessTokenEntity implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    private ?CryptKey $privateKey = null;

    /**
     * The JWT, built once: the token response and the ID token's at_hash must see the same string.
     */
    private ?string $jwt = null;

    public function __construct(private string $issuer, private ?string $keyId = null)
    {
    }

    public function setPrivateKey(CryptKey $privateKey)
    {
        $this->privateKey = $privateKey;
    }

    public function toJwt(): Token
    {
        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty')
        );

        // Whole seconds, so the JWT's dates are integers (lcobucci writes microseconds as floats).
        $now     = new \DateTimeImmutable('@' . time());
        $builder = $config->builder()
            ->issuedBy($this->issuer)
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt(new \DateTimeImmutable('@' . $this->getExpiryDateTime()->getTimestamp()))
            ->relatedTo((string) $this->getUserIdentifier())
            ->withClaim('scopes', array_map(fn ($scope) => $scope->getIdentifier(), $this->getScopes()));

        if ($this->keyId) {
            $builder = $builder->withHeader('kid', $this->keyId);
        }

        return $builder->getToken($config->signer(), $config->signingKey());
    }

    public function __toString()
    {
        return $this->jwt ??= $this->toJwt()->toString();
    }
}
