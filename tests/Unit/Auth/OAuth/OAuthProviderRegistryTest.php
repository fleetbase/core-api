<?php

use Fleetbase\Auth\OAuth\Drivers\AppleDriver;
use Fleetbase\Auth\OAuth\Drivers\GithubDriver;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Auth\OAuth\Drivers\MicrosoftDriver;
use Fleetbase\Auth\OAuth\Exceptions\UnknownOAuthProviderException;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\OAuthProviderRegistry;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Illuminate\Http\Request;

/**
 * A repository stub: the registry only needs configuration, and building a real one
 * would drag a database in for no benefit. OAuthConfigResolutionTest covers the real
 * resolution behaviour.
 */
class OAuthRegistryConfigStub extends OAuthConfigRepository
{
    /**
     * @param array<string, array<string, mixed>> $providers
     */
    public function __construct(
        private array $providers,
        private bool $globallyEnabled = true,
    ) {
        parent::__construct(null);
    }

    public function isEnabled(): bool
    {
        return $this->globallyEnabled;
    }

    public function providerIds(): array
    {
        return array_keys($this->providers);
    }

    public function driverClass(string $provider): ?string
    {
        $class = $this->providers[$provider]['driver'] ?? null;

        return is_string($class) ? $class : null;
    }

    public function forProvider(string $provider): Fleetbase\Auth\OAuth\OAuthProviderConfig
    {
        $values = $this->providers[$provider] ?? [];
        unset($values['driver']);

        return new Fleetbase\Auth\OAuth\OAuthProviderConfig($provider, $values, null);
    }
}

function oauth_registry(array $providers, bool $globallyEnabled = true): OAuthProviderRegistry
{
    bind_test_container();

    return new OAuthProviderRegistry(
        new OAuthRegistryConfigStub($providers, $globallyEnabled),
        Request::create('/int/v1/auth/oauth/google/redirect', 'GET'),
        new IdTokenVerifier()
    );
}

function oauth_registry_all_providers(): array
{
    return [
        'google'    => ['driver' => GoogleDriver::class,    'enabled' => true, 'client_id' => 'g', 'client_secret' => 'gs'],
        'microsoft' => ['driver' => MicrosoftDriver::class, 'enabled' => true, 'client_id' => 'm', 'client_secret' => 'ms'],
        'github'    => ['driver' => GithubDriver::class,    'enabled' => true, 'client_id' => 'h', 'client_secret' => 'hs'],
        'apple'     => [
            'driver'      => AppleDriver::class,
            'enabled'     => true,
            'client_id'   => 'io.fleetbase.console',
            'team_id'     => 'TEAM',
            'key_id'      => 'KEY',
            'private_key' => '-----BEGIN PRIVATE KEY-----',
        ],
    ];
}

