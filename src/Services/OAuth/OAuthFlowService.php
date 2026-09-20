<?php

namespace Fleetbase\Services\OAuth;

use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\Exceptions\OAuthStateException;
use Fleetbase\Auth\OAuth\OAuthProviderRegistry;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Models\OAuthState;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the two server-side legs of the OAuth handshake.
 *
 *   startAuthorization() — issue state + PKCE verifier, return the provider URL
 *   handleCallback()     — validate state, exchange the code, hand the resolved
 *                          identity to the console through a one-time handoff code
 *
 * Nothing here trusts the request for anything structural: the redirect_uri is
 * computed from configuration, the console host comes from the console config, and
 * the only request-supplied value that survives is a relative return path, which is
 * validated to be exactly that.
 */
class OAuthFlowService
{
    public const INTENT_LOGIN  = 'login';
    public const INTENT_SIGNUP = 'signup';
    public const INTENT_LINK   = 'link';

    /**
     * A safe return path: absolute-from-root, no scheme, no host, no backslashes,
     * no control characters, and not protocol-relative.
     */
    public const RETURN_PATH_PATTERN = '#^/(?!/)[A-Za-z0-9\-._~!$&\'()*+,;=:@%/?]*$#';

    public function __construct(
        private OAuthProviderRegistry $registry,
        private OAuthStateService $states,
        private OAuthConfigRepository $config,
    ) {
    }

    /**
     * Begin authorization and return the provider URL to redirect the browser to.
     *
     * @throws OAuthException
     */
    public function startAuthorization(
        string $provider,
        string $intent = self::INTENT_LOGIN,
        ?string $returnTo = null,
        ?string $ip = null,
        ?string $userUuid = null,
    ): string {
        $driver = $this->registry->driver($provider);

        if (!$driver->isEnabled()) {
            throw new OAuthException('provider_disabled');
        }

        // 96 characters of CSPRNG output, comfortably inside RFC 7636's 43-128 range.
        $codeVerifier = $this->generateCodeVerifier();
        $redirectUri  = $this->redirectUri($provider);

        $state = $this->states->issue(
            OAuthState::PURPOSE_AUTHORIZATION,
            [
                'code_verifier' => $codeVerifier,
                'return_to'     => $this->sanitizeReturnPath($returnTo),
                'redirect_uri'  => $redirectUri,
            ],
            $this->config->ttl('authorization', 600),
            $provider,
            $intent,
            $userUuid,
            $ip
        );

        return $driver->authorizationUrl($state, $codeVerifier, $redirectUri);
    }

    /**
     * Handle the provider's callback and return the console URL to redirect to.
     *
     * Always returns a URL — a failure becomes `#error=<code>` on the console
     * callback route rather than an API error page, because this leg is a top-level
     * browser navigation and the user must land somewhere useful.
     */
    public function handleCallback(string $provider, Request $request): string
    {
        $state = $request->input('state');

        if (!is_string($state) || $state === '') {
            return $this->consoleUrl(['error' => 'invalid_state']);
        }

        try {
            $consumed = $this->states->consume(OAuthState::PURPOSE_AUTHORIZATION, $state, $request->ip());
        } catch (OAuthStateException $e) {
            // Unknown, expired, replayed, or issued for another purpose. No token
            // exchange is attempted.
            return $this->consoleUrl(['error' => 'invalid_state']);
        }

        /** @var OAuthState $stateRow */
        $stateRow = $consumed['state'];
        $payload  = $consumed['payload'];
        $returnTo = is_string($payload['return_to'] ?? null) ? $payload['return_to'] : null;

        // The state row records which provider it was issued for; a callback that
        // arrives on a different provider's route is a mismatch, not a coincidence.
        if ($stateRow->provider !== null && $stateRow->provider !== $provider) {
            return $this->consoleUrl(['error' => 'invalid_state'], $returnTo);
        }

        // The user declined at the provider, or the provider refused outright.
        if ($request->filled('error')) {
            // Narrowed rather than cast: a provider sending `error[]=x` would make a
            // string cast emit a notice mid-redirect.
            $error = $request->input('error');
            $error = is_string($error) ? $error : '';

            return $this->consoleUrl([
                'error' => $error === 'access_denied' ? 'access_denied' : 'provider_error',
            ], $returnTo);
        }

        $code = $request->input('code');

        if (!is_string($code) || $code === '') {
            return $this->consoleUrl(['error' => 'missing_code'], $returnTo);
        }

        try {
            $driver = $this->registry->driver($provider);

            if (!$driver->isEnabled()) {
                return $this->consoleUrl(['error' => 'provider_disabled'], $returnTo);
            }

            $profile = $driver->exchange(
                $code,
                is_string($payload['code_verifier'] ?? null) ? $payload['code_verifier'] : '',
                is_string($payload['redirect_uri'] ?? null) ? $payload['redirect_uri'] : $this->redirectUri($provider),
                $request->all()
            );
        } catch (OAuthException $e) {
            // A policy failure the user can act on, e.g. hosted_domain_mismatch.
            return $this->consoleUrl(['error' => $e->getMessage()], $returnTo);
        } catch (\Throwable $e) {
            // Network, provider outage, malformed response. Never surface the
            // underlying message — it can embed a token or a response body.
            Log::error('[OAuth] Authorization code exchange failed.', ['provider' => $provider]);

            return $this->consoleUrl(['error' => 'exchange_failed'], $returnTo);
        }

        $handoff = $this->states->issue(
            OAuthState::PURPOSE_HANDOFF,
            [
                'profile'   => $profile->jsonSerialize(),
                'intent'    => $stateRow->intent ?? self::INTENT_LOGIN,
                'return_to' => $returnTo,
            ],
            $this->config->ttl('handoff', 120),
            $provider,
            $stateRow->intent,
            $stateRow->user_uuid,
            $request->ip()
        );

        return $this->consoleUrl(['handoff' => $handoff], $returnTo);
    }

