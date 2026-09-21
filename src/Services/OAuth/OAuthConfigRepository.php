<?php

namespace Fleetbase\Services\OAuth;

use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Fleetbase\Models\Setting;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/**
 * Resolves OAuth configuration, layering database settings over env-backed defaults.
 *
 * Resolution order per field: `system.oauth` in the settings table → config/oauth.php
 * (which reads the environment) → null. That ordering is what lets a self-hosted
 * operator configure everything through the environment while a Cloud admin
 * configures the same fields through the console.
 *
 * This deliberately does NOT go through EnvironmentMapper / MergeConfigFromSettings,
 * for three reasons:
 *
 *   1. That middleware is global, so anything routed through it lands in config() on
 *      every request, readable by anything that dumps config('services') — Sentry
 *      context, `artisan config:show`, error pages.
 *   2. It copies setting values verbatim and has no hook to decrypt, so an encrypted
 *      secret would arrive as ciphertext.
 *   3. It uses putenv(), which under Octane writes into a long-lived worker process
 *      and would leak secrets across unrelated requests.
 */
class OAuthConfigRepository
{
    /**
     * The single settings row holding all OAuth configuration.
     */
    public const SETTINGS_KEY = 'oauth';

    public function __construct(private ?Encrypter $encrypter = null)
    {
    }

    /**
     * Whether OAuth is switched on at all.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->globalValue('enabled', config('oauth.enabled', true));
    }

    /**
     * Whether an unrecognised provider identity may start a Fleetbase signup.
     *
     * Separate from isEnabled() so an operator can offer OAuth sign-in to existing
     * users without opening self-service registration.
     */
    public function allowsRegistration(): bool
    {
        return (bool) $this->globalValue('allow_registration', config('oauth.allow_registration', true));
    }

    /**
     * Whether a provider identity may be linked automatically to an existing
     * account whose confirmed email it matches. See OAuthController::autoLinkCandidate()
     * for the conditions; this only switches the behaviour on or off.
     */
    public function autoLinksVerifiedEmail(): bool
    {
        return (bool) $this->globalValue('auto_link', config('oauth.auto_link', true));
    }

    /**
     * The public origin of this API, used to build the redirect_uri handed to
     * providers. Must match what is registered in each provider's console.
     */
    public function redirectBase(): string
    {
        $base = $this->globalValue('redirect_base', config('oauth.redirect_base'));

        if (!is_string($base) || $base === '') {
            $base = (string) config('app.url');
        }

        return rtrim($base, '/');
    }

    public function consoleCallbackPath(): string
    {
        $path = $this->globalValue('console_callback_path', config('oauth.console_callback_path', '/auth/oauth/callback'));

        return is_string($path) && $path !== '' ? $path : '/auth/oauth/callback';
    }

    /**
     * Resolved configuration for one provider.
     */
    public function forProvider(string $provider): OAuthProviderConfig
    {
        return new OAuthProviderConfig($provider, $this->mergedValues($provider), $this->encrypter);
    }

    /**
     * Configuration for one provider as it would be after saving $draft.
     *
     * Held in memory only: nothing is written. Draft secrets stay plaintext and
     * replace any stored ciphertext; an empty draft secret keeps the stored one,
     * exactly as save() treats it.
     *
     * @param array<string, mixed> $draft
     * @param array<int, string>   $secretKeys
     */
    public function draftFor(string $provider, array $draft, array $secretKeys = []): OAuthProviderConfig
    {
        $values = $this->mergedValues($provider);

        foreach ($draft as $key => $value) {
            if ($key === 'driver') {
                continue;
            }

            if (in_array($key, $secretKeys, true)) {
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }

                $values[$key] = trim($value);
                unset($values[$key . OAuthProviderConfig::ENCRYPTED_SUFFIX]);

                continue;
            }

            $values[$key] = is_string($value) ? trim($value) : $value;
        }

