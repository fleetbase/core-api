<?php

use Fleetbase\Auth\OAuth\AppleClientSecretFactory;
use Fleetbase\Auth\OAuth\Drivers\AppleDriver;
use Fleetbase\Auth\OAuth\Drivers\GithubDriver;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\OAuthProviderRegistry;
use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\Admin\SaveOAuthConfigRequest;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Fleetbase\Services\OAuth\OAuthFlowService;
use Fleetbase\Services\OAuth\OAuthStateService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class SettingControllerOAuthEncrypterFake implements Encrypter
{
    public function encrypt($value, $serialize = true)
    {
        return 'enc:' . base64_encode($serialize ? serialize($value) : (string) $value);
    }

    public function decrypt($payload, $unserialize = true)
    {
        if (!is_string($payload) || !str_starts_with($payload, 'enc:')) {
            throw new DecryptException('The payload is invalid.');
        }

        $value = base64_decode(substr($payload, 4), true);

        return $unserialize ? unserialize((string) $value) : (string) $value;
    }

    public function getKey()
    {
        return 'test-key';
    }
}

class SettingControllerOAuthCacheFake
{
    private array $values = [];

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;
    }

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}

/**
 * @return array{0: OAuthProviderRegistry, 1: OAuthConfigRepository, 2: OAuthFlowService, 3: AppleClientSecretFactory}
 */
function setting_controller_oauth_services(array $config = []): array
{
    EloquentModel::clearBootedModels();

    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];

    $container = bind_test_container(array_merge([
        'api.cache.enabled'                     => false,
        'app.url'                               => 'https://api.fleetbase.test',
        'database.default'                      => 'mysql',
        'database.connections.mysql'            => $connection,
        'fleetbase.connection.db'               => 'mysql',
        'fleetbase.api.routing.prefix'          => '/',
        'fleetbase.api.routing.internal_prefix' => 'int',
        'oauth.enabled'                         => true,
        'oauth.allow_registration'              => true,
        'oauth.providers'                       => [
            'google' => ['driver' => GoogleDriver::class, 'enabled' => false],
            'github' => ['driver' => GithubDriver::class, 'enabled' => false],
            'apple'  => ['driver' => AppleDriver::class, 'enabled' => false],
        ],
    ], $config));

    $cache = new SettingControllerOAuthCacheFake();
    $container->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('log');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('mysql')->getSchemaBuilder()->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });

    $encrypter = new SettingControllerOAuthEncrypterFake();
    $config    = new OAuthConfigRepository($encrypter);
    $registry  = new OAuthProviderRegistry($config, Request::create('/'), new IdTokenVerifier());
    $flow      = new OAuthFlowService($registry, new OAuthStateService($encrypter), $config);

    return [$registry, $config, $flow, new AppleClientSecretFactory()];
}

function setting_controller_oauth_save(array $body): SaveOAuthConfigRequest
{
    return SaveOAuthConfigRequest::create('/int/v1/settings/oauth-config', 'POST', $body);
}

function setting_controller_oauth_stored(): array
{
    $row = Setting::query()->where('key', 'system.oauth')->first();

    return is_array($row?->value) ? $row->value : [];
}

function setting_controller_oauth_ec_key(): string
{
    $resource = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($resource, $pem);

    return (string) $pem;
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------

it('describes every provider so the console can render the form without hardcoding one', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    $payload = (new SettingController())
        ->getOAuthConfig(AdminRequest::create('/'), $registry, $config, $flow)
        ->getData(true);

    expect(array_column($payload['providers'], 'id'))->toBe(['google', 'github', 'apple'])
        ->and($payload['providers'][0]['label'])->toBe('Google')
        ->and($payload['providers'][0]['schema']['client_secret']['secret'])->toBeTrue()
        // The exact callback URL the operator has to register at each provider.
        ->and($payload['redirect_uris']['google'])->toBe('https://api.fleetbase.test/int/v1/auth/oauth/google/callback')
        ->and($payload['oauth']['enabled'])->toBeTrue();
});

