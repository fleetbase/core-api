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

it('rejects a missing token before doing any work', function () {
    [, $jwk] = id_token_key('ms-key-1', false);

    expect(fn () => id_token_verify(id_token_verifier_with([$jwk]), ''))
        ->toThrow(OAuthIdTokenException::class, 'id_token_missing');
});

it('refuses to verify against an unconfigured client id', function () {
    [$private, $jwk] = id_token_key('ms-key-1', false);

    // PermittedFor('') would otherwise assert against an empty audience.
    expect(fn () => id_token_verifier_with([$jwk])->verify(id_token_signed($private, 'ms-key-1'), ID_TOKEN_JWKS_URL, '', fn (): bool => true, ID_TOKEN_CACHE_KEY))
        ->toThrow(OAuthIdTokenException::class, 'audience_not_configured');
});

it('reports a token that does not parse as malformed', function () {
    [, $jwk] = id_token_key('ms-key-1', false);

    expect(fn () => id_token_verify(id_token_verifier_with([$jwk]), 'not-a-jwt'))
        ->toThrow(OAuthIdTokenException::class, 'id_token_malformed');
});

it('refuses a token whose header names no key', function () {
    [$private, $jwk] = id_token_key('ms-key-1', false);

    $unkeyed = JWT::encode(['iss' => ID_TOKEN_ISSUER, 'aud' => ID_TOKEN_AUDIENCE, 'exp' => time() + 300], $private, 'RS256');

    expect(fn () => id_token_verify(id_token_verifier_with([$jwk]), $unkeyed))
        ->toThrow(OAuthIdTokenException::class, 'id_token_missing_kid');
});

it('reports a key set it cannot read', function (mixed $cached) {
    [$private] = id_token_key('ms-key-1', false);

    $verifier = id_token_verifier_with([]);
    Cache::put(ID_TOKEN_CACHE_KEY, $cached, 300);

    expect(fn () => id_token_verify($verifier, id_token_signed($private, 'ms-key-1')))
        ->toThrow(OAuthIdTokenException::class, 'jwks_unreadable');
})->with([
    'no keys in the set'  => [['keys' => []]],
    'not a json document' => ['<html>maintenance</html>'],
]);

/**
 * Serve $files from a PHP built-in server on a free loopback port, standing in for a
 * provider's JWKS endpoint. The verifier builds its own HTTP client, so the fetch is a
 * real request, but it never leaves this machine.
 *
 * @param array<string, string> $files
 *
 * @return array{0: string, 1: array{process: resource, root: string}}
 */
function id_token_jwks_server(array $files): array
{
    $root = sys_get_temp_dir() . '/fleetbase-jwks-' . bin2hex(random_bytes(4));
    mkdir($root);

    foreach ($files as $name => $contents) {
        file_put_contents($root . '/' . $name, $contents);
    }

    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);

    // Refused connections are expected until the server is listening; they are not
    // warnings about the code under test.
    set_error_handler(fn (): bool => true);

    try {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($socket !== false) {
                fclose($socket);
                break;
            }

            usleep(50000);
        }
    } finally {
        restore_error_handler();
    }

    return ['http://127.0.0.1:' . $port, ['process' => $process, 'root' => $root]];
}

/**
 * @param array{process: resource, root: string} $server
 */
function id_token_jwks_server_stop(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);

    array_map('unlink', glob($server['root'] . '/*') ?: []);
    rmdir($server['root']);
}

function id_token_uncached_verifier(): IdTokenVerifier
{
    bind_test_container();

    $cache = new CacheRepository(new ArrayStore());
    app()->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('log');
    Cache::swap($cache);

    return new IdTokenVerifier();
}

it('fetches and caches the key set when it is not cached yet', function () {
    [$private, $jwk]    = id_token_key('fetched-key', false);
    [$baseUrl, $server] = id_token_jwks_server(['keys.json' => json_encode(['keys' => [$jwk]])]);

    try {
        $verifier = id_token_uncached_verifier();
        $claims   = $verifier->verify(id_token_signed($private, 'fetched-key'), $baseUrl . '/keys.json', ID_TOKEN_AUDIENCE, fn (string $issuer): bool => $issuer === ID_TOKEN_ISSUER, ID_TOKEN_CACHE_KEY);
    } finally {
        id_token_jwks_server_stop($server);
    }

    expect($claims['sub'])->toBe('subject-1')
        ->and(Cache::get(ID_TOKEN_CACHE_KEY))->toBe(['keys' => [$jwk]]);
});

it('reports a key set endpoint that fails', function () {
    [$private]          = id_token_key('fetched-key', false);
    [$baseUrl, $server] = id_token_jwks_server([]);

    try {
        $verifier = id_token_uncached_verifier();

        expect(fn () => $verifier->verify(id_token_signed($private, 'fetched-key'), $baseUrl . '/missing.json', ID_TOKEN_AUDIENCE, fn (): bool => true, ID_TOKEN_CACHE_KEY))
            ->toThrow(OAuthIdTokenException::class, 'jwks_unreachable');
    } finally {
        id_token_jwks_server_stop($server);
    }

    // Nothing is cached from a failed fetch, so the next attempt tries again.
    expect(Cache::get(ID_TOKEN_CACHE_KEY))->toBeNull()
        ->and(app('log')->entries[0][1] ?? null)->toBe('[OAuth] Unable to fetch provider JWKS.');
});