        return new OAuthProviderConfig($provider, $values, $this->encrypter);
    }

    /**
     * The provider ids this installation defines.
     *
     * @return array<int, string>
     */
    public function providerIds(): array
    {
        $providers = config('oauth.providers', []);

        return is_array($providers) ? array_keys($providers) : [];
    }

    /**
     * The driver class name for a provider id, or null when it is not defined.
     *
     * Returned as a plain string: the registry is what validates that the class
     * exists and implements the driver contract before instantiating anything.
     */
    public function driverClass(string $provider): ?string
    {
        $class = config('oauth.providers.' . $provider . '.driver');

        return is_string($class) && $class !== '' ? $class : null;
    }

    /**
     * How long a token of the given purpose lives.
     */
    public function ttl(string $purpose, int $default): int
    {
        $ttl = config('oauth.ttl.' . $purpose, $default);

        return is_numeric($ttl) ? (int) $ttl : $default;
    }

    /**
     * Persist a partial configuration change.
     *
     * Read-modify-write under a row lock: Setting::configure() is an updateOrCreate
     * over a single JSON blob, so two administrators saving different providers at
     * the same time would otherwise silently discard one of the two edits.
     *
     * Secrets are encrypted here, under a distinct `<key>_encrypted` name. An empty
     * incoming secret means "leave the stored one alone", which is what lets the
     * admin UI render a masked placeholder instead of the real value.
     *
     * @param array<string, mixed>                $global
     * @param array<string, array<string, mixed>> $providers
     * @param array<string, array<int, string>>   $secretKeys provider => secret field names
     */
    public function save(array $global, array $providers = [], array $secretKeys = []): void
    {
        DB::transaction(function () use ($global, $providers, $secretKeys): void {
            $current = Setting::query()
                ->where('key', 'system.' . self::SETTINGS_KEY)
                ->lockForUpdate()
                ->first();

            $stored = is_array($current->value ?? null) ? $current->value : [];

            foreach ($global as $key => $value) {
                $stored[$key] = $value;
            }

            $storedProviders = is_array($stored['providers'] ?? null) ? $stored['providers'] : [];

            foreach ($providers as $provider => $values) {
                $existing = is_array($storedProviders[$provider] ?? null) ? $storedProviders[$provider] : [];
                $secrets  = $secretKeys[$provider] ?? [];

                foreach ($values as $key => $value) {
                    if (in_array($key, $secrets, true)) {
                        // Empty means "keep what is stored" — the admin UI never
                        // receives the real value, so it cannot echo it back.
                        if (!is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $existing[$key . OAuthProviderConfig::ENCRYPTED_SUFFIX] = $this->encrypt(trim($value));
                        // Drop any plaintext left by an earlier environment-based setup
                        // so the encrypted copy is unambiguously authoritative.
                        unset($existing[$key]);

                        continue;
                    }

                    $existing[$key] = $value;
                }

                $storedProviders[$provider] = $existing;
            }

            $stored['providers'] = $storedProviders;

            Setting::configureSystem(self::SETTINGS_KEY, $stored);
        });
    }

    /**
     * The whole configuration as an administrator should see it: no secret values,
     * only whether each is set plus a short hint.
     *
     * @param array<string, array<string, array{label: string, secret?: bool, required?: bool, help?: string}>> $schemas
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(array $schemas): array
    {
        $providers = [];

        foreach ($schemas as $provider => $schema) {
            $providers[$provider] = $this->forProvider($provider)->toAdminArray($schema);
        }

        return [
            'enabled'            => $this->isEnabled(),
            'allow_registration' => $this->allowsRegistration(),
            'auto_link'          => $this->autoLinksVerifiedEmail(),
            'providers'          => $providers,
        ];
    }

    /**
     * Database settings layered over the config defaults for one provider.
     *
     * @return array<string, mixed>
     */
    protected function mergedValues(string $provider): array
    {
        $defaults = config('oauth.providers.' . $provider, []);
        $defaults = is_array($defaults) ? $defaults : [];

        $stored = $this->storedSettings();
        $values = $stored['providers'][$provider] ?? [];

        $merged = array_merge($defaults, is_array($values) ? $values : []);

        // The driver class is wiring, not configuration, and must never be settable
        // from the database — that would be arbitrary class instantiation. Stripped
        // AFTER the merge, because a settings row could otherwise reintroduce it.
        unset($merged['driver']);

        return $merged;
    }

    protected function globalValue(string $key, mixed $default): mixed
    {
        $stored = $this->storedSettings();

        return array_key_exists($key, $stored) ? $stored[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    protected function storedSettings(): array
    {
        $stored = Setting::system(self::SETTINGS_KEY);

        return is_array($stored) ? $stored : [];
    }

    protected function encrypt(string $value): string
    {
        if (!$this->encrypter instanceof Encrypter) {
            throw new \RuntimeException('Cannot store an OAuth secret without an encrypter.');
        }

        return $this->encrypter->encrypt($value, false);
    }
}