it('never returns a secret value, even to an administrator', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => true, 'client_id' => 'google-id', 'client_secret' => 'super-secret-value']],
    ]), $registry, $config, $flow);

    $body = json_encode((new SettingController())
        ->getOAuthConfig(AdminRequest::create('/'), $registry, $config, $flow)
        ->getData(true));

    expect($body)->not->toContain('super-secret-value')
        ->and($body)->toContain('google-id')
        ->and($body)->toContain('alue'); // the four-character hint
});

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

it('encrypts secrets at rest', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['client_id' => 'google-id', 'client_secret' => 'super-secret-value']],
    ]), $registry, $config, $flow);

    $stored = setting_controller_oauth_stored();

    expect(json_encode($stored))->not->toContain('super-secret-value')
        ->and($stored['providers']['google'])->toHaveKey('client_secret_encrypted')
        ->and($stored['providers']['google'])->not->toHaveKey('client_secret')
        ->and($config->forProvider('google')->secret('client_secret'))->toBe('super-secret-value');
});

it('keeps the stored secret when the form submits an empty one', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();
    $controller                 = new SettingController();

    $controller->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['client_id' => 'id-1', 'client_secret' => 'original']],
    ]), $registry, $config, $flow);

    // The form renders a masked placeholder, so a save that did not touch the field
    // submits it empty. That must not wipe the credential.
    $controller->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['client_id' => 'id-2', 'client_secret' => '']],
    ]), $registry, $config, $flow);

    expect($config->forProvider('google')->secret('client_secret'))->toBe('original')
        ->and($config->forProvider('google')->get('client_id'))->toBe('id-2');
});

it('ignores provider ids no driver defines', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['made-up' => ['client_id' => 'x', 'client_secret' => 'y']],
    ]), $registry, $config, $flow);

    expect(setting_controller_oauth_stored()['providers'] ?? [])->not->toHaveKey('made-up');
});

it('ignores fields a driver does not declare, including a driver class', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => [
            'client_id' => 'google-id',
            // Writing a class name into settings would be arbitrary class instantiation.
            'driver'    => 'Evil\\ArbitraryClass',
            'team_id'   => 'apple-only-field',
            'nonsense'  => 'value',
        ]],
    ]), $registry, $config, $flow);

    $google = setting_controller_oauth_stored()['providers']['google'];

    expect($google)->toHaveKey('client_id')
        ->and($google)->not->toHaveKey('driver')
        ->and($google)->not->toHaveKey('team_id')
        ->and($google)->not->toHaveKey('nonsense');
});

it('persists the global switches and provider toggles', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'enabled'            => false,
        'allow_registration' => '0',
        'providers'          => ['github' => ['enabled' => 'true']],
    ]), $registry, $config, $flow);

    expect($config->isEnabled())->toBeFalse()
        ->and($config->allowsRegistration())->toBeFalse()
        ->and($config->forProvider('github')->enabled())->toBeTrue();
});

it('leaves the global switches alone when a save does not mention them', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();
    $controller                 = new SettingController();

    $controller->saveOAuthConfig(setting_controller_oauth_save(['allow_registration' => false]), $registry, $config, $flow);
    $controller->saveOAuthConfig(setting_controller_oauth_save(['providers' => ['google' => ['client_id' => 'x']]]), $registry, $config, $flow);

    expect($config->allowsRegistration())->toBeFalse();
});

it('responds with the refreshed configuration after saving', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    $payload = (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['client_id' => 'saved-id']],
    ]), $registry, $config, $flow)->getData(true);

    expect($payload['oauth']['providers']['google']['client_id'])->toBe('saved-id')
        ->and($payload)->toHaveKeys(['oauth', 'providers', 'redirect_uris']);
});

// ---------------------------------------------------------------------------
// Test
// ---------------------------------------------------------------------------

