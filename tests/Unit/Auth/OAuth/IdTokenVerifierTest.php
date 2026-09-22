<?php

use Firebase\JWT\JWT;
use Fleetbase\Auth\OAuth\Exceptions\OAuthIdTokenException;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

const ID_TOKEN_JWKS_URL  = 'https://login.microsoftonline.com/common/discovery/v2.0/keys';
const ID_TOKEN_CACHE_KEY = 'oauth.jwks.test';
const ID_TOKEN_AUDIENCE  = 'client-id-123';
const ID_TOKEN_ISSUER    = 'https://login.microsoftonline.com/tenant-1/v2.0';

/**
 * A real RSA key pair, and its public half as a JWK. `alg` is only added when asked:
 * Microsoft publishes its keys without one.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function id_token_key(string $kid, bool $withAlg): array
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privatePem);
    $details = openssl_pkey_get_details($resource);

    $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $jwk = ['kty' => 'RSA', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];

    if ($withAlg) {
        $jwk['alg'] = 'RS256';
    }

    return [(string) $privatePem, $jwk];
}

/**
 * The JWKS is placed in the cache the verifier reads first, so no request is made.
 *
 * @param array<int, array<string, string>> $keys
 */
function id_token_verifier_with(array $keys): IdTokenVerifier
{
    bind_test_container();

    $cache = new CacheRepository(new ArrayStore());
    app()->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');
    Cache::swap($cache);
    Cache::put(ID_TOKEN_CACHE_KEY, ['keys' => $keys], 300);

    return new IdTokenVerifier();
}

function id_token_signed(string $privatePem, string $kid, array $claims = []): string
{
    return JWT::encode(array_merge([
        'iss' => ID_TOKEN_ISSUER,
        'aud' => ID_TOKEN_AUDIENCE,
        'sub' => 'subject-1',
        'iat' => time(),
        'nbf' => time(),
        'exp' => time() + 300,
    ], $claims), $privatePem, 'RS256', $kid);
}

function id_token_verify(IdTokenVerifier $verifier, string $jwt): array
{
    return $verifier->verify($jwt, ID_TOKEN_JWKS_URL, ID_TOKEN_AUDIENCE, fn (string $issuer): bool => $issuer === ID_TOKEN_ISSUER, ID_TOKEN_CACHE_KEY);
}

it('verifies a token signed by a key published without an alg, as Microsoft does', function () {
    [$private, $jwk] = id_token_key('ms-key-1', false);

    $claims = id_token_verify(id_token_verifier_with([$jwk]), id_token_signed($private, 'ms-key-1'));

    expect($claims['sub'])->toBe('subject-1');
});

it('verifies a token signed by a key that names its alg, as Google and Apple do', function () {
    [$private, $jwk] = id_token_key('g-key-1', true);

    expect(id_token_verify(id_token_verifier_with([$jwk]), id_token_signed($private, 'g-key-1'))['sub'])->toBe('subject-1');
});

it('still rejects a token signed by a different key under a published kid', function () {
    [, $jwk]          = id_token_key('ms-key-1', false);
    [$otherPrivate]   = id_token_key('ms-key-1', false);

    // The missing alg defaults to RS256 for reading the key; it does not relax the
    // signature check.
    expect(fn () => id_token_verify(id_token_verifier_with([$jwk]), id_token_signed($otherPrivate, 'ms-key-1')))
        ->toThrow(OAuthIdTokenException::class, 'id_token_invalid');
});

it('reports an unknown key id rather than trusting it', function () {
    [$private, $jwk] = id_token_key('ms-key-1', false);

    expect(fn () => id_token_verify(id_token_verifier_with([$jwk]), id_token_signed($private, 'rotated-key')))
        ->toThrow(OAuthIdTokenException::class, 'id_token_unknown_key');
});
