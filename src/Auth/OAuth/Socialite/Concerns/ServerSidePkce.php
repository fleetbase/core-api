<?php

namespace Fleetbase\Auth\OAuth\Socialite\Concerns;

use Illuminate\Support\Arr;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Drives Socialite's authorization-code flow without a session.
 *
 * Stock Socialite keeps both the CSRF `state` and the PKCE `code_verifier` in
 * `$request->session()` (AbstractProvider::redirect(), ::getCodeChallenge(),
 * ::getTokenFields()). Fleetbase's OAuth routes live in the public throttled
 * group, which has no StartSession middleware — only `fleetbase.protected` does —
 * and a session would not survive the provider's cross-site POST callback anyway.
 *
 * So state and the verifier are held server side in `oauth_states` and injected
 * here explicitly. The provider is put into `stateless()` mode to disable
 * Socialite's own session-backed state checking, and `state` is then added back to
 * the authorization URL by this trait, because it is still a required CSRF control —
 * it is simply validated against the database instead of the session.
 *
 * This lives on a subclass because getAuthUrl(), getAccessTokenResponse(),
 * getCodeFields(), getTokenFields() and userInstance() are all protected.
 */
trait ServerSidePkce
{
    /**
     * Parameters that belong on the authorization request only.
     *
     * Socialite merges $this->parameters into BOTH getCodeFields() and
     * getTokenFields(), so without this a `response_mode` or `prompt` set for the
     * authorization leg would also be POSTed to the token endpoint, where it is at
     * best ignored and at worst rejected.
     */
    private const AUTHORIZATION_ONLY_PARAMETERS = [
        'response_mode',
        'prompt',
        'hd',
        'login_hint',
        'access_type',
        'include_granted_scopes',
    ];

    protected ?string $serverCodeVerifier = null;

    protected ?string $serverState = null;

    /**
     * The provider's raw token response, kept for the life of the request so
     * id_token-based providers can read claims from it. Never persisted.
     *
     * @var array<string, mixed>
     */
    protected array $serverTokenResponse = [];

    /**
     * Supply the PKCE verifier for this exchange.
     */
    public function withServerSidePkce(string $verifier): static
    {
        $this->serverCodeVerifier = $verifier;

        return $this;
    }

    /**
     * Build the authorization URL the browser is redirected to.
     */
    public function buildAuthorizationUrl(string $state): string
    {
        $this->serverState = $state;

        return $this->getAuthUrl($state);
    }

    /**
     * Exchange an authorization code for the provider's raw token response.
     *
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $response = $this->getAccessTokenResponse($code);

        return is_array($response) ? $response : [];
    }

    /**
     * Resolve the provider's user from an already-exchanged token response.
     *
     * @param array<string, mixed> $tokenResponse
     */
    public function userFromTokenResponse(array $tokenResponse): SocialiteUser
    {
        $this->serverTokenResponse = $tokenResponse;

        return $this->userInstance(
            $tokenResponse,
            $this->getUserByToken(Arr::get($tokenResponse, 'access_token'))
        );
    }

    /**
     * The raw token response, for providers whose profile comes from the id_token.
     *
     * @return array<string, mixed>
     */
    public function serverTokenResponse(): array
    {
        return $this->serverTokenResponse;
    }

    /**
     * The id_token from the provider's token response, for providers whose profile
     * comes from the token rather than a userinfo call.
     *
     * Narrowed rather than cast: a non-string here means the provider returned
     * something unexpected, and an empty string makes the verifier reject it
     * explicitly instead of stringifying an array.
     */
    protected function serverIdToken(): string
    {
        $idToken = $this->serverTokenResponse['id_token'] ?? null;

        return is_string($idToken) ? $idToken : '';
    }

    /**
     * Add our own state and PKCE challenge to the authorization request.
     *
     * Deliberately does not call enablePKCE(): that flag makes the parent read the
     * verifier out of the session in both getCodeFields() and getTokenFields(),
     * which is precisely what this trait exists to avoid.
     *
     * @param string|null $state
     *
     * @return array<string, mixed>
     */
    protected function getCodeFields($state = null)
    {
        $fields = parent::getCodeFields($state);

        if ($this->serverState !== null) {
            $fields['state'] = $this->serverState;
        }

        if ($this->serverCodeVerifier !== null) {
            $fields['code_challenge']        = $this->serverCodeChallenge();
            $fields['code_challenge_method'] = 'S256';
        }

        return $fields;
    }

    /**
     * Add the PKCE verifier to the token request.
     *
     * @param string $code
     *
     * @return array<string, mixed>
     */
    protected function getTokenFields($code)
    {
        $fields = parent::getTokenFields($code);

        foreach (self::AUTHORIZATION_ONLY_PARAMETERS as $parameter) {
            unset($fields[$parameter]);
        }

        if ($this->serverCodeVerifier !== null) {
            $fields['code_verifier'] = $this->serverCodeVerifier;
        }

        return $fields;
    }

    /**
     * RFC 7636 S256: base64url(sha256(verifier)), unpadded.
     */
    protected function serverCodeChallenge(): string
    {
        return rtrim(
            strtr(base64_encode(hash('sha256', (string) $this->serverCodeVerifier, true)), '+/', '-_'),
            '='
        );
    }
}
