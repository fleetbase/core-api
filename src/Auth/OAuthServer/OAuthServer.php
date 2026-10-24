<?php

namespace Fleetbase\Auth\OAuthServer;

use Fleetbase\Auth\OAuthServer\Entities\UserEntity;
use Fleetbase\Auth\OAuthServer\Exceptions\AuthorizeError;
use Fleetbase\Auth\OAuthServer\Exceptions\TokenError;
use Fleetbase\Auth\OAuthServer\Repositories\AccessTokenRepository;
use Fleetbase\Auth\OAuthServer\Repositories\AuthCodeRepository;
use Fleetbase\Auth\OAuthServer\Repositories\ClientRepository;
use Fleetbase\Auth\OAuthServer\Repositories\RefreshTokenRepository;
use Fleetbase\Auth\OAuthServer\Repositories\ScopeRepository;
use Fleetbase\Models\Company;
use Fleetbase\Models\OAuthAccessToken;
use Fleetbase\Models\OAuthAuthCode;
use Fleetbase\Models\OAuthClient;
use Fleetbase\Models\OAuthGrant;
use Fleetbase\Models\OAuthRefreshToken;
use Fleetbase\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Fleetbase as an OAuth 2.0 / OpenID Connect provider, on league/oauth2-server.
 *
 * League does the protocol and the crypto: code and refresh-token payload encryption, PKCE
 * verification, JWT signing and validation. This class adds Fleetbase's rules around it:
 *
 * - Only the authorization code grant (with PKCE S256, for every client) and refresh tokens.
 * - Exact redirect URI matching; errors never redirect to an unverified URI.
 * - Every code and token is bound to a grant (the user's consent for one app) and, when the
 *   scopes need one, to the organization the user picked. The binding is re-checked on every
 *   refresh and every API call.
 * - Replayed codes revoke their tokens; replayed refresh tokens revoke the grant.
 */
class OAuthServer
{
    public function __construct(protected ?OAuthKeys $keys = null)
    {
        $this->keys ??= new OAuthKeys();
    }

    public function keys(): OAuthKeys
    {
        return $this->keys;
    }

    /**
     * The issuer identifier, without a trailing slash.
     */
    public function issuer(): string
    {
        $issuer = config('oauth-server.issuer') ?: config('app.url');

        return rtrim((string) $issuer, '/');
    }

    public function endpoint(string $path): string
    {
        return $this->issuer() . '/' . ltrim($path, '/');
    }

    public function ttl(string $name): \DateInterval
    {
        return new \DateInterval((string) config("oauth-server.ttl.{$name}", ['auth_code' => 'PT10M', 'access_token' => 'PT1H', 'refresh_token' => 'P30D', 'id_token' => 'PT1H'][$name]));
    }

    /**
     * League's authorization server, wired to Fleetbase's repositories for one request.
     */
    public function authorizationServer(IssueContext $context): AuthorizationServer
    {
        $server = new AuthorizationServer(
            new ClientRepository(),
            new AccessTokenRepository($context, $this->issuer(), $this->keys->keyId()),
            new ScopeRepository(),
            $this->keys->privateKey(),
            $this->keys->encryptionKey(),
            new IdTokenResponse($context, $this->keys, $this->issuer(), $this->ttl('id_token'))
        );

        $authCodeGrant = new AuthCodeGrant(new AuthCodeRepository($context), new RefreshTokenRepository($context), $this->ttl('auth_code'));
        $authCodeGrant->setRefreshTokenTTL($this->ttl('refresh_token'));
        $server->enableGrantType($authCodeGrant, $this->ttl('access_token'));

        $refreshTokenGrant = new RefreshTokenGrant(new RefreshTokenRepository($context));
        $refreshTokenGrant->setRefreshTokenTTL($this->ttl('refresh_token'));
        $server->enableGrantType($refreshTokenGrant, $this->ttl('access_token'));

        return $server;
    }

    public function resourceServer(): ResourceServer
    {
        return new ResourceServer(new AccessTokenRepository(new IssueContext(), $this->issuer()), $this->keys->publicKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization (the consent screen)
    |--------------------------------------------------------------------------
    */

    /**
     * Check an authorization request's parameters.
     *
     * @param array<string, mixed> $params the authorization request's query parameters
     *
     * @throws AuthorizeError
     */
    public function validateAuthorizationRequest(array $params): ValidatedAuthorization
    {
        $string = fn (string $key) => is_string($params[$key] ?? null) && $params[$key] !== '' ? $params[$key] : null;

        $client = (new ClientRepository())->findActive($string('client_id'));
        if (!$client) {
            throw new AuthorizeError('invalid_client', 'This app is not registered with Fleetbase, or it has been turned off.');
        }

        $redirectUri = $string('redirect_uri');
        if (!$client->allowsRedirectUri($redirectUri)) {
            throw new AuthorizeError('invalid_request', "The redirect URI doesn't match one registered for {$client->name}.");
        }

        // From here on the redirect URI is trusted, so errors go back to the app.
        $state = $string('state');
        $fail  = fn (string $error, string $description) => new AuthorizeError($error, $description, $redirectUri, $state);

        foreach ($params as $key => $value) {
            if (!is_string($value)) {
                throw $fail('invalid_request', "The parameter `{$key}` must be a single value.");
            }
        }

        if ($string('response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }

        if (!$string('code_challenge')) {
            throw $fail('invalid_request', 'PKCE is required: send code_challenge and code_challenge_method=S256.');
        }

        if ($string('code_challenge_method') !== 'S256') {
            throw $fail('invalid_request', 'code_challenge_method must be S256.');
        }

        $scopes = array_values(array_unique(array_filter(explode(' ', (string) $string('scope')))));
        if (!$scopes) {
            throw $fail('invalid_scope', 'Ask for at least one scope.');
        }
        foreach ($scopes as $scope) {
            if (!OAuthScopes::has($scope) || !$client->allowsScope($scope)) {
                throw $fail('invalid_scope', "The scope `{$scope}` isn't available to this app.");
            }
        }

        $prompt = array_values(array_filter(explode(' ', (string) $string('prompt'))));
        if (in_array('none', $prompt, true)) {
            // There is no silent authorization: the consent screen runs in the console, which
            // the app can't check without showing it.
            throw $fail(count($prompt) > 1 ? 'invalid_request' : 'interaction_required', 'prompt=none is not supported; the user has to see the consent screen.');
        }

        $nonce = $string('nonce');
        if ($nonce !== null && strlen($nonce) > 255) {
            throw $fail('invalid_request', 'nonce must be at most 255 characters.');
        }

        $maxAge = $string('max_age');
        if ($maxAge !== null && !ctype_digit($maxAge)) {
            throw $fail('invalid_request', 'max_age must be a whole number of seconds.');
        }

        try {
            $request = $this->authorizationServer(new IssueContext())->validateAuthorizationRequest($this->queryRequest($params));
        } catch (OAuthServerException $e) {
            throw $fail($e->getErrorType(), $e->getHint() ?: $e->getMessage());
        }

        return new ValidatedAuthorization($client, $request, $scopes, $redirectUri, $state, $nonce, $prompt, $maxAge !== null ? (int) $maxAge : null);
    }

    /**
     * Whether the user has to sign in again first (`prompt=login`, or `max_age` exceeded).
     */
    public function needsReauthentication(ValidatedAuthorization $authorization, ?\DateTimeInterface $authTime): bool
    {
        if (!$authTime) {
            return $authorization->prompts('login') || $authorization->maxAge !== null;
        }

        $age = time() - $authTime->getTimestamp();
        if ($authorization->prompts('login') && $age > (int) config('oauth-server.reauthenticate_after', 300)) {
            return true;
        }

        return $authorization->maxAge !== null && $age > $authorization->maxAge;
    }

    /**
     * The user's live grant for this app and organization, if any.
     */
    public function existingGrant(User $user, OAuthClient $client, ?Company $company): ?OAuthGrant
    {
        return OAuthGrant::active()
            ->where('user_uuid', $user->uuid)
            ->where('client_id', $client->id)
            ->where(fn ($query) => $company ? $query->where('company_uuid', $company->uuid) : $query->whereNull('company_uuid'))
            ->first();
    }

    /**
     * Whether the consent screen can be skipped: sign-in only, already approved, not forced.
     */
    public function canSkipConsent(ValidatedAuthorization $authorization, User $user): bool
    {
        if ($authorization->requiresOrganization() || $authorization->prompts('consent')) {
            return false;
        }

        $grant = $this->existingGrant($user, $authorization->client, null);

        return $grant && empty(array_diff($authorization->scopes, $grant->scopes ?? []));
    }

    /**
     * The user approved: record the grant, issue the code and return where to send the browser.
     *
     * @return array{redirect: string, grant: OAuthGrant, created: bool}
     *
     * @throws AuthorizeError when the organization can't be shared
     */
    public function approve(ValidatedAuthorization $authorization, User $user, ?Company $company, ?\DateTimeInterface $authTime): array
    {
        if ($authorization->requiresOrganization()) {
            if (!$company || !OAuthOrganizations::canShare($user, $company)) {
                throw new AuthorizeError('access_denied', 'Choose an organization you administer to share with this app.');
            }
        } else {
            $company = null;
        }

        [$grant, $created] = DB::connection((new OAuthGrant())->getConnectionName())->transaction(function () use ($authorization, $user, $company) {
            $grant   = $this->existingGrant($user, $authorization->client, $company);
            $created = $grant === null;
            $grant ??= new OAuthGrant([
                'user_uuid'    => $user->uuid,
                'client_id'    => $authorization->client->id,
                'company_uuid' => $company?->uuid,
            ]);
            $grant->scopes = array_values(array_unique(array_merge($grant->scopes ?? [], $authorization->scopes)));
            $grant->save();

            return [$grant, $created];
        });

        $context           = IssueContext::forGrant($grant->uuid, $company?->uuid);
        $context->nonce    = $authorization->nonce;
        $context->authTime = $authTime;

        $authorization->request->setUser(new UserEntity($user->uuid));
        $authorization->request->setAuthorizationApproved(true);

        $response = $this->authorizationServer($context)->completeAuthorizationRequest($authorization->request, new Psr7Response());

        return [
            'redirect' => $this->withIssuer($response->getHeaderLine('Location')),
            'grant'    => $grant,
            'created'  => $created,
        ];
    }

    /**
     * The user declined: where to send the browser.
     */
    public function deny(ValidatedAuthorization $authorization): string
    {
        return (new AuthorizeError('access_denied', 'The user declined to share access.', $authorization->redirectUri, $authorization->state))->redirectUrl($this->issuer());
    }

    /*
    |--------------------------------------------------------------------------
    | Token endpoint
    |--------------------------------------------------------------------------
    */

    public function respondToTokenRequest(Request $request): Response
    {
        $context = new IssueContext();

        try {
            $this->loadTokenContext($request->request->all(), $context);
            $response = $this->authorizationServer($context)->respondToAccessTokenRequest($this->psrRequest($request), new Psr7Response());

            if ($context->grantUuid) {
                OAuthGrant::find($context->grantUuid)?->touchLastUsed();
            }
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(new Psr7Response());
        }

        return $this->laravelResponse($response);
    }

    /**
     * Find the grant and organization behind the code or refresh token being redeemed.
     *
     * @param array<string, mixed> $params
     *
     * @throws OAuthServerException when the grant no longer allows new tokens
     */
    protected function loadTokenContext(array $params, IssueContext $context): void
    {
        $crypt = new PayloadCrypt($this->keys->encryptionKey());

        if (($params['grant_type'] ?? null) === 'authorization_code') {
            $payload = $crypt->open($params['code'] ?? null);
            $code    = isset($payload['auth_code_id']) ? OAuthAuthCode::find($payload['auth_code_id']) : null;
            if (!$code) {
                return;
            }

            $context->grantUuid   = $code->grant_uuid;
            $context->companyUuid = $code->company_uuid;
            $context->nonce       = $code->nonce;
            $context->authTime    = $code->auth_time;
            $context->authCodeId  = $code->id;

            // A replayed code is left to league, whose revoked-code check also revokes its tokens.
            if (!$code->revoked) {
                $this->assertGrantUsable(OAuthGrant::find($code->grant_uuid));
            }

            return;
        }

        if (($params['grant_type'] ?? null) === 'refresh_token') {
            $payload      = $crypt->open($params['refresh_token'] ?? null);
            $refreshToken = isset($payload['refresh_token_id']) ? OAuthRefreshToken::find($payload['refresh_token_id']) : null;
            if (!$refreshToken) {
                return;
            }

            $grant                = OAuthGrant::find($refreshToken->grant_uuid);
            $context->grantUuid   = $refreshToken->grant_uuid;
            $context->companyUuid = $grant?->company_uuid;
            $context->authCodeId  = OAuthAccessToken::where('id', $refreshToken->access_token_id)->value('auth_code_id');

            // A replayed refresh token is left to league, whose revoked check revokes the grant.
            if (!$refreshToken->revoked) {
                $this->assertGrantUsable($grant);
            }
        }
    }

    /**
     * @throws OAuthServerException
     */
    protected function assertGrantUsable(?OAuthGrant $grant): void
    {
        if (!$grant || $grant->isRevoked()) {
            throw OAuthServerException::invalidGrant('The user removed this app\'s access.');
        }

        $user = User::where('uuid', $grant->user_uuid)->first();
        if (!$user || !OAuthOrganizations::userIsActive($user)) {
            $grant->revoke();

            throw OAuthServerException::invalidGrant('The account that authorized this app is no longer active.');
        }

        if ($grant->company_uuid) {
            $company = Company::where('uuid', $grant->company_uuid)->first();
            if (!$company || !OAuthOrganizations::canShare($user, $company)) {
                $grant->revoke();

                throw OAuthServerException::invalidGrant('The user no longer administers the organization this app was given.');
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Revocation (RFC 7009)
    |--------------------------------------------------------------------------
    */

    /**
     * Revoke a refresh token (which disconnects the app: the whole grant) or an access token.
     *
     * Answers 200 whether or not the token was known, as RFC 7009 §2.2 asks.
     */
    public function revoke(Request $request): Response|JsonResponse
    {
        [$clientId, $secret] = $this->clientCredentials($request);
        $client              = (new ClientRepository())->findActive($clientId);
        $authenticated       = $client && ($client->isConfidential() ? $client->verifySecret($secret) : ($secret === null || $secret === ''));
        if (!$authenticated) {
            return new JsonResponse(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401, ['WWW-Authenticate' => 'Basic realm="OAuth"', 'Cache-Control' => 'no-store']);
        }

        $token = $request->input('token');
        if (!is_string($token) || $token === '') {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'Send the token to revoke.'], 400, ['Cache-Control' => 'no-store']);
        }

        $payload = (new PayloadCrypt($this->keys->encryptionKey()))->open($token);
        if ($payload && ($payload['client_id'] ?? null) === $client->id && isset($payload['refresh_token_id'])) {
            $refreshToken = OAuthRefreshToken::find($payload['refresh_token_id']);
            if ($refreshToken) {
                OAuthGrant::find($refreshToken->grant_uuid)?->revoke();
            }
        } elseif ($tokenId = $this->accessTokenId($token)) {
            OAuthAccessToken::where('id', $tokenId)->where('client_id', $client->id)->update(['revoked' => true, 'updated_at' => now()]);
        }

        return new Response('', 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * The `jti` of an access token this server signed, or null.
     */
    protected function accessTokenId(string $jwt): ?string
    {
        try {
            $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText('empty', 'empty'));
            $token  = $config->parser()->parse($jwt);
            if (!$config->validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->keys->publicKeyContents())))) {
                return null;
            }

            return $token->claims()->get('jti');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Client credentials from HTTP Basic (client_secret_basic) or the body (client_secret_post).
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function clientCredentials(Request $request): array
    {
        $header = (string) $request->header('Authorization');
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [urldecode($id), urldecode($secret)];
            }
        }

        $id     = $request->input('client_id');
        $secret = $request->input('client_secret');

        return [is_string($id) ? $id : null, is_string($secret) ? $secret : null];
    }

    /*
    |--------------------------------------------------------------------------
    | Protected resources
    |--------------------------------------------------------------------------
    */

    /**
     * Check the bearer token on a request to an OAuth-protected endpoint.
     *
     * @param array<int, string> $scopes every scope the endpoint needs
     *
     * @throws TokenError
     */
    public function authenticate(Request $request, array $scopes = []): OAuthAccessToken
    {
        try {
            $validated = $this->resourceServer()->validateAuthenticatedRequest($this->psrRequest($request));
        } catch (OAuthServerException $e) {
            throw TokenError::invalidToken();
        }

        $token = OAuthAccessToken::with(['grant', 'client', 'user', 'company'])->find($validated->getAttribute('oauth_access_token_id'));
        if (!$token || !$token->isUsable() || !$token->grant || $token->grant->isRevoked() || !$token->client || $token->client->isDisabled()) {
            throw TokenError::invalidToken();
        }

        if (!$token->user || !OAuthOrganizations::userIsActive($token->user)) {
            throw TokenError::invalidToken();
        }

        if ($token->company_uuid && (!$token->company || !OAuthOrganizations::canShare($token->user, $token->company))) {
            $token->grant->revoke();

            throw TokenError::invalidToken('The user no longer administers the organization this app was given.');
        }

        // The client's registration is checked too, so an admin removing a scope from an app
        // takes it away from tokens already issued.
        foreach ($scopes as $scope) {
            if (!$token->hasScope($scope) || !$token->client->allowsScope($scope)) {
                throw TokenError::insufficientScope($scope);
            }
        }

        $token->grant->touchLastUsed();

        return $token;
    }

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    */

    /**
     * OpenID Connect Discovery 1.0 / RFC 8414 metadata.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        return [
            'issuer'                                         => $this->issuer(),
            'authorization_endpoint'                         => $this->endpoint('oauth/authorize'),
            'token_endpoint'                                 => $this->endpoint('oauth/token'),
            'userinfo_endpoint'                              => $this->endpoint('oauth/userinfo'),
            'jwks_uri'                                       => $this->endpoint('oauth/jwks'),
            'revocation_endpoint'                            => $this->endpoint('oauth/revoke'),
            'scopes_supported'                               => array_keys(OAuthScopes::all()),
            'response_types_supported'                       => ['code'],
            'response_modes_supported'                       => ['query'],
            'grant_types_supported'                          => ClientRepository::GRANT_TYPES,
            'subject_types_supported'                        => ['public'],
            'id_token_signing_alg_values_supported'          => ['RS256'],
            'token_endpoint_auth_methods_supported'          => ['client_secret_basic', 'client_secret_post', 'none'],
            'revocation_endpoint_auth_methods_supported'     => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported'               => ['S256'],
            'prompt_values_supported'                        => ['login', 'consent'],
            'claims_supported'                               => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'at_hash', 'name', 'picture', 'zoneinfo', 'updated_at', 'email', 'email_verified', 'org_id', 'org_name'],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PSR-7 plumbing
    |--------------------------------------------------------------------------
    */

    public function psrRequest(Request $request): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);
    }

    /**
     * A GET request carrying only these query parameters, for league's authorization checks.
     *
     * @param array<string, mixed> $params
     */
    protected function queryRequest(array $params): ServerRequestInterface
    {
        return (new ServerRequest('GET', $this->endpoint('oauth/authorize') . '?' . http_build_query($params)))->withQueryParams($params);
    }

    public function laravelResponse(ResponseInterface $response): Response
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new Response((string) $response->getBody(), $response->getStatusCode(), $headers);
    }

    /**
     * Add `iss` to an authorization response (RFC 9207), so the app can detect mix-up attacks.
     */
    protected function withIssuer(string $location): string
    {
        return $location . (str_contains($location, '?') ? '&' : '?') . 'iss=' . rawurlencode($this->issuer());
    }
}
