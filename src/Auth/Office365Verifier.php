<?php

namespace Fleetbase\Auth;

use Firebase\JWT\JWK;
use Fleetbase\Auth\Signers\AppleSignerInMemory;
use Fleetbase\Auth\Signers\AppleSignerNone;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Cache;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\HasClaimWithValue;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/**
 * Server-side verifier for Microsoft / Office365 ID tokens.
 *
 * Mirrors the AppleVerifier pattern (lcobucci/jwt parse + JWKS lookup
 * + RS256 signature check) against Microsoft's identity platform:
 *
 *   - Issuer: https://login.microsoftonline.com/{tenant}/v2.0
 *   - JWKS:   https://login.microsoftonline.com/{tenant}/discovery/v2.0/keys
 *   - Audience: the registered application client_id
 *
 * For multi-tenant apps (`services.microsoft.tenant = 'common'`) the
 * issuer in the token will contain the user's home tenant UUID rather
 * than 'common'. We therefore validate the issuer with a prefix check
 * rather than a strict equals.
 *
 * Config keys required:
 *   - services.microsoft.client_id  — registered Azure app client id (audience)
 *   - services.microsoft.tenant     — 'common' (multi-tenant) or a tenant uuid/domain
 */
class Office365Verifier
{
    private const CACHE_DURATION = 300; // Cache Microsoft JWKS for 5 minutes
    private const ISSUER_PREFIX  = 'https://login.microsoftonline.com/';

    /**
     * Verify a Microsoft ID JWT and return the verified profile.
     *
     * @return array|null `['user_id', 'email', 'name', 'tenant_id']` when valid, `null` otherwise
     */
    public static function verifyIdToken(string $idToken): ?array
    {
        $clientId = config('services.microsoft.client_id');
        $tenant   = config('services.microsoft.tenant', 'common');

        if (!$clientId) {
            logger()->error('Microsoft OAuth not configured — services.microsoft.client_id missing');

            return null;
        }

        try {
            // lcobucci/jwt requires *some* configuration even when we override
            // the signer per-call — match AppleVerifier's bootstrap pattern.
            $jwtContainer = Configuration::forSymmetricSigner(
                new AppleSignerNone(),
                AppleSignerInMemory::plainText('')
            );

            $token = $jwtContainer->parser()->parse($idToken);
            $kid   = $token->headers()->get('kid');
            if (!$kid) {
                logger()->warning('Microsoft ID token missing kid header');

                return null;
            }

            $jwks = self::fetchJwks($tenant);
            $keys = JWK::parseKeySet($jwks);
            if (!isset($keys[$kid])) {
                logger()->warning('Microsoft ID token kid not in JWKS', ['kid' => $kid]);

                return null;
            }

            $publicKey = openssl_pkey_get_details($keys[$kid]->getKeyMaterial());

            $constraints = [
                new SignedWith(new Sha256(), AppleSignerInMemory::plainText($publicKey['key'])),
                new PermittedFor($clientId),
                new LooseValidAt(SystemClock::fromSystemTimezone()),
            ];

            if (!$jwtContainer->validator()->validate($token, ...$constraints)) {
                logger()->info('Microsoft ID token failed validation constraints');

                return null;
            }

            // Issuer prefix check — accept both single-tenant + multi-tenant
            // (token issuer contains the user's home tenant UUID in `common`).
            $issuer = (string) $token->claims()->get('iss');
            if (!str_starts_with($issuer, self::ISSUER_PREFIX)) {
                logger()->warning('Microsoft ID token has unexpected issuer', ['iss' => $issuer]);

                return null;
            }

            // Microsoft uses `oid` (object id, immutable per tenant) as the
            // stable user identifier. `sub` is per-application-pairwise and
            // also stable, but `oid` is the conventional choice when the same
            // user may sign into multiple Microsoft apps.
            $oid = (string) $token->claims()->get('oid');
            if (!$oid) {
                logger()->warning('Microsoft ID token missing oid claim');

                return null;
            }

            $email = $token->claims()->get('email')
                ?? $token->claims()->get('preferred_username');
            $name  = $token->claims()->get('name');
            $tid   = (string) $token->claims()->get('tid');

            return [
                'user_id'   => $oid,
                'email'     => $email ? (string) $email : null,
                'name'      => $name ? (string) $name : null,
                'tenant_id' => $tid ?: null,
            ];
        } catch (\Throwable $e) {
            logger()->error('Microsoft ID token verification failed: ' . $e->getMessage());

            return null;
        }
    }

    private static function fetchJwks(string $tenant): array
    {
        $cacheKey = "microsoft-jwks:{$tenant}";

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($tenant) {
            $url      = self::ISSUER_PREFIX . rawurlencode($tenant) . '/discovery/v2.0/keys';
            $response = (new GuzzleClient([
                'timeout'         => 8.0,
                'connect_timeout' => 4.0,
                'verify'          => config('app.debug') !== true && app()->environment('production'),
            ]))->get($url);

            return json_decode((string) $response->getBody(), true);
        });
    }
}
