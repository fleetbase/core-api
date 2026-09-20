<?php

namespace Fleetbase\Auth\OAuth;

use Fleetbase\Auth\OAuth\Contracts\OAuthProviderDriver;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\AbstractProvider as SocialiteProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Shared wiring for every provider driver.
 *
 * Subclasses supply policy — which Socialite class, which scopes, and above all how
 * to decide whether the provider actually vouched for the email address. That last
 * decision is per-provider and is the single most security-sensitive thing a driver
 * does, which is why it is an abstract method rather than a shared default.
 *
 * Socialite providers are constructed directly rather than through SocialiteManager:
 * the manager exists to read Laravel's `config/services.php` and resolve the current
 * request from the container, and Fleetbase does neither — configuration comes from
 * OAuthConfigRepository and the request is injected.
 */
abstract class AbstractOAuthProviderDriver implements OAuthProviderDriver
{
    public function __construct(
        protected OAuthProviderConfig $config,
        protected Request $request,
        protected IdTokenVerifier $idTokenVerifier,
    ) {
    }

    /**
     * The Socialite provider class backing this driver.
     *
     * @return class-string<SocialiteProvider>
     */
    abstract protected function socialiteProviderClass(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function scopes(): array;

    /**
     * Turn a provider response into a normalized Fleetbase profile.
     *
     * @param array<string, mixed> $callbackPayload
     */
    abstract protected function normalize(SocialiteUser $user, array $callbackPayload): OAuthUserProfile;

    /**
     * Plain config keys that must be present.
     *
     * @return array<int, string>
     */
    protected static function requiredKeys(): array
    {
        return ['client_id'];
    }

    /**
     * Secrets that must resolve.
     *
     * @return array<int, string>
     */
    protected static function requiredSecrets(): array
    {
        return ['client_secret'];
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured(static::requiredKeys(), static::requiredSecrets());
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled() && $this->isConfigured();
    }

    public function usesFormPostCallback(): bool
    {
        return false;
    }

    public function authorizationUrl(string $state, string $codeVerifier, string $redirectUri): string
    {
        // withSecret: false — the authorization request is not authenticated, and for
        // Apple resolving the secret means signing a fresh ES256 assertion. Minting
        // one here would be wasted work on every redirect, and would turn a bad .p8
        // into a confusing failure at redirect time rather than at token exchange.
        return $this->build($redirectUri, false)
            ->withServerSidePkce($codeVerifier)
            ->with($this->additionalAuthorizationParameters())
            ->buildAuthorizationUrl($state);
    }

    public function exchange(string $code, string $codeVerifier, string $redirectUri, array $callbackPayload = []): OAuthUserProfile
    {
        $provider = $this->build($redirectUri)->withServerSidePkce($codeVerifier);

        $tokenResponse = $provider->exchangeAuthorizationCode($code);
        $socialiteUser = $provider->userFromTokenResponse($tokenResponse);

        // The raw token response rides along in memory so a driver can read id_token
        // claims during normalization. It is dropped before anything is persisted or
        // serialized — Fleetbase never stores provider access or refresh tokens.
        return $this->normalize($socialiteUser, $callbackPayload)->withRawTokenResponse($tokenResponse);
    }

    /**
     * The configured client id, narrowed to a string.
     */
    protected function clientId(): string
    {
        $clientId = $this->config->get('client_id');

        return is_scalar($clientId) ? (string) $clientId : '';
    }

    /**
     * The client secret handed to the provider. Apple overrides this to mint one.
     */
    protected function clientSecret(): string
    {
        return $this->config->secret('client_secret');
    }

    /**
     * Extra parameters for the authorization request only.
     *
     * @return array<string, string>
     */
    protected function additionalAuthorizationParameters(): array
    {
        return [];
    }

    /**
     * Construct and configure the Socialite provider.
     *
     * `stateless()` disables Socialite's session-backed state checking; state is
     * still sent and is validated against oauth_states instead. See ServerSidePkce.
     */
    protected function build(string $redirectUri, bool $withSecret = true): SocialiteProvider
    {
        $class = $this->socialiteProviderClass();

        $provider = new $class(
            $this->request,
            $this->clientId(),
            $withSecret ? $this->clientSecret() : '',
            $redirectUri
        );

        $provider->stateless()->setScopes($this->scopes());

        return $this->configureProvider($provider);
    }

    /**
     * Hook for drivers that need to inject extra collaborators into the provider.
     */
    protected function configureProvider(SocialiteProvider $provider): SocialiteProvider
    {
        return $provider;
    }

    /**
     * Read a claim from the verified token claims a provider returned as its raw
     * user payload.
     */
    protected function rawClaim(SocialiteUser $user, string $claim, mixed $default = null): mixed
    {
        $raw = $user->getRaw();

        return is_array($raw) ? ($raw[$claim] ?? $default) : $default;
    }
}