it('reports a provider with missing credentials', function () {
    [$registry, $config, $flow, $apple] = setting_controller_oauth_services();

    $payload = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'google']), $registry, $config, $flow, $apple
    )->getData(true);

    expect($payload)->toBe([
        'provider'     => 'google',
        'configured'   => false,
        'enabled'      => false,
        'problem'      => 'missing_credentials',
        'redirect_uri' => 'https://api.fleetbase.test/int/v1/auth/oauth/google/callback',
    ]);
});

it('reports a fully configured provider', function () {
    [$registry, $config, $flow, $apple] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['github' => ['enabled' => true, 'client_id' => 'gh-id', 'client_secret' => 'gh-secret']],
    ]), $registry, $config, $flow);

    $payload = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'github']), $registry, $config, $flow, $apple
    )->getData(true);

    expect($payload['configured'])->toBeTrue()
        ->and($payload['enabled'])->toBeTrue()
        ->and($payload['problem'])->toBeNull();
});

it('catches an apple signing key that cannot mint a client secret', function () {
    [$registry, $config, $flow, $apple] = setting_controller_oauth_services();
    app()->instance('cache', new SettingControllerOAuthCacheFake());
    Facade::clearResolvedInstance('cache');

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['apple' => [
            'client_id'   => 'io.fleetbase.console',
            'team_id'     => 'TEAM',
            'key_id'      => 'KEY',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nnot-a-real-key\n-----END PRIVATE KEY-----",
        ]],
    ]), $registry, $config, $flow);

    // The failure an operator is most likely to hit, and one that would otherwise only
    // surface at the first real sign-in.
    $payload = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'apple']), $registry, $config, $flow, $apple
    )->getData(true);

    expect($payload['configured'])->toBeFalse()
        ->and($payload['problem'])->toBe('invalid_signing_key');
});

it('accepts an apple signing key that mints a client secret', function () {
    [$registry, $config, $flow, $apple] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['apple' => [
            'client_id'   => 'io.fleetbase.console',
            'team_id'     => 'TEAM',
            'key_id'      => 'KEY',
            'private_key' => setting_controller_oauth_ec_key(),
        ]],
    ]), $registry, $config, $flow);

    $payload = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'apple']), $registry, $config, $flow, $apple
    )->getData(true);

    expect($payload['configured'])->toBeTrue()
        ->and($payload['problem'])->toBeNull();
});

it('rejects an unknown provider under test', function () {
    [$registry, $config, $flow, $apple] = setting_controller_oauth_services();

    $response = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'nope']), $registry, $config, $flow, $apple
    );

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true)['code'])->toBe('unknown_provider');
});

// ---------------------------------------------------------------------------
// Request
// ---------------------------------------------------------------------------

it('is an admin-only request', function () {
    // AdminRequest::authorize() is what restricts these endpoints to administrators.
    expect(is_subclass_of(SaveOAuthConfigRequest::class, AdminRequest::class))->toBeTrue();
});

it('validates the shape of a save', function (array $body, bool $fails) {
    setting_controller_oauth_services();

    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
    $validator  = (new Illuminate\Validation\Factory($translator))->make($body, (new SaveOAuthConfigRequest())->rules());

    expect($validator->fails())->toBe($fails);
})->with([
    'toggles only'           => [['enabled' => true, 'allow_registration' => false], false],
    'non boolean toggle'     => [['enabled' => 'sometimes'], true],
    'full pem key'           => [['providers' => ['apple' => ['private_key' => "-----BEGIN PRIVATE KEY-----\nabc"]]], false],
    // Empty means "keep the stored key" and must not be rejected.
    'empty key keeps stored' => [['providers' => ['apple' => ['private_key' => '']]], false],
    'key without pem header' => [['providers' => ['apple' => ['private_key' => 'MIGTAgEAMBMGByqGSM49']]], true],
    'oversized client id'    => [['providers' => ['google' => ['client_id' => str_repeat('a', 513)]]], true],
]);
