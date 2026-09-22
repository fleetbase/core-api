<?php

use Fleetbase\Auth\OAuth\AppleClientSecretFactory;
use Fleetbase\Auth\OAuth\Exceptions\OAuthProviderNotConfiguredException;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class AppleSecretCacheFake
{
    public array $values = [];
    public int $misses   = 0;

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->misses++;
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }
}

function apple_secret_private_key(): string
{
    // A real P-256 key, generated per run. Apple's ES256 assertion cannot be signed
    // with anything else, so a fake string would not exercise the signer at all.
    $resource = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    openssl_pkey_export($resource, $pem);

    return (string) $pem;
}

function apple_secret_factory(array $overrides = []): array
{
    bind_test_container();

    $cache = new AppleSecretCacheFake();
    app()->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('log');

    $config = new OAuthProviderConfig('apple', array_merge([
        'client_id'   => 'io.fleetbase.console',
        'team_id'     => 'TEAM123456',
        'key_id'      => 'KEY7890',
        'private_key' => apple_secret_private_key(),
    ], $overrides), null);

    return [new AppleClientSecretFactory(), $config, $cache];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function apple_secret_decode(string $jwt): array
{
    [$header, $payload] = explode('.', $jwt);

    $decode = fn (string $segment) => json_decode(
        (string) base64_decode(strtr($segment, '-_', '+/'), true),
        true
    );

    return [$decode($header), $decode($payload)];
}

it('mints an es256 assertion apple will accept', function () {
    Carbon::setTestNow(Carbon::parse('2024-03-01 12:00:00', 'UTC'));
    [$factory, $config] = apple_secret_factory();

    [$header, $claims] = apple_secret_decode($factory->make($config));

    expect($header['alg'])->toBe('ES256')
        ->and($header['kid'])->toBe('KEY7890')
        ->and($claims['iss'])->toBe('TEAM123456')
        ->and($claims['sub'])->toBe('io.fleetbase.console')
        ->and($claims['aud'])->toBe('https://appleid.apple.com')
        ->and($claims['exp'] - $claims['iat'])->toBe(AppleClientSecretFactory::SECRET_TTL_SECONDS)
        // Apple caps the assertion lifetime at six months.
        ->and($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(15777000);

    Carbon::setTestNow();
});

it('reuses a cached assertion rather than re-signing', function () {
    [$factory, $config, $cache] = apple_secret_factory();

    $first  = $factory->make($config);
    $second = $factory->make($config);

    expect($second)->toBe($first)
        ->and($cache->misses)->toBe(1)
        // Must expire before the assertion does, or a cached value could be served
        // after Apple would already reject it.
        ->and(AppleClientSecretFactory::CACHE_TTL_SECONDS)->toBeLessThan(AppleClientSecretFactory::SECRET_TTL_SECONDS);
});

it('caches per credential set so rotating a key does not serve a stale assertion', function () {
    [$factory, $config, $cache] = apple_secret_factory();
    $rotated                    = new OAuthProviderConfig('apple', [
        'client_id'   => 'io.fleetbase.console',
        'team_id'     => 'TEAM123456',
        'key_id'      => 'KEY-ROTATED',
        'private_key' => apple_secret_private_key(),
    ], null);

    $factory->make($config);
    $factory->make($rotated);

    expect($cache->misses)->toBe(2)
        ->and(array_keys($cache->values))->toHaveCount(2);
});

it('reports apple unconfigured when part of the signing identity is missing', function (array $overrides) {
    [$factory, $config] = apple_secret_factory($overrides);

    expect(fn () => $factory->make($config))->toThrow(OAuthProviderNotConfiguredException::class);
})->with([
    'no team id'     => [['team_id' => null]],
    'no key id'      => [['key_id' => null]],
    'no client id'   => [['client_id' => null]],
    'no private key' => [['private_key' => null]],
]);

it('reports apple unconfigured for a key openssl cannot sign with and logs no key material', function () {
    [$factory, $config] = apple_secret_factory(['private_key' => '-----BEGIN PRIVATE KEY-----not-a-key-----END PRIVATE KEY-----']);

    expect(fn () => $factory->make($config))->toThrow(OAuthProviderNotConfiguredException::class, 'apple.private_key');

    // OpenSSL error strings can echo key material, so nothing but the provider name
    // is logged.
    foreach (app('log')->entries as $entry) {
        expect(json_encode($entry))->not->toContain('BEGIN PRIVATE KEY');
    }
});