it('lists every defined provider whether or not it is configured', function () {
    $registry = oauth_registry(['google' => ['driver' => GoogleDriver::class]]);

    expect($registry->ids())->toBe(['google'])
        ->and($registry->has('google'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse();
});

it('resolves a driver instance for a known provider', function () {
    $registry = oauth_registry(oauth_registry_all_providers());

    expect($registry->driver('google'))->toBeInstanceOf(GoogleDriver::class)
        ->and($registry->driver('microsoft'))->toBeInstanceOf(MicrosoftDriver::class)
        ->and($registry->driver('github'))->toBeInstanceOf(GithubDriver::class)
        ->and($registry->driver('apple'))->toBeInstanceOf(AppleDriver::class);
});

it('throws for an unknown provider', function () {
    $registry = oauth_registry(['google' => ['driver' => GoogleDriver::class]]);

    expect(fn () => $registry->driver('nope'))->toThrow(UnknownOAuthProviderException::class, 'unknown_provider');
});

it('refuses a driver class that is not an oauth driver', function () {
    // Defence in depth behind OAuthConfigRepository already stripping `driver` from
    // stored settings: even if a class name did reach the registry it must not be
    // instantiated unless it implements the contract.
    $registry = oauth_registry(['evil' => ['driver' => stdClass::class]]);

    expect($registry->has('evil'))->toBeFalse()
        ->and(fn () => $registry->driver('evil'))->toThrow(UnknownOAuthProviderException::class);
});

it('treats a missing class name as an unknown provider', function () {
    $registry = oauth_registry(['ghost' => ['driver' => 'Fleetbase\\Nope\\DoesNotExist']]);

    expect($registry->has('ghost'))->toBeFalse();
});

it('reports only providers that are both enabled and configured', function () {
    $registry = oauth_registry([
        // enabled and complete
        'google' => ['driver' => GoogleDriver::class, 'enabled' => true, 'client_id' => 'g', 'client_secret' => 'gs'],
        // configured but switched off
        'github' => ['driver' => GithubDriver::class, 'enabled' => false, 'client_id' => 'h', 'client_secret' => 'hs'],
        // switched on but missing its secret — excluded rather than rendered as a
        // broken button
        'microsoft' => ['driver' => MicrosoftDriver::class, 'enabled' => true, 'client_id' => 'm'],
    ]);

    expect(array_keys($registry->enabled()))->toBe(['google']);
});

it('reports nothing when oauth is globally disabled', function () {
    $registry = oauth_registry(oauth_registry_all_providers(), false);

    expect($registry->enabled())->toBe([])
        ->and($registry->toDiscoveryArray())->toBe([]);
});

it('exposes only what the console needs to draw a button', function () {
    $registry = oauth_registry(oauth_registry_all_providers());

    $discovery = $registry->toDiscoveryArray();

    expect($discovery)->toHaveCount(4)
        ->and($discovery[0])->toBe(['id' => 'google', 'label' => 'Google', 'icon' => 'google'])
        // No client ids, no secrets, no redirect URIs.
        ->and(json_encode($discovery))->not->toContain('client_id')
        ->and(json_encode($discovery))->not->toContain('gs')
        ->and(json_encode($discovery))->not->toContain('BEGIN PRIVATE KEY');

    foreach ($discovery as $provider) {
        expect(array_keys($provider))->toBe(['id', 'label', 'icon']);
    }
});

it('keeps provider identifiers stable', function () {
    // These land in routes, settings keys and the oauth_identities.provider column.
    // Changing one for a shipped provider orphans every existing linked identity.
    expect(GoogleDriver::id())->toBe('google')
        ->and(MicrosoftDriver::id())->toBe('microsoft')
        ->and(GithubDriver::id())->toBe('github')
        ->and(AppleDriver::id())->toBe('apple');
});

it('publishes a config schema for every provider', function () {
    $registry = oauth_registry(oauth_registry_all_providers());

    $schemas = $registry->schemas();

    expect(array_keys($schemas))->toBe(['google', 'microsoft', 'github', 'apple'])
        ->and($schemas['google']['client_secret']['secret'])->toBeTrue()
        ->and($schemas['apple']['private_key']['secret'])->toBeTrue()
        ->and($schemas['google']['client_id'])->not->toHaveKey('secret');

    // Every schema must declare a label, since the admin form renders from it.
    foreach ($schemas as $provider => $schema) {
        foreach ($schema as $field => $definition) {
            expect($definition)->toHaveKey('label');
        }
    }
});

it('marks apple as needing a form post callback and the others not', function () {
    $registry = oauth_registry(oauth_registry_all_providers());

    // Apple requires response_mode=form_post whenever name/email scopes are asked
    // for, which is why the callback route has to accept POST as well as GET.
    expect($registry->driver('apple')->usesFormPostCallback())->toBeTrue()
        ->and($registry->driver('google')->usesFormPostCallback())->toBeFalse()
        ->and($registry->driver('microsoft')->usesFormPostCallback())->toBeFalse()
        ->and($registry->driver('github')->usesFormPostCallback())->toBeFalse();
});
