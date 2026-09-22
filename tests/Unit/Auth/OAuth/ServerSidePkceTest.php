<?php

use Fleetbase\Auth\OAuth\Drivers\AppleDriver;
use Fleetbase\Auth\OAuth\Drivers\GithubDriver;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Auth\OAuth\Drivers\MicrosoftDriver;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Illuminate\Http\Request;

const PKCE_REDIRECT = 'https://api.fleetbase.test/int/v1/auth/oauth/google/callback';

function pkce_driver(string $class, array $values = []): object
{
    bind_test_container();

    return new $class(
        new OAuthProviderConfig($class::id(), array_merge([
            'client_id'     => 'client-id-123',
            'client_secret' => 'client-secret-456',
        ], $values), null),
        // The request is never read for state or PKCE — that is the point of the
        // trait — but Socialite's constructor requires one.
        Request::create('/int/v1/auth/oauth/google/redirect', 'GET'),
        new IdTokenVerifier()
    );
}

/**
 * @return array<string, string>
 */
function pkce_query(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * A real P-256 key. Apple's assertion is genuinely signed on the exchange leg, so a
 * placeholder string would fail in the signer rather than exercise the trait.
 */
function pkce_apple_private_key(): string
{
    $resource = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    openssl_pkey_export($resource, $pem);

    return (string) $pem;
}

it('builds an authorization url carrying our state and a pkce challenge', function () {
    $driver = pkce_driver(GoogleDriver::class);

    $url   = $driver->authorizationUrl('state-abc', 'verifier-xyz', PKCE_REDIRECT);
    $query = pkce_query($url);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/auth?')
        ->and($query['client_id'])->toBe('client-id-123')
        ->and($query['redirect_uri'])->toBe(PKCE_REDIRECT)
        ->and($query['response_type'])->toBe('code')
        // Socialite suppresses its own session-backed state in stateless mode; ours
        // is added back by the trait and validated against oauth_states.
        ->and($query['state'])->toBe('state-abc')
        ->and($query['code_challenge_method'])->toBe('S256');
});

it('derives the challenge as rfc 7636 s256 of the verifier', function () {
    $driver = pkce_driver(GoogleDriver::class);

    $query = pkce_query($driver->authorizationUrl('state-abc', 'verifier-xyz', PKCE_REDIRECT));

    $expected = rtrim(strtr(base64_encode(hash('sha256', 'verifier-xyz', true)), '+/', '-_'), '=');

    expect($query['code_challenge'])->toBe($expected)
        // base64url: no padding and no + or / characters.
        ->and($query['code_challenge'])->not->toContain('=')
        ->and($query['code_challenge'])->not->toContain('+')
        ->and($query['code_challenge'])->not->toContain('/')
        // The verifier itself must never appear in a URL the browser is handed.
        ->and($query)->not->toContain('verifier-xyz');
});

it('never puts the verifier or the client secret in the authorization url', function () {
    $driver = pkce_driver(GoogleDriver::class);

    $url = $driver->authorizationUrl('state-abc', 'verifier-xyz', PKCE_REDIRECT);

    expect($url)->not->toContain('verifier-xyz')
        ->and($url)->not->toContain('client-secret-456')
        ->and($url)->not->toContain('code_verifier');
});

it('sends the verifier to the token endpoint and keeps authorization only params out', function () {
    $driver = pkce_driver(AppleDriver::class, [
        'client_id'   => 'io.fleetbase.console',
        'team_id'     => 'TEAM',
        'key_id'      => 'KEY',
        'private_key' => pkce_apple_private_key(),
    ]);

    // Build the provider the way the driver does, then read the token fields it
    // would POST. Socialite merges $this->parameters into BOTH the authorization
    // request and the token request, so without the trait's filtering Apple's
    // response_mode would be posted to the token endpoint too.
    // The exchange leg needs the real client secret, which for Apple is minted and cached.
    app()->instance('cache', new class {
        private array $values = [];

        public function remember(string $key, mixed $ttl, Closure $callback): mixed
        {
            return $this->values[$key] ??= $callback();
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');

    $provider = (fn () => $this->build(PKCE_REDIRECT))->call($driver);
    $provider->withServerSidePkce('verifier-xyz')->with(['response_mode' => 'form_post', 'prompt' => 'consent']);

    $fields = (fn () => $this->getTokenFields('auth-code-1'))->call($provider);

    expect($fields['code'])->toBe('auth-code-1')
        ->and($fields['grant_type'])->toBe('authorization_code')
        ->and($fields['code_verifier'])->toBe('verifier-xyz')
        ->and($fields['redirect_uri'])->toBe(PKCE_REDIRECT)
        ->and($fields)->not->toHaveKey('response_mode')
        ->and($fields)->not->toHaveKey('prompt')
        ->and($fields)->not->toHaveKey('state');
});

it('asks apple for a form post response', function () {
    $driver = pkce_driver(AppleDriver::class, [
        'client_id'   => 'io.fleetbase.console',
        'team_id'     => 'TEAM',
        'key_id'      => 'KEY',
        'private_key' => 'unused-here',
    ]);

    $parameters = (fn () => $this->additionalAuthorizationParameters())->call($driver);

    // Apple rejects the name/email scopes unless the response is form-posted.
    expect($parameters)->toBe(['response_mode' => 'form_post']);
});

it('requests the scopes each provider needs', function (string $class, array $expected) {
    $driver = pkce_driver($class, [
        'client_id'   => 'id',
        'team_id'     => 'TEAM',
        'key_id'      => 'KEY',
        'private_key' => 'unused-here',
    ]);

    expect((fn () => $this->scopes())->call($driver))->toBe($expected);
})->with([
    'google'    => [GoogleDriver::class, ['openid', 'email', 'profile']],
    'microsoft' => [MicrosoftDriver::class, ['openid', 'profile', 'email']],
    // read:user for the profile, user:email because /user only exposes the public
    // profile address, which GitHub does not vouch for.
    'github'    => [GithubDriver::class, ['read:user', 'user:email']],
    'apple'     => [AppleDriver::class, ['name', 'email']],
]);

it('targets the configured microsoft tenant', function () {
    $single = pkce_driver(MicrosoftDriver::class, ['tenant' => 'contoso.onmicrosoft.com']);
    $multi  = pkce_driver(MicrosoftDriver::class, ['tenant' => 'common']);

    expect($single->authorizationUrl('s', 'v', PKCE_REDIRECT))
        ->toStartWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?')
        ->and($multi->authorizationUrl('s', 'v', PKCE_REDIRECT))
        ->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?');
});

it('sends google to its account chooser and honours a workspace hint', function () {
    $plain     = pkce_driver(GoogleDriver::class);
    $workspace = pkce_driver(GoogleDriver::class, ['hosted_domain' => 'fleetbase.io']);

    expect(pkce_query($plain->authorizationUrl('s', 'v', PKCE_REDIRECT))['prompt'])->toBe('select_account')
        ->and(pkce_query($workspace->authorizationUrl('s', 'v', PKCE_REDIRECT))['hd'])->toBe('fleetbase.io')
        ->and(pkce_query($plain->authorizationUrl('s', 'v', PKCE_REDIRECT)))->not->toHaveKey('hd');
});

it('uses the apple authorization endpoint', function () {
    $driver = pkce_driver(AppleDriver::class, [
        'client_id'   => 'io.fleetbase.console',
        'team_id'     => 'TEAM',
        'key_id'      => 'KEY',
        'private_key' => 'unused-here',
    ]);

    $url = $driver->authorizationUrl('state-abc', 'verifier-xyz', PKCE_REDIRECT);

    expect($url)->toStartWith('https://appleid.apple.com/auth/authorize?')
        ->and(pkce_query($url)['response_mode'])->toBe('form_post')
        ->and(pkce_query($url)['scope'])->toBe('name email');
});
