<?php

namespace Fleetbase\Auth\OAuth;

use Firebase\JWT\JWK;
use Fleetbase\Auth\OAuth\Exceptions\OAuthIdTokenException;
use Fleetbase\Auth\Signers\AppleSignerInMemory;
use Fleetbase\Auth\Signers\AppleSignerNone;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/**
 * Verifies an OIDC ID token against a provider's published JWKS.
 *
 * Generalised from the existing Fleetbase\Auth\AppleVerifier, with two differences
 * that matter:
 *
 *   1. It asserts the audience (PermittedFor). AppleVerifier does not, which means
 *      it will accept a token minted for any other Apple application — that class
 *      is left untouched here because it has its own callers and tests, but it is
 *      not used by the OAuth flow for exactly this reason.
 *   2. Issuer validation is a caller-supplied predicate rather than an equality
 *      check, because Microsoft's multi-tenant issuer embeds the user's home
 *      tenant id and cannot be compared literally.
 *
 * It returns the verified claims rather than a boolean, so a caller never has to
 * re-parse an unverified copy of the token to read them.
 */
class IdTokenVerifier
{
    /**
     * How long a provider's JWKS document is cached. Short, because a provider
     * rotating a signing key must not lock users out for long.
     */
    public const JWKS_CACHE_SECONDS = 300;

    /**
     * Verify an ID token and return its claims.
     *
     * @param \Closure(string): bool $issuerIsValid
     *
     * @return array<string, mixed>
     *
     * @throws OAuthIdTokenException
     */
    public function verify(
        string $jwt,
        string $jwksUrl,
        string $audience,
        \Closure $issuerIsValid,
        string $cacheKey,
        int $jwksTtl = self::JWKS_CACHE_SECONDS,
    ): array {
        if ($jwt === '') {
            throw new OAuthIdTokenException('id_token_missing');
        }

        // An unconfigured client id must never reach PermittedFor, which would then
        // be asserting against an empty audience.
        if ($audience === '') {
            throw new OAuthIdTokenException('audience_not_configured');
        }

        // lcobucci requires a configuration even when the signer is overridden per
        // call; this mirrors the bootstrap in Fleetbase\Auth\AppleVerifier.
        $container = Configuration::forSymmetricSigner(new AppleSignerNone(), AppleSignerInMemory::plainText(''));

        try {
            $token = $container->parser()->parse($jwt);
        } catch (\Throwable $e) {
            throw new OAuthIdTokenException('id_token_malformed');
        }

        if (!$token instanceof Plain) {
            throw new OAuthIdTokenException('id_token_malformed');
        }

        $kid = $token->headers()->get('kid');

        if (!is_string($kid) || $kid === '') {
            throw new OAuthIdTokenException('id_token_missing_kid');
        }

        $publicKey = $this->resolvePublicKey($jwksUrl, $cacheKey, $jwksTtl, $kid);

        try {
            $container->validator()->assert(
                $token,
                new SignedWith(new Sha256(), AppleSignerInMemory::plainText($publicKey)),
                new PermittedFor($audience),
                new LooseValidAt(SystemClock::fromSystemTimezone())
            );
        } catch (\Throwable $e) {
            // Signature, audience or expiry. Which one is a detail for the operator,
            // not the caller — and the token itself is never logged.
            Log::info('[OAuth] ID token failed validation constraints.', ['jwks' => $jwksUrl]);

            throw new OAuthIdTokenException('id_token_invalid');
        }

        $issuer = $token->claims()->get('iss');

        if (!is_string($issuer) || !$issuerIsValid($issuer)) {
            Log::info('[OAuth] ID token carried an unexpected issuer.', ['jwks' => $jwksUrl]);

            throw new OAuthIdTokenException('id_token_issuer_mismatch');
        }

        return $token->claims()->all();
    }

    /**
     * Fetch the signing key for a key id out of the provider's JWKS.
     *
     * @throws OAuthIdTokenException
     */
    protected function resolvePublicKey(string $jwksUrl, string $cacheKey, int $jwksTtl, string $kid): string
    {
        $jwks = $this->fetchJwks($jwksUrl, $cacheKey, $jwksTtl);

        try {
            // RS256 is only the fallback for keys that don't name an algorithm, and
            // Microsoft's JWKS names none: without a default, php-jwt rejects the whole
            // document. It loosens nothing: the signature is checked with RSA-SHA256
            // below whatever the token or key claims.
            $keys = JWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable $e) {
            throw new OAuthIdTokenException('jwks_unreadable');
        }

        if (!isset($keys[$kid])) {
            // A rotated key that is not yet in our cached copy looks identical to a
            // forged kid. Drop the cached document so the next attempt refetches.
            Cache::forget($cacheKey);

            throw new OAuthIdTokenException('id_token_unknown_key');
        }

        $details = openssl_pkey_get_details($keys[$kid]->getKeyMaterial());

        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            throw new OAuthIdTokenException('jwks_unreadable');
        }

        return $details['key'];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OAuthIdTokenException
     */
    protected function fetchJwks(string $jwksUrl, string $cacheKey, int $jwksTtl): array
    {
        try {
            $jwks = Cache::remember($cacheKey, $jwksTtl, function () use ($jwksUrl) {
                // TLS verification is always on. Several older verifiers in this
                // codebase disable it when app.debug is true, which also disables it
                // in staging; that is not repeated here.
                $response = (new GuzzleClient(['timeout' => 8.0, 'connect_timeout' => 4.0]))->get($jwksUrl);

                return json_decode((string) $response->getBody(), true);
            });
        } catch (\Throwable $e) {
            Log::error('[OAuth] Unable to fetch provider JWKS.', ['jwks' => $jwksUrl]);

            throw new OAuthIdTokenException('jwks_unreachable');
        }

        if (!is_array($jwks)) {
            throw new OAuthIdTokenException('jwks_unreadable');
        }

        return $jwks;
    }
}
