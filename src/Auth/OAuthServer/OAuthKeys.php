<?php

namespace Fleetbase\Auth\OAuthServer;

use Defuse\Crypto\Encoding;
use Defuse\Crypto\Key;
use League\OAuth2\Server\CryptKey;

/**
 * The keys the OAuth provider signs and encrypts with.
 *
 * - An RSA key pair signs access tokens and ID tokens (RS256). It comes from
 *   OAUTH_SERVER_PRIVATE_KEY / OAUTH_SERVER_PUBLIC_KEY (PEM, `\n` escapes allowed), or from
 *   oauth-private.key / oauth-public.key in storage/, written by `php artisan oauth-server:keys`.
 * - A symmetric key encrypts the authorization code and refresh token payloads. It is derived
 *   from APP_KEY with HKDF, so it needs no setup and is the same on every instance.
 */
class OAuthKeys
{
    public function privateKeyContents(): string
    {
        return $this->load('private');
    }

    public function publicKeyContents(): string
    {
        return $this->load('public');
    }

    public function privateKey(): CryptKey
    {
        return new CryptKey($this->privateKeyContents(), null, false);
    }

    public function publicKey(): CryptKey
    {
        return new CryptKey($this->publicKeyContents(), null, false);
    }

    /**
     * Whether a key pair is configured.
     */
    public function configured(): bool
    {
        try {
            return $this->privateKeyContents() !== '' && $this->publicKeyContents() !== '';
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * The defuse key league encrypts code and refresh-token payloads with.
     *
     * A Key object (rather than a password string) skips defuse's per-call PBKDF2 stretching,
     * which is meant for human passwords and costs tens of milliseconds per token.
     */
    public function encryptionKey(): Key
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not set; the OAuth provider needs it to encrypt authorization codes.');
        }

        $bytes = hash_hkdf('sha256', $appKey, Key::KEY_BYTE_SIZE, 'fleetbase-oauth-server-payloads');

        return Key::loadFromAsciiSafeString(Encoding::saveBytesToChecksummedAsciiSafeString(Key::KEY_CURRENT_VERSION, $bytes));
    }

    /**
     * The JWK for the public key, with its RFC 7638 thumbprint as `kid`.
     *
     * @return array<string, string>
     */
    public function jwk(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->publicKeyContents()));
        $n       = static::base64url($details['rsa']['n']);
        $e       = static::base64url($details['rsa']['e']);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => static::base64url(hash('sha256', json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n]), true)),
            'n'   => $n,
            'e'   => $e,
        ];
    }

    public function keyId(): string
    {
        return $this->jwk()['kid'];
    }

    /**
     * Generate a new RSA key pair.
     *
     * @return array{private: string, public: string}
     */
    public static function generate(int $bits = 4096): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $public = openssl_pkey_get_details($resource)['key'];

        return ['private' => $private, 'public' => $public];
    }

    public static function keyPath(string $type): string
    {
        return storage_path("oauth-{$type}.key");
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    protected function load(string $type): string
    {
        $configured = str_replace('\\n', "\n", (string) config("oauth-server.{$type}_key", ''));
        if (trim($configured) !== '') {
            return $configured;
        }

        $path = static::keyPath($type);
        if (is_readable($path)) {
            return (string) file_get_contents($path);
        }

        throw new \RuntimeException("The OAuth provider's {$type} key is not configured. Run `php artisan oauth-server:keys` or set OAUTH_SERVER_" . strtoupper($type) . '_KEY.');
    }
}
