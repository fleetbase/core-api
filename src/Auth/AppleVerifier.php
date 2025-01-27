<?php

namespace Fleetbase\Auth;

use Firebase\JWT\JWK;
use Fleetbase\Auth\Signers\AppleSignerInMemory;
use Fleetbase\Auth\Signers\AppleSignerNone;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;

class AppleVerifier
{
    private const APPLE_ISSUER   = 'https://appleid.apple.com';
    private const APPLE_KEYS_URL = self::APPLE_ISSUER . '/auth/keys';
    private const CACHE_DURATION = 300; // Cache Apple keys for 5 minutes

    /**
     * Verifies an Apple JWT.
     *
     * @throws \Exception
     */
    public static function verifyAppleJwt(string $jwt): bool
    {
        // Create JWT configuration
        $jwtContainer = Configuration::forSymmetricSigner(
            new AppleSignerNone(),
            AppleSignerInMemory::plainText('')
        );

        // Parse the token
        $token = $jwtContainer->parser()->parse($jwt);

        // Fetch and cache the Apple public keys
        $data = Cache::remember('apple-JWKSet', self::CACHE_DURATION, function () {
            $response = (new Client())->get(self::APPLE_KEYS_URL);

            return json_decode((string) $response->getBody(), true);
        });

        $publicKeys = JWK::parseKeySet($data);
        $kid        = $token->headers()->get('kid');

        if (isset($publicKeys[$kid])) {
            // Extract public key material
            $publicKey = openssl_pkey_get_details($publicKeys[$kid]->getKeyMaterial());

            // Define validation constraints
            $constraints = [
                new SignedWith(new Sha256(), AppleSignerInMemory::plainText($publicKey['key'])),
                new IssuedBy(self::APPLE_ISSUER),
                new LooseValidAt(SystemClock::fromSystemTimezone()),
            ];

            try {
                // Validate the token with constraints
                $jwtContainer->validator()->assert($token, ...$constraints);

                return true;
            } catch (RequiredConstraintsViolated $e) {
                throw new \Exception('JWT validation failed: ' . $e->getMessage());
            }
        }

        throw new \Exception('Invalid JWT Signature or missing key ID.');
    }
}
