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
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
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
 * Stands in for the providers' token endpoints. Queue what the provider answers;
 * an unexpected call fails the test, because the queue is empty.
 */
class SettingControllerOAuthProviderFake
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

    /** The provider authenticated the client and rejected only the made-up code. */
    public function accepts(): self
    {
        return $this->says(400, ['error' => 'invalid_grant']);
    }

    /**
     * @return array<string, string>
     */
    public function lastForm(): array
    {
        parse_str((string) end($this->history)['request']->getBody(), $form);

        return $form;
    }
}

/**
 * @return array{0: OAuthProviderRegistry, 1: OAuthConfigRepository, 2: OAuthFlowService, 3: AppleClientSecretFactory, 4: SettingControllerOAuthProviderFake}
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
    $provider  = new SettingControllerOAuthProviderFake();
    $registry  = new OAuthProviderRegistry($config, Request::create('/'), new IdTokenVerifier(), $provider->client());
    $flow      = new OAuthFlowService($registry, new OAuthStateService($encrypter), $config);

    return [$registry, $config, $flow, new AppleClientSecretFactory(), $provider];
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
        ->and($payload['oauth']['enabled'])->toBeTrue()
        ->and($payload['oauth']['auto_link'])->toBeTrue();
});

it('never returns a secret value, even to an administrator', function () {
    [$registry, $config, $flow, , $provider] = setting_controller_oauth_services();
    $provider->accepts();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => true, 'client_id' => 'google-id', 'client_secret' => 'super-secret-value']],
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

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
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

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
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

    // The form renders a masked placeholder, so a save that did not touch the field
    // submits it empty. That must not wipe the credential.
    $controller->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['client_id' => 'id-2', 'client_secret' => '']],
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

    expect($config->forProvider('google')->secret('client_secret'))->toBe('original')
        ->and($config->forProvider('google')->get('client_id'))->toBe('id-2');
});

it('ignores provider ids no driver defines', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['made-up' => ['client_id' => 'x', 'client_secret' => 'y']],
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

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
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

    $google = setting_controller_oauth_stored()['providers']['google'];

    expect($google)->toHaveKey('client_id')
        ->and($google)->not->toHaveKey('driver')
        ->and($google)->not->toHaveKey('team_id')
        ->and($google)->not->toHaveKey('nonsense');
});

it('persists the global switches and provider toggles', function () {
    [$registry, $config, $flow, , $provider] = setting_controller_oauth_services();
    $provider->says(200, ['error' => 'bad_verification_code']);

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'enabled'            => false,
        'allow_registration' => '0',
        'auto_link'          => false,
        'providers'          => ['github' => ['enabled' => 'true', 'client_id' => 'gh-id', 'client_secret' => 'gh-secret']],
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

    expect($config->isEnabled())->toBeFalse()
        ->and($config->allowsRegistration())->toBeFalse()
        ->and($config->autoLinksVerifiedEmail())->toBeFalse()
        ->and($config->toAdminArray([])['auto_link'])->toBeFalse()
        ->and($config->forProvider('github')->enabled())->toBeTrue();
});

it('leaves the global switches alone when a save does not mention them', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();
    $controller                 = new SettingController();

    $controller->saveOAuthConfig(setting_controller_oauth_save(['allow_registration' => false]), $registry, $config, $flow, new AppleClientSecretFactory());
    $controller->saveOAuthConfig(setting_controller_oauth_save(['providers' => ['google' => ['client_id' => 'x']]]), $registry, $config, $flow, new AppleClientSecretFactory());

    expect($config->allowsRegistration())->toBeFalse();
});

it('responds with the refreshed configuration after saving', function () {
    [$registry, $config, $flow] = setting_controller_oauth_services();

    $payload = (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['client_id' => 'saved-id']],
    ]), $registry, $config, $flow, new AppleClientSecretFactory())->getData(true);

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
        'verified'     => false,
        'problem'      => 'missing_credentials',
        'missing'      => ['Client ID', 'Client Secret'],
        'message'      => 'Missing: Client ID, Client Secret.',
        'redirect_uri' => 'https://api.fleetbase.test/int/v1/auth/oauth/google/callback',
    ]);
});

it('reports a fully configured provider', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    // GitHub answers token errors with HTTP 200: once for the save, once for the check.
    $provider->says(200, ['error' => 'bad_verification_code'])->says(200, ['error' => 'bad_verification_code']);

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['github' => ['enabled' => true, 'client_id' => 'gh-id', 'client_secret' => 'gh-secret']],
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

    $payload = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'github']), $registry, $config, $flow, $apple
    )->getData(true);

    expect($payload['configured'])->toBeTrue()
        ->and($payload['verified'])->toBeTrue()
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
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

    // The failure an operator is most likely to hit, and one that would otherwise only
    // surface at the first real sign-in.
    $payload = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'apple']), $registry, $config, $flow, $apple
    )->getData(true);

    expect($payload['configured'])->toBeFalse()
        ->and($payload['problem'])->toBe('invalid_signing_key');
});

it('accepts an apple signing key that mints a client secret', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $provider->accepts();

    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['apple' => [
            'client_id'   => 'io.fleetbase.console',
            'team_id'     => 'TEAM',
            'key_id'      => 'KEY',
            'private_key' => setting_controller_oauth_ec_key(),
        ]],
    ]), $registry, $config, $flow, new AppleClientSecretFactory());

    $payload = (new SettingController())->testOAuthConfig(
        AdminRequest::create('/', 'POST', ['provider' => 'apple']), $registry, $config, $flow, $apple
    )->getData(true);

    expect($payload['configured'])->toBeTrue()
        ->and($payload['problem'])->toBeNull()
        // The minted assertion, not the .p8, is what Apple receives.
        ->and($provider->lastForm()['client_secret'])->toStartWith('eyJ');
});

it('checks the values in the form before they are saved', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $provider->accepts();

    // Nothing stored yet: the admin has typed credentials and not pressed save.
    $payload = (new SettingController())->testOAuthConfig(AdminRequest::create('/', 'POST', [
        'provider' => 'google',
        'values'   => ['client_id' => 'typed-id', 'client_secret' => 'typed-secret', 'hosted_domain' => ''],
    ]), $registry, $config, $flow, $apple)->getData(true);

    expect($payload['verified'])->toBeTrue()
        ->and($payload['problem'])->toBeNull()
        ->and($provider->lastForm())->toMatchArray(['client_id' => 'typed-id', 'client_secret' => 'typed-secret', 'redirect_uri' => 'https://api.fleetbase.test/int/v1/auth/oauth/google/callback'])
        ->and(setting_controller_oauth_stored())->toBe([]);
});

it('checks with the stored secret when the form leaves it blank', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['client_id' => 'stored-id', 'client_secret' => 'stored-secret']],
    ]), $registry, $config, $flow, $apple);
    $provider->accepts();

    (new SettingController())->testOAuthConfig(AdminRequest::create('/', 'POST', [
        'provider' => 'google',
        'values'   => ['client_id' => 'new-id', 'client_secret' => ''],
    ]), $registry, $config, $flow, $apple);

    expect($provider->lastForm())->toMatchArray(['client_id' => 'new-id', 'client_secret' => 'stored-secret'])
        ->and($config->forProvider('google')->get('client_id'))->toBe('stored-id');
});

it('reports what the provider said about the credentials', function (int $status, array $body, string $problem) {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $provider->says($status, $body);

    $payload = (new SettingController())->testOAuthConfig(AdminRequest::create('/', 'POST', [
        'provider' => 'google',
        'values'   => ['client_id' => 'id', 'client_secret' => 'secret'],
    ]), $registry, $config, $flow, $apple)->getData(true);

    expect($payload['problem'])->toBe($problem)
        ->and($payload['verified'])->toBeFalse()
        ->and($payload['configured'])->toBeTrue()
        ->and($payload['message'])->toStartWith('Google');
})->with([
    'wrong secret'        => [401, ['error' => 'invalid_client'], 'invalid_client'],
    'unknown app'         => [400, ['error' => 'unauthorized_client'], 'invalid_client'],
    'unregistered url'    => [400, ['error' => 'redirect_uri_mismatch'], 'redirect_uri_mismatch'],
    'provider outage'     => [503, [], 'unreachable'],
    'unrecognised answer' => [400, ['error' => 'something_new'], 'inconclusive'],
]);

it('reports an unreachable provider', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $provider->mock->append(new ConnectException('Connection refused', new PsrRequest('POST', 'https://oauth2.googleapis.com/token')));

    $payload = (new SettingController())->testOAuthConfig(AdminRequest::create('/', 'POST', [
        'provider' => 'google',
        'values'   => ['client_id' => 'id', 'client_secret' => 'secret'],
    ]), $registry, $config, $flow, $apple)->getData(true);

    expect($payload['problem'])->toBe('unreachable');
});

it('does not call the provider while credentials are missing', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();

    $payload = (new SettingController())->testOAuthConfig(AdminRequest::create('/', 'POST', [
        'provider' => 'google',
        'values'   => ['client_id' => 'id'],
    ]), $registry, $config, $flow, $apple)->getData(true);

    expect($payload['missing'])->toBe(['Client Secret'])
        ->and($provider->history)->toBe([]);
});

// ---------------------------------------------------------------------------
// Enabling
// ---------------------------------------------------------------------------

it('refuses to offer a provider whose credentials the provider rejects', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $provider->says(401, ['error' => 'invalid_client']);

    $response = (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'allow_registration' => false,
        'providers'          => ['google' => ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'wrong']],
    ]), $registry, $config, $flow, $apple);

    // Refused as a whole: not even the unrelated switch is written.
    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toMatchArray(['code' => 'oauth_provider_check_failed', 'provider' => 'google', 'problem' => 'invalid_client'])
        ->and(setting_controller_oauth_stored())->toBe([]);
});

it('refuses to offer a provider with missing credentials', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();

    $response = (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['github' => ['enabled' => true]],
    ]), $registry, $config, $flow, $apple);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['problem'])->toBe('missing_credentials')
        ->and($provider->history)->toBe([]);
});

it('offers a provider once the provider accepts its credentials', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $provider->accepts();

    $response = (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'right']],
    ]), $registry, $config, $flow, $apple);

    expect($response->getStatusCode())->toBe(200)
        ->and($registry->driver('google')->isEnabled())->toBeTrue();
});

it('rechecks a live provider only when its credentials change', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $controller                                    = new SettingController();
    $provider->accepts();
    $controller->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'right']],
    ]), $registry, $config, $flow, $apple);

    // The form resubmits every provider on each save; an untouched one is not re-asked.
    $controller->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => true, 'client_id' => 'id', 'client_secret' => '', 'hosted_domain' => '']],
    ]), $registry, $config, $flow, $apple);
    expect($provider->history)->toHaveCount(1);

    // A new secret on a live provider is checked before it replaces the working one.
    $provider->says(401, ['error' => 'invalid_client']);
    $response = $controller->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'typo']],
    ]), $registry, $config, $flow, $apple);

    expect($response->getStatusCode())->toBe(422)
        ->and($config->forProvider('google')->secret('client_secret'))->toBe('right');
});

it('switches a provider off without asking it anything', function () {
    [$registry, $config, $flow, $apple, $provider] = setting_controller_oauth_services();
    $provider->accepts();
    (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'right']],
    ]), $registry, $config, $flow, $apple);

    $response = (new SettingController())->saveOAuthConfig(setting_controller_oauth_save([
        'providers' => ['google' => ['enabled' => false, 'client_secret' => 'anything']],
    ]), $registry, $config, $flow, $apple);

    expect($response->getStatusCode())->toBe(200)
        ->and($config->forProvider('google')->enabled())->toBeFalse()
        ->and($provider->history)->toHaveCount(1);
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
    'auto link toggle'       => [['auto_link' => false], false],
    'non boolean auto link'  => [['auto_link' => 'maybe'], true],
    'full pem key'           => [['providers' => ['apple' => ['private_key' => "-----BEGIN PRIVATE KEY-----\nabc"]]], false],
    // Empty means "keep the stored key" and must not be rejected.
    'empty key keeps stored' => [['providers' => ['apple' => ['private_key' => '']]], false],
    'key without pem header' => [['providers' => ['apple' => ['private_key' => 'MIGTAgEAMBMGByqGSM49']]], true],
    'oversized client id'    => [['providers' => ['google' => ['client_id' => str_repeat('a', 513)]]], true],
]);
