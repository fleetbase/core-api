<?php

namespace Fleetbase\Auth\OAuth;

use Fleetbase\Auth\OAuth\Exceptions\OAuthProviderNotConfiguredException;
use Fleetbase\Auth\Signers\AppleSignerInMemory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as EcdsaSha256;

/**
 * Mints the ephemeral client secret Apple requires at its token endpoint.
 *
 * Apple has no static client secret. Instead the token request must carry an ES256
 * JWT signed with the team's .p8 private key, valid for at most six months and
 * conventionally minted much shorter than that.
 *
 * No new dependency is needed: lcobucci/jwt and lcobucci/clock are already required
 * by core-api, and Fleetbase\Auth\Signers\AppleSignerInMemory already exists to
 * satisfy lcobucci's non-empty-key requirement for the unused verification key.
 */
class AppleClientSecretFactory
{
    /**
     * Lifetime of the minted assertion.
     */
    public const SECRET_TTL_SECONDS = 3600;

    /**
     * How long a minted secret is reused.
     *
     * Deliberately shorter than SECRET_TTL_SECONDS so a cached value can never be
     * served after it has expired.
     */
    public const CACHE_TTL_SECONDS = 3000;

    /**
     * Mint (or reuse) a client secret for the given Apple configuration.
     *
     * @throws OAuthProviderNotConfiguredException
     */
    public function make(OAuthProviderConfig $config): string
    {
        $teamId   = $config->get('team_id');
        $keyId    = $config->get('key_id');
        $clientId = $config->get('client_id');

        if (!is_string($teamId) || !is_string($keyId) || !is_string($clientId)) {
            throw new OAuthProviderNotConfiguredException('apple.team_id|key_id|client_id');
        }

        // The .p8 contents. Held only for the duration of the signing closure.
        $privateKey = $config->secret('private_key');

        $cacheKey = 'oauth.apple.client_secret.' . sha1(implode('|', [$teamId, $keyId, $clientId]));

        $secret = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($teamId, $keyId, $clientId, $privateKey): string {
            return $this->mint($teamId, $keyId, $clientId, $privateKey);
        });

        return is_string($secret) ? $secret : '';
    }

    /**
     * @throws OAuthProviderNotConfiguredException
     */
    protected function mint(string $teamId, string $keyId, string $clientId, string $privateKey): string
    {
        try {
            $configuration = Configuration::forAsymmetricSigner(
                new EcdsaSha256(),
                AppleSignerInMemory::plainText($privateKey),
                // Never used — nothing verifies this assertion locally — but lcobucci
                // requires a verification key, and its own InMemory rejects an empty one.
                AppleSignerInMemory::plainText('')
            );

            $issuedAt = Carbon::now();

            return $configuration->builder()
                ->issuedBy($teamId)
                ->relatedTo($clientId)
                ->permittedFor('https://appleid.apple.com')
                ->issuedAt($issuedAt->toDateTimeImmutable())
                ->expiresAt($issuedAt->copy()->addSeconds(self::SECRET_TTL_SECONDS)->toDateTimeImmutable())
                ->withHeader('kid', $keyId)
                ->getToken($configuration->signer(), $configuration->signingKey())
                ->toString();
        } catch (\Throwable $e) {
            // A malformed or non-EC .p8. Name nothing but the provider — the exception
            // message from OpenSSL can echo key material.
            Log::error('[OAuth] Unable to mint the Apple client secret.', ['provider' => 'apple']);

            throw new OAuthProviderNotConfiguredException('apple.private_key');
        }
    }
}