    /**
     * The callback URL registered with the provider.
     *
     * Always computed, never read from the request: a request-supplied redirect_uri
     * is the classic way to turn an OAuth client into an open redirector. The same
     * string is used for both the authorization and token requests because providers
     * require them to match byte for byte.
     */
    public function redirectUri(string $provider): string
    {
        $base = $this->config->redirectBase();

        $segments = array_filter([
            trim((string) config('fleetbase.api.routing.prefix', ''), '/'),
            trim((string) config('fleetbase.api.routing.internal_prefix', 'int'), '/'),
            'v1',
            'auth/oauth',
            $provider,
            'callback',
        ], fn ($segment) => $segment !== '');

        return rtrim($base, '/') . '/' . implode('/', $segments);
    }

    /**
     * Build the console URL the browser is returned to.
     *
     * Parameters go in the FRAGMENT, never the query string: a fragment is not sent
     * to the console's web server, so the handoff code never reaches an access log
     * or a Referer header.
     *
     * @param array<string, string> $fragment
     */
    public function consoleUrl(array $fragment, ?string $returnTo = null): string
    {
        $path     = $this->config->consoleCallbackPath();
        $returnTo = $this->sanitizeReturnPath($returnTo);

        if ($returnTo !== null) {
            $fragment['return_to'] = $returnTo;
        }

        return Utils::consoleUrl($path) . '#' . http_build_query($fragment);
    }

    /**
     * Reduce a caller-supplied return target to a safe relative path, or null.
     *
     * Rejects absolute URLs, protocol-relative `//evil.tld`, backslash variants,
     * and anything carrying a control character. Nothing attacker-controlled is ever
     * allowed to influence the host.
     */
    public function sanitizeReturnPath(?string $returnTo): ?string
    {
        if (!is_string($returnTo) || $returnTo === '') {
            return null;
        }

        if (strlen($returnTo) > 512) {
            return null;
        }

        // Control characters (including CR/LF) would allow header or fragment
        // splitting downstream.
        if (preg_match('/[\x00-\x1F\x7F]/', $returnTo) === 1) {
            return null;
        }

        if (str_contains($returnTo, '\\')) {
            return null;
        }

        return preg_match(self::RETURN_PATH_PATTERN, $returnTo) === 1 ? $returnTo : null;
    }

    /**
     * RFC 7636 code verifier: 43-128 characters from the unreserved set.
     */
    protected function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    /**
     * Read the profile back out of a redeemed handoff payload.
     *
     * @param array<string, mixed> $payload
     */
    public function profileFromPayload(array $payload): OAuthUserProfile
    {
        $profile = $payload['profile'] ?? [];

        return OAuthUserProfile::fromArray(is_array($profile) ? $profile : []);
    }
}
