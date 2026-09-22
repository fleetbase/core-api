<?php

namespace Fleetbase\Auth\OAuth;

use Fleetbase\Auth\OAuth\Contracts\OAuthProviderDriver;
use Fleetbase\Auth\OAuth\Exceptions\OAuthProviderNotConfiguredException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
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
     * The HTTP client used to talk to the provider. Defaults to a fresh client with
     * short timeouts; tests hand in one backed by canned responses.
     */
    protected ?HttpClient $http = null;

    public function useHttpClient(?HttpClient $http): static
    {
        $this->http = $http;

        return $this;
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

    public function verifyCredentials(string $redirectUri): CredentialCheck
    {
        try {
            // A code that cannot exist, and a throwaway verifier to go with it: the
            // provider must authenticate the client before it can reject either.
            $response = $this->build($redirectUri)
                ->withServerSidePkce(bin2hex(random_bytes(32)))
                ->exchangeAuthorizationCode('fleetbase-credential-check-' . bin2hex(random_bytes(8)));
        } catch (OAuthProviderNotConfiguredException $e) {
            return CredentialCheck::InvalidClient;
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;

            if ($status < 400 || $status >= 500) {
                return CredentialCheck::Unreachable;
            }

            $body = json_decode((string) $e->getResponse()->getBody(), true);

            return CredentialCheck::fromTokenError(is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null);
        } catch (GuzzleException $e) {
            return CredentialCheck::Unreachable;
        }

        // GitHub reports errors in a 200 response body.
        $error = $response['error'] ?? null;

        if (is_string($error)) {
            return CredentialCheck::fromTokenError($error);
        }

        // A token for a code that was never issued would be a provider bug; there is
        // nothing to conclude from it about these credentials.
        return CredentialCheck::Inconclusive;
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
        $provider->setHttpClient($this->http ?? new HttpClient(['timeout' => 10, 'connect_timeout' => 5]));

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
