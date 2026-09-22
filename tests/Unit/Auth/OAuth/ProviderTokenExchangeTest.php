<?php

use Firebase\JWT\JWT;
use Fleetbase\Auth\OAuth\AppleClientSecretFactory;
use Fleetbase\Auth\OAuth\CredentialCheck;
use Fleetbase\Auth\OAuth\Drivers\AppleDriver;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Auth\OAuth\Drivers\MicrosoftDriver;
use Fleetbase\Auth\OAuth\Exceptions\OAuthIdTokenException;
use Fleetbase\Auth\OAuth\Exceptions\OAuthProviderNotConfiguredException;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Fleetbase\Auth\OAuth\Socialite\MicrosoftProvider;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

/**
 * The token-endpoint leg of each id_token provider, end to end: the driver posts the
 * code to the provider (a MockHandler here), and the id_token that comes back is
 * verified against a JWKS already in the cache — so nothing leaves the process.
 */
const TOKEN_EXCHANGE_REDIRECT = 'https://api.fleetbase.test/int/v1/auth/oauth/provider/callback';

/**
 * Canned token-endpoint answers, with a record of what the driver posted.
 */
class TokenExchangeProviderFake
{
    public MockHandler $mock;

    /** @var array<int, array{request: Psr\Http\Message\RequestInterface}> */
    public array $history = [];

    public function __construct()
    {
        $this->mock = new MockHandler();
    }

    public function client(): HttpClient
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new HttpClient(['handler' => $stack]);
    }

    public function says(int $status, array $body): self
    {
        $this->mock->append(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body)));

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function postedForm(int $index = 0): array
    {
        parse_str((string) $this->history[$index]['request']->getBody(), $form);

        return $form;
    }

    public function postedUrl(int $index = 0): string
    {
        return (string) $this->history[$index]['request']->getUri();
    }
}

/**
 * Apple's client secret is an ES256 assertion minted per request; its signing is
 * covered by AppleClientSecretFactoryTest, so a fixed value stands in for it here.
 */
class TokenExchangeAppleSecretFake extends AppleClientSecretFactory
{
    public function make(OAuthProviderConfig $config): string
    {
        return 'minted-apple-secret';
    }
}

/**
 * A real RSA key pair and its public half as a JWK.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function token_exchange_key(string $kid): array
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privatePem);
    $details = openssl_pkey_get_details($resource);

    $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return [(string) $privatePem, ['kty' => 'RSA', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]];
}

/**
 * A fresh container with an in-memory cache holding the provider's JWKS.
 *
 * @param array<int, array<string, string>> $keys
 */
function token_exchange_jwks(string $cacheKey, array $keys): void
{
    bind_test_container();

    $cache = new CacheRepository(new ArrayStore());
    app()->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');
    Cache::swap($cache);
    Cache::put($cacheKey, ['keys' => $keys], 300);
}

function token_exchange_id_token(string $privatePem, string $kid, array $claims): string
{
    return JWT::encode(array_merge([
        'iat' => time(),
        'nbf' => time(),
        'exp' => time() + 300,
    ], $claims), $privatePem, 'RS256', $kid);
}

function token_exchange_microsoft(array $values, TokenExchangeProviderFake $provider): MicrosoftDriver
{
    $driver = new MicrosoftDriver(
        new OAuthProviderConfig('microsoft', array_merge(['client_id' => 'ms-client', 'client_secret' => 'ms-secret'], $values), null),
        Request::create('/int/v1/auth/oauth/microsoft/callback', 'GET'),
        new IdTokenVerifier()
    );

    return $driver->useHttpClient($provider->client());
}

function token_exchange_apple(TokenExchangeProviderFake $provider): AppleDriver
{
    $driver = new AppleDriver(
        new OAuthProviderConfig('apple', [
            'client_id'   => 'io.fleetbase.console',
            'team_id'     => 'TEAM',
            'key_id'      => 'KEY',
            'private_key' => 'unused-by-the-fake',
        ], null),
        Request::create('/int/v1/auth/oauth/apple/callback', 'POST'),
        new IdTokenVerifier(),
        new TokenExchangeAppleSecretFake()
    );

    return $driver->useHttpClient($provider->client());
}

