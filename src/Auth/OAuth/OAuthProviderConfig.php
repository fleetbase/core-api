<?php

namespace Fleetbase\Auth\OAuth;

use Fleetbase\Auth\OAuth\Exceptions\OAuthProviderNotConfiguredException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * The resolved configuration for a single OAuth provider.
 *
 * Values arrive already merged by OAuthConfigRepository: database settings layered
 * over the env-backed defaults in config/oauth.php.
 *
 * Secrets are handled in one of two shapes, and the distinction is load-bearing:
 *
 *   <key>_encrypted — written by the admin UI, Crypt-encrypted at rest
 *   <key>           — supplied through the environment, already trusted, plaintext
 *
 * The suffix is what tells them apart, so a plaintext legacy value can never be
 * mistaken for ciphertext and handed to a provider verbatim.
 */
class OAuthProviderConfig
{
    /**
     * Suffix marking a stored value as ciphertext.
     */
    public const ENCRYPTED_SUFFIX = '_encrypted';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public readonly string $provider,
        private array $values,
        private ?Encrypter $encrypter = null,
    ) {
    }

    /**
     * Read a non-secret value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Whether an administrator has switched this provider on.
     *
     * Being enabled is not the same as being usable — see isConfigured().
     */
    public function enabled(): bool
    {
        return (bool) ($this->values['enabled'] ?? false);
    }

    /**
     * Resolve a secret, decrypting it when it was stored through the admin UI.
     *
     * @throws OAuthProviderNotConfiguredException when the secret is absent or undecryptable
     */
    public function secret(string $key): string
    {
        $encrypted = $this->values[$key . self::ENCRYPTED_SUFFIX] ?? null;

        if (is_string($encrypted) && $encrypted !== '') {
            if (!$this->encrypter instanceof Encrypter) {
                throw new OAuthProviderNotConfiguredException($this->provider . '.' . $key);
            }

            try {
                $decrypted = $this->encrypter->decrypt($encrypted, false);
            } catch (\Throwable $e) {
                // Almost always a rotated APP_KEY. Name the provider and the key,
                // never the ciphertext and never the plaintext.
                Log::error('[OAuth] Failed to decrypt a stored provider secret.', [
                    'provider' => $this->provider,
                    'key'      => $key,
                ]);

                throw new OAuthProviderNotConfiguredException($this->provider . '.' . $key);
            }

            if (is_string($decrypted) && $decrypted !== '') {
                return $decrypted;
            }
        }

        $plain = $this->values[$key] ?? null;

        if (is_string($plain) && $plain !== '') {
            return $plain;
        }

        throw new OAuthProviderNotConfiguredException($this->provider . '.' . $key);
    }

    /**
     * Whether a secret is present and usable, without throwing.
     */
    public function hasSecret(string $key): bool
    {
        try {
            $this->secret($key);

            return true;
        } catch (OAuthProviderNotConfiguredException $e) {
            return false;
        }
    }

    /**
     * Whether every credential the driver needs is present.
     *
     * @param array<int, string> $requiredKeys    plain values that must be non-empty
     * @param array<int, string> $requiredSecrets secrets that must resolve
     */
    public function isConfigured(array $requiredKeys, array $requiredSecrets = []): bool
    {
        foreach ($requiredKeys as $key) {
            if ($this->get($key) === null) {
                return false;
            }
        }

        foreach ($requiredSecrets as $key) {
            if (!$this->hasSecret($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The shape handed to an administrator.
     *
     * Secrets never leave the server, not even for an admin: each is reduced to
     * whether it is set plus a short trailing hint, which is enough to tell two
     * credentials apart when rotating without disclosing either.
     *
     * @param array<string, array{secret?: bool}> $schema
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(array $schema): array
    {
        $output = ['enabled' => $this->enabled()];

        foreach ($schema as $key => $definition) {
            if (($definition['secret'] ?? false) === true) {
                $output[$key] = [
                    'configured' => $this->hasSecret($key),
                    'hint'       => $this->hint($key),
                ];

                continue;
            }

            $output[$key] = $this->get($key);
        }

        return $output;
    }

    /**
     * The last four characters of a secret, masked.
     */
    private function hint(string $key): ?string
    {
        try {
            $secret = $this->secret($key);
        } catch (OAuthProviderNotConfiguredException $e) {
            return null;
        }

        // A short secret would be mostly disclosed by a 4-character tail.
        if (mb_strlen($secret) < 8) {
            return '••••';
        }

        return '••••' . mb_substr($secret, -4);
    }

    /**
     * The merged values, for the repository to re-serialize on save.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
