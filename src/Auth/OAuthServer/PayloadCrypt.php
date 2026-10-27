<?php

namespace Fleetbase\Auth\OAuthServer;

use Defuse\Crypto\Key;
use League\OAuth2\Server\CryptTrait;

/**
 * Opens the encrypted payload league puts in an authorization code or refresh token.
 *
 * The token endpoint reads the code's or refresh token's id before league redeems it, to find
 * the grant and organization the new tokens belong to. Anything that doesn't decrypt is left for
 * league to reject with its usual error.
 */
class PayloadCrypt
{
    use CryptTrait;

    public function __construct(Key $key)
    {
        $this->setEncryptionKey($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function open(mixed $payload): ?array
    {
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->decrypt($payload), true);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