it('exchanges a microsoft code and reads the profile from the verified id token', function () {
    [$private, $jwk] = token_exchange_key('ms-key');
    token_exchange_jwks('oauth.jwks.microsoft.' . sha1('contoso.onmicrosoft.com'), [$jwk]);

    $provider = (new TokenExchangeProviderFake())->says(200, [
        'access_token' => 'ms-access-token',
        'id_token'     => token_exchange_id_token($private, 'ms-key', [
            'iss'                => 'https://login.microsoftonline.com/tenant-1/v2.0',
            'aud'                => 'ms-client',
            'oid'                => 'object-id-1',
            'tid'                => 'contoso.onmicrosoft.com',
            'name'               => 'Ada Lovelace',
            'email'              => 'ada@contoso.com',
            'preferred_username' => 'ada@contoso.com',
        ]),
    ]);

    $profile = token_exchange_microsoft(['tenant' => 'contoso.onmicrosoft.com'], $provider)
        ->exchange('auth-code-1', 'verifier-xyz', TOKEN_EXCHANGE_REDIRECT);

    expect($profile->provider)->toBe('microsoft')
        ->and($profile->providerUserId)->toBe('object-id-1')
        ->and($profile->email)->toBe('ada@contoso.com')
        ->and($profile->name)->toBe('Ada Lovelace')
        // A single-tenant app trusts addresses from its own directory.
        ->and($profile->emailVerified)->toBeTrue()
        ->and($profile->meta('tid'))->toBe('contoso.onmicrosoft.com')
        ->and($provider->postedUrl())->toBe('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token')
        ->and($provider->postedForm())->toMatchArray([
            'code'          => 'auth-code-1',
            'code_verifier' => 'verifier-xyz',
            'client_secret' => 'ms-secret',
        ]);
});

it('refuses a microsoft id token issued outside the microsoft authority', function () {
    [$private, $jwk] = token_exchange_key('ms-key');
    token_exchange_jwks('oauth.jwks.microsoft.' . sha1('common'), [$jwk]);

    $provider = (new TokenExchangeProviderFake())->says(200, [
        'access_token' => 'ms-access-token',
        'id_token'     => token_exchange_id_token($private, 'ms-key', [
            'iss' => 'https://login.evil.test/tenant-1/v2.0',
            'aud' => 'ms-client',
            'oid' => 'object-id-1',
        ]),
    ]);

    expect(fn () => token_exchange_microsoft([], $provider)->exchange('auth-code-1', 'verifier-xyz', TOKEN_EXCHANGE_REDIRECT))
        ->toThrow(OAuthIdTokenException::class, 'id_token_issuer_mismatch');
});

it('rejects a token response that carries no usable id token', function () {
    token_exchange_jwks('oauth.jwks.microsoft.' . sha1('common'), []);

    // An array where the JWT should be is narrowed to nothing, not stringified.
    $provider = (new TokenExchangeProviderFake())->says(200, ['access_token' => 'ms-access-token', 'id_token' => ['not', 'a', 'jwt']]);

    expect(fn () => token_exchange_microsoft([], $provider)->exchange('auth-code-1', 'verifier-xyz', TOKEN_EXCHANGE_REDIRECT))
        ->toThrow(OAuthIdTokenException::class, 'id_token_missing');
});

it('names its tenant and holds no token response before an exchange', function () {
    bind_test_container();

    $provider = new MicrosoftProvider(Request::create('/'), 'ms-client', 'ms-secret', TOKEN_EXCHANGE_REDIRECT);

    expect($provider->withTenant('')->tenant())->toBe('common')
        ->and($provider->withTenant('contoso.onmicrosoft.com')->tenant())->toBe('contoso.onmicrosoft.com')
        ->and($provider->serverTokenResponse())->toBe([]);
});

it('exchanges an apple code with a minted client secret and verifies the id token', function () {
    [$private, $jwk] = token_exchange_key('apple-key');
    token_exchange_jwks('oauth.jwks.apple', [$jwk]);

    $provider = (new TokenExchangeProviderFake())->says(200, [
        'access_token' => 'apple-access-token',
        'id_token'     => token_exchange_id_token($private, 'apple-key', [
            'iss'            => 'https://appleid.apple.com',
            'aud'            => 'io.fleetbase.console',
            'sub'            => '001234.apple-subject',
            'email'          => 'ada@privaterelay.appleid.com',
            'email_verified' => 'true',
        ]),
    ]);

    $profile = token_exchange_apple($provider)->exchange('auth-code-1', 'verifier-xyz', TOKEN_EXCHANGE_REDIRECT, [
        // Apple posts the name once, beside the code, on first authorization only.
        'user' => json_encode(['name' => ['firstName' => 'Ada', 'lastName' => 'Lovelace']]),
    ]);

    expect($profile->provider)->toBe('apple')
        ->and($profile->providerUserId)->toBe('001234.apple-subject')
        ->and($profile->email)->toBe('ada@privaterelay.appleid.com')
        ->and($profile->emailVerified)->toBeTrue()
        ->and($profile->name)->toBe('Ada Lovelace')
        ->and($profile->meta('private_relay'))->toBeTrue()
        ->and($provider->postedUrl())->toBe('https://appleid.apple.com/auth/token')
        ->and($provider->postedForm())->toMatchArray([
            'client_id'     => 'io.fleetbase.console',
            'client_secret' => 'minted-apple-secret',
            'code_verifier' => 'verifier-xyz',
        ]);
});

it('refuses an apple id token from another issuer', function () {
    [$private, $jwk] = token_exchange_key('apple-key');
    token_exchange_jwks('oauth.jwks.apple', [$jwk]);

    $provider = (new TokenExchangeProviderFake())->says(200, [
        'access_token' => 'apple-access-token',
        'id_token'     => token_exchange_id_token($private, 'apple-key', [
            'iss' => 'https://appleid.apple.com.evil.test',
            'aud' => 'io.fleetbase.console',
            'sub' => '001234.apple-subject',
        ]),
    ]);

    expect(fn () => token_exchange_apple($provider)->exchange('auth-code-1', 'verifier-xyz', TOKEN_EXCHANGE_REDIRECT))
        ->toThrow(OAuthIdTokenException::class, 'id_token_issuer_mismatch');
});

it('reports a client secret that cannot be resolved as invalid credentials without calling the provider', function () {
    bind_test_container();
    $provider = new TokenExchangeProviderFake();

    // Ciphertext with no encrypter to open it: the secret cannot be resolved at all.
    $config = new OAuthProviderConfig('google', ['client_id' => 'g', 'client_secret_encrypted' => 'ciphertext'], null);
    $driver = (new GoogleDriver($config, Request::create('/'), new IdTokenVerifier()))->useHttpClient($provider->client());

    expect(fn () => $config->secret('client_secret'))->toThrow(OAuthProviderNotConfiguredException::class, 'google.client_secret')
        ->and($driver->verifyCredentials(TOKEN_EXCHANGE_REDIRECT))->toBe(CredentialCheck::InvalidClient)
        ->and($provider->history)->toBe([]);
});

it('draws no conclusion when the provider issues a token for a code that cannot exist', function () {
    bind_test_container();
    $provider = (new TokenExchangeProviderFake())->says(200, ['access_token' => 'should-never-happen']);

    $driver = (new GoogleDriver(
        new OAuthProviderConfig('google', ['client_id' => 'g', 'client_secret' => 'gs'], null),
        Request::create('/'),
        new IdTokenVerifier()
    ))->useHttpClient($provider->client());

    expect($driver->verifyCredentials(TOKEN_EXCHANGE_REDIRECT))->toBe(CredentialCheck::Inconclusive);
});

it('counts only verified credentials as a pass', function () {
    expect(CredentialCheck::Verified->passed())->toBeTrue()
        ->and(CredentialCheck::InvalidClient->passed())->toBeFalse()
        ->and(CredentialCheck::RedirectUriMismatch->passed())->toBeFalse()
        ->and(CredentialCheck::Unreachable->passed())->toBeFalse()
        ->and(CredentialCheck::Inconclusive->passed())->toBeFalse();
});
