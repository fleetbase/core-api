<?php

use Fleetbase\Auth\OAuth\Contracts\OAuthProviderDriver;
use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Fleetbase\Auth\OAuth\OAuthProviderRegistry;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Http\Controllers\Internal\v1\OAuthController;
use Fleetbase\Http\Requests\Internal\OAuthExchangeRequest;
use Fleetbase\Http\Requests\Internal\OAuthRedirectRequest;
use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\OAuthState;
use Fleetbase\Models\User;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Fleetbase\Services\OAuth\OAuthFlowService;
use Fleetbase\Services\OAuth\OAuthIdentityService;
use Fleetbase\Services\OAuth\OAuthStateService;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

// redirect() likewise does not exist without illuminate/foundation.
if (!function_exists('Fleetbase\\Http\\Controllers\\Internal\\v1\\redirect')) {
    eval('namespace Fleetbase\\Http\\Controllers\\Internal\\v1; function redirect() { return new \\OAuthControllerRedirectorFake(); }');
}

// Shared event shim. core-api has no illuminate/foundation, so the global event()
// helper does not exist under test; PHP resolves an unqualified call to the current
// namespace first, so this intercepts the services' calls. It must be identical in
// every test file that needs it — the first file Pest loads wins, and a non-recording
// variant loading first would silently blind another file's event assertions.
if (!function_exists('oauth_test_record_event')) {
    function oauth_test_record_event(object $event): void
    {
        $GLOBALS['oauth_test_events'][] = $event;
    }

    /**
     * @return array<int, object>
     */
    function oauth_test_events(): array
    {
        return $GLOBALS['oauth_test_events'] ?? [];
    }

    function oauth_test_reset_events(): void
    {
        $GLOBALS['oauth_test_events'] = [];
    }
}

if (!function_exists('Fleetbase\\Services\\OAuth\\event')) {
    eval('namespace Fleetbase\\Services\\OAuth; function event($event = null) { if (is_object($event)) { \\oauth_test_record_event($event); } return $event; }');
}

class OAuthControllerRedirectorFake
{
    public function away(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public string $url = '';

    public function getTargetUrl(): string
    {
        return $this->url;
    }
}

class OAuthControllerEncrypterFake implements Encrypter
{
    public function encrypt($value, $serialize = true)
    {
        return 'enc:' . base64_encode($serialize ? serialize($value) : (string) $value);
    }

    public function decrypt($payload, $unserialize = true)
    {
        $value = base64_decode(substr((string) $payload, 4), true);

        return $unserialize ? unserialize((string) $value) : (string) $value;
    }

    public function getKey()
    {
        return 'test-key';
    }
}

class OAuthControllerCacheFake
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

class OAuthControllerHashFake
{
    public function make(string $value, array $options = []): string
    {
        return 'hashed:' . $value;
    }

    public function check(string $value, ?string $hashed = null): bool
    {
        return $hashed === 'hashed:' . $value;
    }

    public function info(string $hashed): array
    {
        return ['algo' => 'fake'];
    }
}

class OAuthControllerRedisFake
{
    public array $values = [];

    public array $sets = [];

    public function set(string $key, mixed $value, mixed ...$options): bool
    {
        $this->values[$key] = $value;
        $this->sets[]       = compact('key', 'value', 'options');

        return true;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function del(?string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function connection(): self
    {
        return $this;
    }
}

class OAuthControllerResponseCacheFake
{
    public function clear(): void
    {
    }
}

class OAuthControllerRateLimiterFake
{
    public array $hits = [];

    public function __construct(public int $limitAfter = PHP_INT_MAX)
    {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return count($this->hits) >= $this->limitAfter;
    }

    public function hit(string $key, int $decaySeconds = 60): int
    {
        $this->hits[] = $key;

        return count($this->hits);
    }
}

/**
 * A driver that returns a canned profile instead of talking to a provider.
 *
 * The real drivers are covered by ProviderProfileNormalizationTest; what matters
 * here is the controller's behaviour around whatever a driver produces.
 */
class OAuthControllerFakeDriver implements OAuthProviderDriver
{
    /**
     * Set per-test to steer the fake.
     *
     * @var array<string, mixed>
     */
    public static array $behaviour = [];

    public function __construct(
        protected OAuthProviderConfig $config,
        protected Request $request,
        protected IdTokenVerifier $verifier,
    ) {
    }

    public static function id(): string
    {
        return 'fakeprovider';
    }

    public static function label(): string
    {
        return 'Fake Provider';
    }

    public static function icon(): string
    {
        return 'circle';
    }

    public static function configSchema(): array
    {
        return ['client_id' => ['label' => 'Client ID', 'required' => true]];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) (self::$behaviour['enabled'] ?? true);
    }

    public function usesFormPostCallback(): bool
    {
        return false;
    }

    public function authorizationUrl(string $state, string $codeVerifier, string $redirectUri): string
    {
        return 'https://provider.test/authorize?' . http_build_query([
            'state'         => $state,
            'redirect_uri'  => $redirectUri,
            'verifier_hash' => hash('sha256', $codeVerifier),
        ]);
    }

    public function exchange(string $code, string $codeVerifier, string $redirectUri, array $callbackPayload = []): OAuthUserProfile
    {
        if (isset(self::$behaviour['throw'])) {
            throw self::$behaviour['throw'];
        }

        return self::$behaviour['profile'] ?? new OAuthUserProfile('fakeprovider', 'subject-1', 'ada@example.com', true, 'Ada Lovelace');
    }

    public function verifyCredentials(string $redirectUri): Fleetbase\Auth\OAuth\CredentialCheck
    {
        return Fleetbase\Auth\OAuth\CredentialCheck::Verified;
    }
}

function oauth_controller_database(array $config = []): Capsule
{
    EloquentModel::clearBootedModels();
    OAuthControllerFakeDriver::$behaviour = [];

    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];

    $container = bind_test_container(array_merge([
        'app.env'                                      => 'testing',
        'app.timezone'                                 => 'UTC',
        'app.key'                                      => 'base64:' . base64_encode(str_repeat('a', 32)),
        'app.url'                                      => 'https://api.fleetbase.test',
        'api.cache.enabled'                            => false,
        'activitylog.enabled'                          => false,
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'fleetbase.console.host'                       => 'console.fleetbase.test',
        'fleetbase.console.secure'                     => true,
        'fleetbase.api.routing.prefix'                 => '/',
        'fleetbase.api.routing.internal_prefix'        => 'int',
        'oauth.enabled'                                => true,
        'oauth.allow_registration'                     => true,
        // Explicit: the test container keeps config between tests, so one test
        // switching this off would otherwise switch it off for every later one.
        'oauth.auto_link'                              => true,
        'oauth.console_callback_path'                  => '/auth/oauth/callback',
        'oauth.ttl'                                    => ['authorization' => 600, 'handoff' => 120, 'registration_intent' => 900],
        'oauth.providers'                              => [
            'fakeprovider' => ['driver' => OAuthControllerFakeDriver::class, 'enabled' => true, 'client_id' => 'cid'],
        ],
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.column_names.model_morph_key'      => 'model_uuid',
    ], $config));

    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));

    $cache = new OAuthControllerCacheFake();
    $container->instance('cache', $cache);
    $container->instance('hash', new OAuthControllerHashFake());
    $container->instance('redis', new OAuthControllerRedisFake());
    $container->instance('responsecache', new OAuthControllerResponseCacheFake());
    $container->instance(Illuminate\Cache\RateLimiter::class, new OAuthControllerRateLimiterFake());
    Cache::swap($cache);
    foreach (['cache', 'hash', 'redis', 'responsecache', 'log'] as $facade) {
        Facade::clearResolvedInstance($facade);
    }
    Facade::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = app('db')->connection('mysql')->getSchemaBuilder();

    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable()->index();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('slug')->nullable();
        $table->string('password')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('google_user_id')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    $schema->create('oauth_identities', function ($table) {
        $table->string('uuid')->primary();
        $table->string('user_uuid');
        $table->string('provider', 40);
        $table->string('provider_user_id', 191);
        $table->string('provider_email')->nullable();
        $table->boolean('email_verified')->default(false);
        $table->text('meta')->nullable();
        $table->timestamp('last_login_at')->nullable();
        $table->timestamps();
        $table->unique(['provider', 'provider_user_id']);
    });
    $schema->create('oauth_states', function ($table) {
        $table->string('uuid')->primary();
        $table->string('purpose', 24);
        $table->string('token_hash', 64)->unique();
        $table->string('provider', 40)->nullable();
        $table->string('intent', 16)->nullable();
        $table->string('user_uuid')->nullable();
        $table->text('payload')->nullable();
        $table->string('ip_hash', 64)->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->timestamps();
    });

    return $capsule;
}

function oauth_controller_services(): array
{
    $encrypter  = new OAuthControllerEncrypterFake();
    $states     = new OAuthStateService($encrypter);
    $config     = new OAuthConfigRepository($encrypter);
    $identities = new OAuthIdentityService();
    $registry   = new OAuthProviderRegistry($config, Request::create('/'), new IdTokenVerifier());
    $flow       = new OAuthFlowService($registry, $states, $config);

    // Bound as well as injected: Support\OAuth resolves from the container, and in
    // production CoreServiceProvider binds these scoped so the facade and the
    // controller share one instance per request. Registering the same objects here
    // keeps the test faithful to that rather than giving the facade a second set.
    app()->instance(OAuthStateService::class, $states);
    app()->instance(OAuthIdentityService::class, $identities);
    app()->instance(OAuthConfigRepository::class, $config);
    app()->instance(OAuthProviderRegistry::class, $registry);

    return [new OAuthController($registry, $flow, $states, $identities, $config), $states, $identities, $config, $flow];
}

function oauth_controller_user(array $attributes = []): User
{
    app('db')->connection('mysql')->table('users')->insert(array_merge([
        'uuid'              => 'user-1',
        'email'             => 'ada@example.com',
        'name'              => 'Ada',
        'type'              => 'user',
        'status'            => 'active',
        'email_verified_at' => '2024-01-01 00:00:00',
        'created_at'        => Carbon::now(),
        'updated_at'        => Carbon::now(),
    ], $attributes));

    return User::query()->findOrFail($attributes['uuid'] ?? 'user-1');
}

function oauth_controller_link(User $user, string $subject = 'subject-1', string $provider = 'fakeprovider'): OAuthIdentity
{
    return OAuthIdentity::query()->create([
        'user_uuid'        => $user->uuid,
        'provider'         => $provider,
        'provider_user_id' => $subject,
        'provider_email'   => $user->email,
        'email_verified'   => true,
    ]);
}

/**
 * core-api has no illuminate/foundation, so tests/Pest.php polyfills FormRequest as a
 * bare Request with no validation machinery. These build the request object the
 * controller receives; the rules themselves are exercised for real against
 * Illuminate's validator further down.
 */
function oauth_redirect_request(array $query = []): OAuthRedirectRequest
{
    return OAuthRedirectRequest::create('/int/v1/auth/oauth/fakeprovider/redirect', 'GET', $query);
}

function oauth_exchange_request(array $body): OAuthExchangeRequest
{
    return OAuthExchangeRequest::create('/int/v1/auth/oauth/exchange', 'POST', $body);
}

/**
 * @param array<string, mixed> $rules
 * @param array<string, mixed> $data
 */
function oauth_validator(array $rules, array $data): Illuminate\Contracts\Validation\Validator
{
    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');

    return (new Illuminate\Validation\Factory($translator))->make($data, $rules);
}

/**
 * @return array<string, string>
 */
function oauth_fragment(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_FRAGMENT), $fragment);

    /** @var array<string, string> $fragment */
    return $fragment;
}

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

it('advertises only enabled providers and leaks no credentials', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $payload = $controller->providers()->getData(true);

    expect($payload['providers'])->toBe([[
        'id'    => 'fakeprovider',
        'label' => 'Fake Provider',
        'icon'  => 'circle',
    ]])
        ->and(json_encode($payload))->not->toContain('cid');
});

it('advertises nothing when oauth is switched off', function () {
    oauth_controller_database(['oauth.enabled' => false]);
    [$controller] = oauth_controller_services();

    expect($controller->providers()->getData(true)['providers'])->toBe([]);
});

it('says whether sign-ups are open, so the sign-up page can leave its buttons out', function (bool $open) {
    oauth_controller_database(['oauth.allow_registration' => $open]);
    [$controller] = oauth_controller_services();

    expect($controller->providers()->getData(true)['allow_registration'])->toBe($open);
})->with(['open' => true, 'closed' => false]);

// ---------------------------------------------------------------------------
// Redirect
// ---------------------------------------------------------------------------

it('redirects to the provider with state and a pkce challenge', function () {
    oauth_controller_database();
    [$controller, $states] = oauth_controller_services();

    $response = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

    $row = OAuthState::query()->first();

    expect($response->getTargetUrl())->toStartWith('https://provider.test/authorize?')
        ->and($row->purpose)->toBe(OAuthState::PURPOSE_AUTHORIZATION)
        ->and($row->provider)->toBe('fakeprovider')
        ->and($row->intent)->toBe('login')
        // The state in the URL must be the token whose sha256 we stored, never the
        // row's own identifier.
        ->and($row->token_hash)->toBe(hash('sha256', $query['state']))
        // redirect_uri is computed from config, never taken from the request.
        ->and($query['redirect_uri'])->toBe('https://api.fleetbase.test/int/v1/auth/oauth/fakeprovider/callback');
});

it('stores the pkce verifier encrypted and never puts it in the url', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $response = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

    $row = OAuthState::query()->first();

    expect($row->payload)->toStartWith('enc:')
        ->and($row->payload)->not->toContain('code_verifier')
        ->and($response->getTargetUrl())->not->toContain('code_verifier')
        // The fake driver echoes a hash of the verifier it was handed, proving the
        // controller generated one and passed it through.
        ->and($query['verifier_hash'])->toBeString()->toHaveLength(64);
});

it('carries a signup intent through to the state row', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $controller->redirect(oauth_redirect_request(['intent' => 'signup']), 'fakeprovider');

    expect(OAuthState::query()->first()->intent)->toBe('signup');
});

it('rejects an unknown provider', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $response = $controller->redirect(oauth_redirect_request(), 'nope');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true)['code'])->toBe('unknown_provider');
});

it('refuses a disabled provider before issuing any state', function () {
    oauth_controller_database();
    OAuthControllerFakeDriver::$behaviour = ['enabled' => false];
    [$controller]                         = oauth_controller_services();

    $response = $controller->redirect(oauth_redirect_request(), 'fakeprovider');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['code'])->toBe('provider_disabled')
        ->and(OAuthState::query()->count())->toBe(0);
});

it('keeps a safe return path and rejects every unsafe one', function (string $returnTo, ?string $expected) {
    oauth_controller_database();
    [$controller, $states, $identities, $config, $flow] = oauth_controller_services();

    expect($flow->sanitizeReturnPath($returnTo))->toBe($expected);
})->with([
    'plain path'        => ['/dashboard', '/dashboard'],
    'path with query'   => ['/orders?status=open', '/orders?status=open'],
    'absolute url'      => ['https://evil.tld/x', null],
    'protocol relative' => ['//evil.tld', null],
    'backslash trick'   => ['/\\evil.tld', null],
    'scheme relative'   => ['http://evil.tld', null],
    'crlf injection'    => ["/ok\r\nLocation: https://evil.tld", null],
    'null byte'         => ["/ok\0", null],
    'not rooted'        => ['dashboard', null],
    'empty'             => ['', null],
    'too long'          => ['/' . str_repeat('a', 512), null],
]);

it('rejects an unsafe return path at the request boundary too', function () {
    oauth_controller_database();
    $rules = (new OAuthRedirectRequest())->rules();

    // Defence in depth: the form request rejects these before the service ever
    // sees them, and the service sanitizes again regardless.
    expect(oauth_validator($rules, ['return_to' => 'https://evil.tld/x'])->fails())->toBeTrue()
        ->and(oauth_validator($rules, ['return_to' => '//evil.tld'])->fails())->toBeTrue()
        ->and(oauth_validator($rules, ['return_to' => 'dashboard'])->fails())->toBeTrue()
        ->and(oauth_validator($rules, ['return_to' => str_repeat('/a', 400)])->fails())->toBeTrue()
        ->and(oauth_validator($rules, ['return_to' => '/dashboard'])->fails())->toBeFalse()
        ->and(oauth_validator($rules, [])->fails())->toBeFalse();
});

it('only accepts a login or signup intent', function () {
    oauth_controller_database();
    $rules = (new OAuthRedirectRequest())->rules();

    expect(oauth_validator($rules, ['intent' => 'login'])->fails())->toBeFalse()
        ->and(oauth_validator($rules, ['intent' => 'signup'])->fails())->toBeFalse()
        // 'link' is a protected-route flow and must not be startable anonymously.
        ->and(oauth_validator($rules, ['intent' => 'link'])->fails())->toBeTrue()
        ->and(oauth_validator($rules, ['intent' => 'anything'])->fails())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Callback
// ---------------------------------------------------------------------------

it('completes a callback and returns a handoff code in the url fragment', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(['return_to' => '/dashboard']), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['code' => 'auth-code', 'state' => $query['state']]),
        'fakeprovider'
    );

    $url      = $response->getTargetUrl();
    $fragment = oauth_fragment($url);

    expect($url)->toStartWith('https://console.fleetbase.test/auth/oauth/callback#')
        // The code lives in the fragment, which is never sent to the console's web
        // server — so it cannot land in an access log or a Referer header.
        ->and($url)->not->toContain('?handoff=')
        ->and($fragment['handoff'])->toBeString()->toHaveLength(64)
        ->and($fragment['return_to'])->toBe('/dashboard')
        ->and(OAuthState::query()->where('purpose', OAuthState::PURPOSE_HANDOFF)->count())->toBe(1);
});

it('accepts a form posted callback', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    // Apple form-posts its callback whenever the name/email scopes are requested.
    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'POST', ['code' => 'auth-code', 'state' => $query['state']]),
        'fakeprovider'
    );

    expect(oauth_fragment($response->getTargetUrl())['handoff'])->toBeString();
});

it('reports an invalid state without attempting an exchange', function (array $params) {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    OAuthControllerFakeDriver::$behaviour = ['throw' => new RuntimeException('should never be reached')];

    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', $params),
        'fakeprovider'
    );

    expect(oauth_fragment($response->getTargetUrl())['error'])->toBe('invalid_state');
})->with([
    'no state'      => [['code' => 'auth-code']],
    'unknown state' => [['code' => 'auth-code', 'state' => str_repeat('z', 64)]],
    'empty state'   => [['code' => 'auth-code', 'state' => '']],
]);

it('refuses to replay a state', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    $request = fn () => Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['code' => 'auth-code', 'state' => $query['state']]);

    expect(oauth_fragment($controller->callback($request(), 'fakeprovider')->getTargetUrl()))->toHaveKey('handoff')
        ->and(oauth_fragment($controller->callback($request(), 'fakeprovider')->getTargetUrl())['error'])->toBe('invalid_state');
});

it('rejects a state issued for a different provider', function () {
    oauth_controller_database([
        'oauth.providers' => [
            'fakeprovider'  => ['driver' => OAuthControllerFakeDriver::class, 'enabled' => true, 'client_id' => 'cid'],
            'otherprovider' => ['driver' => OAuthControllerFakeDriver::class, 'enabled' => true, 'client_id' => 'cid2'],
        ],
    ]);
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/otherprovider/callback', 'GET', ['code' => 'c', 'state' => $query['state']]),
        'otherprovider'
    );

    expect(oauth_fragment($response->getTargetUrl())['error'])->toBe('invalid_state');
});

it('surfaces a declined authorization', function (string $providerError, string $expected) {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['error' => $providerError, 'state' => $query['state']]),
        'fakeprovider'
    );

    expect(oauth_fragment($response->getTargetUrl())['error'])->toBe($expected);
})->with([
    'user cancelled' => ['access_denied', 'access_denied'],
    'other failure'  => ['server_error', 'provider_error'],
]);

it('surfaces a policy failure from the driver', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    OAuthControllerFakeDriver::$behaviour = ['throw' => new OAuthException('hosted_domain_mismatch')];

    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['code' => 'c', 'state' => $query['state']]),
        'fakeprovider'
    );

    expect(oauth_fragment($response->getTargetUrl())['error'])->toBe('hosted_domain_mismatch');
});

it('never leaks a provider exception message to the browser', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    // Guzzle exception messages embed a truncated response body, which can contain a
    // token. Only a fixed code may reach the URL.
    OAuthControllerFakeDriver::$behaviour = ['throw' => new RuntimeException('500 response: {"access_token":"leaked-token"}')];

    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['code' => 'c', 'state' => $query['state']]),
        'fakeprovider'
    );

    expect(oauth_fragment($response->getTargetUrl())['error'])->toBe('exchange_failed')
        ->and($response->getTargetUrl())->not->toContain('leaked-token');
});

// ---------------------------------------------------------------------------
// Exchange
// ---------------------------------------------------------------------------

/**
 * Run a full redirect → callback and return the handoff code.
 */
function oauth_controller_handoff(OAuthController $controller, array $redirectQuery = []): string
{
    $redirect = $controller->redirect(oauth_redirect_request($redirectQuery), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    $callback = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['code' => 'auth-code', 'state' => $query['state']]),
        'fakeprovider'
    );

    return oauth_fragment($callback->getTargetUrl())['handoff'];
}

it('authenticates a known identity and issues a sanctum token', function () {
    $capsule      = oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user = oauth_controller_user();
    oauth_controller_link($user);

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['token'])->toBeString()
        ->and($payload['type'])->toBe('user')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1)
        ->and(OAuthIdentity::query()->first()->last_login_at)->not->toBeNull()
        // The redeemed handoff row records who it resolved to, for the audit trail.
        ->and(OAuthState::query()->where('purpose', OAuthState::PURPOSE_HANDOFF)->first()->user_uuid)->toBe('user-1');
});

it('refuses to redeem a handoff code twice', function () {
    $capsule      = oauth_controller_database();
    [$controller] = oauth_controller_services();

    oauth_controller_link(oauth_controller_user());
    $handoff = oauth_controller_handoff($controller);

    $controller->exchange(oauth_exchange_request(['code' => $handoff]));
    $second = $controller->exchange(oauth_exchange_request(['code' => $handoff]));

    expect($second->getStatusCode())->toBe(400)
        ->and($second->getData(true)['code'])->toBe('invalid_exchange_code')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});

it('reports an expired handoff code the same way as an unknown one', function () {
    oauth_controller_database();
    Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'UTC'));
    [$controller] = oauth_controller_services();

    oauth_controller_link(oauth_controller_user());
    $handoff = oauth_controller_handoff($controller);

    Carbon::setTestNow(Carbon::parse('2026-09-18 10:05:00', 'UTC'));

    $response = $controller->exchange(oauth_exchange_request(['code' => $handoff]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['code'])->toBe('invalid_exchange_code');

    Carbon::setTestNow();
});

it('challenges for two factor instead of issuing a token', function () {
    $capsule      = oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user = oauth_controller_user();
    oauth_controller_link($user);
    $capsule->getConnection('mysql')->table('settings')->insert([
        'key'   => 'user.user-1.2fa',
        'value' => json_encode(['enabled' => true, 'method' => 'email']),
    ]);

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));
    $payload  = $response->getData(true);

    // Signing in through a provider does not exempt anyone from 2FA.
    expect($payload['isEnabled'])->toBeTrue()
        ->and($payload['twoFaSession'])->toBeString()
        ->and($payload)->not->toHaveKey('token')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(0);
});

it('applies the same gates password login applies', function (array $attributes, int $status, string $code) {
    $capsule      = oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user = oauth_controller_user($attributes);
    oauth_controller_link($user);

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    expect($response->getStatusCode())->toBe($status)
        ->and($response->getData(true)['code'])->toBe($code)
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(0);
})->with([
    'customer accounts'  => [['type' => 'customer'], 403, 'customer_login_not_allowed'],
    'unverified account' => [['email_verified_at' => null, 'type' => 'user'], 400, 'not_verified'],
]);

it('treats a soft deleted account as an unknown identity', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user = oauth_controller_user();
    oauth_controller_link($user);
    User::query()->where('uuid', 'user-1')->update(['deleted_at' => Carbon::now()]);

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    // Not a distinguishable error: telling a caller that an account exists but is
    // deleted is an enumeration oracle.
    expect($response->getData(true)['status'] ?? null)->toBe('registration_required');
});

it('offers registration for an unknown identity', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));
    $payload  = $response->getData(true);

    expect($payload['status'])->toBe('registration_required')
        ->and($payload['intent'])->toStartWith('rti_')
        ->and($payload['prefill'])->toBe([
            'name'           => 'Ada Lovelace',
            'email'          => 'ada@example.com',
            'email_verified' => true,
        ])
        ->and(OAuthState::query()->where('purpose', OAuthState::PURPOSE_REGISTRATION_INTENT)->count())->toBe(1);
});

it('refuses registration when sign-ups are closed', function () {
    oauth_controller_database(['oauth.allow_registration' => false]);
    [$controller] = oauth_controller_services();

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['code'])->toBe('registration_disabled')
        ->and(OAuthState::query()->where('purpose', OAuthState::PURPOSE_REGISTRATION_INTENT)->count())->toBe(0);
});

it('links and signs in a console account whose confirmed email the provider verified', function (string $type) {
    oauth_controller_database();
    [$controller, , $identities] = oauth_controller_services();
    oauth_test_reset_events();

    $user = oauth_controller_user(['uuid' => 'user-1', 'email' => 'ada@example.com', 'type' => $type]);

    $data = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]))->getData(true);

    $events = array_values(array_filter(oauth_test_events(), fn ($event) => $event instanceof Fleetbase\Events\OAuthIdentityLinked));

    expect($data['token'] ?? null)->toBeString()
        ->and($data['linked'])->toBe('fakeprovider')
        ->and($data['linked_label'])->toBe('Fake Provider')
        ->and($identities->findBySubjectForUser($user, 'fakeprovider')?->provider_user_id)->toBe('subject-1')
        // The account holder is emailed about it, worded for an automatic link.
        ->and($events)->toHaveCount(1)
        ->and($events[0]->method)->toBe(Fleetbase\Events\OAuthIdentityLinked::METHOD_AUTOMATIC);
})->with(['user', 'admin']);

it('tells someone who pressed sign up that they already had an account', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();
    oauth_controller_link(oauth_controller_user());

    $data = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller, ['intent' => 'signup'])]))->getData(true);

    expect($data['token'] ?? null)->toBeString()
        ->and($data['existing_account'])->toBeTrue();
});

it('says nothing about an existing account on an ordinary sign-in', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();
    oauth_controller_link(oauth_controller_user());

    $data = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]))->getData(true);

    expect($data['token'] ?? null)->toBeString()
        ->and($data)->not->toHaveKey('existing_account');
});

it('reports both the automatic link and the existing account on a sign-up', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();
    oauth_controller_user(['uuid' => 'user-1', 'email' => 'ada@example.com']);

    $data = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller, ['intent' => 'signup'])]))->getData(true);

    expect($data['linked'])->toBe('fakeprovider')
        ->and($data['existing_account'])->toBeTrue();
});

it('still asks for two-factor after linking automatically', function () {
    $capsule      = oauth_controller_database();
    [$controller] = oauth_controller_services();
    oauth_controller_user(['uuid' => 'user-1', 'email' => 'ada@example.com']);
    $capsule->getConnection('mysql')->table('settings')->insert([
        'key'   => 'user.user-1.2fa',
        'value' => json_encode(['enabled' => true, 'method' => 'email']),
    ]);

    $data = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]))->getData(true);

    expect($data)->not->toHaveKey('token')
        ->and($data['twoFaSession'])->toBeString()
        ->and($data['linked'])->toBe('fakeprovider')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(0)
        // The 2FA session is started only after the provider has vouched for the user,
        // and lives for a relative TTL in seconds rather than an absolute timestamp.
        ->and(app('redis')->sets)->toHaveCount(1)
        ->and(app('redis')->sets[0]['key'])->toStartWith('two_fa_session:user-1:')
        ->and(app('redis')->sets[0]['options'])->toBe(['EX', TwoFactorAuth::SESSION_TTL]);
});

it('asks the user to link by hand when automatic linking does not apply', function (array $account, array $config, ?Closure $before = null) {
    oauth_controller_database($config);
    [$controller, , $identities] = oauth_controller_services();
    oauth_test_reset_events();

    $user = oauth_controller_user(array_merge(['uuid' => 'user-1', 'email' => 'ada@example.com'], $account));
    if ($before) {
        $before($user);
    }

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    // Not signed in, not linked, no signup offered for someone else's address.
    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('link_required')
        ->and(OAuthIdentity::query()->where('provider_user_id', 'subject-1')->exists())->toBeFalse()
        ->and(OAuthState::query()->where('purpose', OAuthState::PURPOSE_REGISTRATION_INTENT)->count())->toBe(0);
})->with([
    'switched off by the administrator' => [[], ['oauth.auto_link' => false]],
    // Someone could have signed up with this address without owning it.
    'account email never confirmed'     => [['email_verified_at' => null], []],
    'customer account'                  => [['type' => 'customer'], []],
    'contact account'                   => [['type' => 'contact'], []],
    'driver account'                    => [['type' => 'driver'], []],
    'no type'                           => [['type' => null], []],
    // A different account from the same provider is already linked; not replaced silently.
    'provider already linked'           => [[], [], fn (User $user) => oauth_controller_link($user, 'another-subject')],
]);

it('never links an address the provider did not verify, even to a confirmed account', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();
    oauth_controller_user(['uuid' => 'user-1', 'email' => 'ada@example.com']);
    OAuthControllerFakeDriver::$behaviour = [
        'profile' => new OAuthUserProfile('fakeprovider', 'subject-9', 'ada@example.com', false, 'Mallory'),
    ];

    $data = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]))->getData(true);

    expect($data)->not->toHaveKey('token')
        ->and(OAuthIdentity::query()->count())->toBe(0);
});

it('never links when two accounts share the address', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();
    oauth_controller_user(['uuid' => 'user-1', 'email' => 'ada@example.com']);
    oauth_controller_user(['uuid' => 'user-2', 'email' => 'ada@example.com']);

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    expect($response->getData(true))->not->toHaveKey('token')
        ->and(OAuthIdentity::query()->count())->toBe(0);
});

it('asks to sign in and link for an unverified address that already has an account', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    oauth_controller_user(['uuid' => 'user-1', 'email' => 'ada@example.com']);
    OAuthControllerFakeDriver::$behaviour = [
        'profile' => new OAuthUserProfile('fakeprovider', 'subject-9', 'ada@example.com', false, 'Ada'),
    ];

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    // Not a sign-up form for an address that is already taken — that would be refused
    // as a duplicate. And, unverified, never a sign-in or a link.
    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('link_required')
        ->and(OAuthIdentity::query()->count())->toBe(0)
        ->and(OAuthState::query()->where('purpose', OAuthState::PURPOSE_REGISTRATION_INTENT)->count())->toBe(0);
});

it('offers a sign-up for an unverified address no account uses', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    OAuthControllerFakeDriver::$behaviour = [
        'profile' => new OAuthUserProfile('fakeprovider', 'subject-9', 'new@example.com', false, 'New Person'),
    ];

    $data = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]))->getData(true);

    expect($data['status'] ?? null)->toBe('registration_required')
        ->and($data['prefill']['email_verified'])->toBeFalse();
});

it('does not match an apple private relay alias against an existing account', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    oauth_controller_user(['uuid' => 'user-1', 'email' => 'abc@privaterelay.appleid.com']);
    OAuthControllerFakeDriver::$behaviour = [
        'profile' => new OAuthUserProfile('fakeprovider', 'subject-9', 'abc@privaterelay.appleid.com', true, 'Ada', null, ['private_relay' => true]),
    ];

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    // Relay aliases are unique per application, so a match is never the same person.
    expect($response->getData(true)['status'] ?? null)->toBe('registration_required');
});

it('refuses an exchange for a provider switched off mid flight', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    oauth_controller_link(oauth_controller_user());
    $handoff = oauth_controller_handoff($controller);

    OAuthControllerFakeDriver::$behaviour = ['enabled' => false];

    $response = $controller->exchange(oauth_exchange_request(['code' => $handoff]));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['code'])->toBe('provider_disabled');
});

it('never returns a provider token or the handoff code in any response', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    oauth_controller_link(oauth_controller_user());
    $handoff = oauth_controller_handoff($controller);

    $body = json_encode($controller->exchange(oauth_exchange_request(['code' => $handoff]))->getData(true));

    expect($body)->not->toContain($handoff)
        ->and($body)->not->toContain('access_token')
        ->and($body)->not->toContain('refresh_token');
});

it('rejects a malformed handoff code at the request boundary', function () {
    oauth_controller_database();
    $rules = (new OAuthExchangeRequest())->rules();

    // Rejecting on shape keeps malformed input away from a database lookup entirely.
    expect(oauth_validator($rules, ['code' => 'too-short'])->fails())->toBeTrue()
        ->and(oauth_validator($rules, [])->fails())->toBeTrue()
        ->and(oauth_validator($rules, ['code' => str_repeat('a', 65)])->fails())->toBeTrue()
        ->and(oauth_validator($rules, ['code' => str_repeat('a', 64)])->fails())->toBeFalse();
});

it('rate limits the exchange endpoint independently of the throttle middleware', function () {
    oauth_controller_database();
    app()->instance(Illuminate\Cache\RateLimiter::class, new OAuthControllerRateLimiterFake(0));
    Facade::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);
    [$controller] = oauth_controller_services();

    // Fleetbase's ThrottleRequests overwrites per-route limits from config, so the
    // controller carries its own limiter.
    $response = $controller->exchange(oauth_exchange_request(['code' => str_repeat('a', 64)]));

    expect($response->getStatusCode())->toBe(429)
        ->and($response->getData(true)['code'])->toBe('rate_limited');
});

// ---------------------------------------------------------------------------
// Account linking
// ---------------------------------------------------------------------------

function oauth_authed(User $user, string $uri = '/int/v1/auth/oauth/identities', string $method = 'GET', array $body = []): Request
{
    $request = Request::create($uri, $method, $body);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function oauth_authed_complete(User $user, string $code): OAuthExchangeRequest
{
    $request = OAuthExchangeRequest::create('/int/v1/auth/oauth/link/complete', 'POST', ['code' => $code]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

/**
 * Start a link as $user and run the provider callback, returning the fragment the
 * console receives.
 *
 * @return array<string, string>
 */
function oauth_link_callback(OAuthController $controller, User $user): array
{
    $started = $controller->link(oauth_authed($user, '/int/v1/auth/oauth/fakeprovider/link', 'POST'), 'fakeprovider')->getData(true);
    parse_str((string) parse_url($started['redirect_url'], PHP_URL_QUERY), $query);

    $callback = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['code' => 'auth-code', 'state' => $query['state']]),
        'fakeprovider'
    );

    return oauth_fragment($callback->getTargetUrl());
}

it('lists linked identities and the providers still available to link', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user = oauth_controller_user(['password' => 'hashed:secret']);
    oauth_controller_link($user);

    $payload = $controller->identities(oauth_authed($user))->getData(true);

    expect($payload['identities'])->toHaveCount(1)
        ->and($payload['identities'][0]['provider'])->toBe('fakeprovider')
        ->and($payload['identities'][0]['label'])->toBe('Fake Provider')
        ->and($payload['identities'][0]['provider_email'])->toBe('ada@example.com')
        // The stable provider subject is never exposed.
        ->and(json_encode($payload))->not->toContain('subject-1')
        ->and($payload['has_password'])->toBeTrue()
        ->and($payload['available'])->toBe([]);
});

it('offers an enabled provider the user has not linked yet', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $payload = $controller->identities(oauth_authed(oauth_controller_user()))->getData(true);

    expect($payload['identities'])->toBe([])
        ->and($payload['has_password'])->toBeFalse()
        ->and(array_column($payload['available'], 'id'))->toBe(['fakeprovider']);
});

it('starts a link bound to the signed in user', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user     = oauth_controller_user();
    $response = $controller->link(oauth_authed($user, '/', 'POST'), 'fakeprovider');
    $row      = OAuthState::query()->where('purpose', OAuthState::PURPOSE_AUTHORIZATION)->first();

    // Returned, not redirected: this route is behind auth:sanctum and a top-level
    // navigation cannot carry the bearer token.
    expect($response->getData(true)['redirect_url'])->toStartWith('https://provider.test/authorize?')
        ->and($row->intent)->toBe('link')
        ->and($row->user_uuid)->toBe('user-1');
});

it('refuses to link a provider that is already linked', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user = oauth_controller_user();
    oauth_controller_link($user);

    $response = $controller->link(oauth_authed($user, '/', 'POST'), 'fakeprovider');

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('already_linked');
});

it('marks a link handoff so the console completes it through the protected endpoint', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $fragment = oauth_link_callback($controller, oauth_controller_user());

    expect($fragment['intent'])->toBe('link')
        ->and($fragment['handoff'])->toBeString()->toHaveLength(64)
        ->and($fragment['return_to'])->toBe('/account/auth');
});

it('does not link anything at the provider callback', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    oauth_link_callback($controller, oauth_controller_user());

    expect(OAuthIdentity::query()->count())->toBe(0);
});

it('links the identity when the user who started the link completes it', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user     = oauth_controller_user(['password' => 'hashed:secret']);
    $fragment = oauth_link_callback($controller, $user);

    $payload = $controller->completeLink(oauth_authed_complete($user, $fragment['handoff']))->getData(true);

    expect(OAuthIdentity::query()->where('user_uuid', 'user-1')->count())->toBe(1)
        ->and($payload['identities'][0]['provider'])->toBe('fakeprovider')
        ->and($payload['available'])->toBe([]);
});

it('refuses a link completed by a different user than the one who started it', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    // Account-linking CSRF: the attacker starts a link on their own account and gets
    // the victim to complete the provider step. The victim's browser then completes
    // the link as the victim — and must be refused, or the victim's provider identity
    // would be attached to the attacker's account.
    $attacker = oauth_controller_user(['uuid' => 'attacker', 'email' => 'attacker@example.com']);
    $victim   = oauth_controller_user(['uuid' => 'victim', 'email' => 'victim@example.com']);

    $fragment = oauth_link_callback($controller, $attacker);
    $response = $controller->completeLink(oauth_authed_complete($victim, $fragment['handoff']));

    expect($response->getStatusCode())->toBe(400)
        // Indistinguishable from an expired code, so the attacker learns nothing.
        ->and($response->getData(true)['code'])->toBe('invalid_exchange_code')
        ->and(OAuthIdentity::query()->count())->toBe(0);
});

it('refuses to complete a login handoff as a link', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user    = oauth_controller_user();
    $handoff = oauth_controller_handoff($controller);

    $response = $controller->completeLink(oauth_authed_complete($user, $handoff));

    expect($response->getStatusCode())->toBe(400)
        ->and(OAuthIdentity::query()->count())->toBe(0);
});

it('refuses to redeem a link handoff through the public exchange', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $fragment = oauth_link_callback($controller, oauth_controller_user());

    // The public endpoint would skip the signed-in-user check entirely.
    $response = $controller->exchange(oauth_exchange_request(['code' => $fragment['handoff']]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['code'])->toBe('invalid_exchange_code')
        ->and(OAuthIdentity::query()->count())->toBe(0);
});

it('refuses to link a provider account already linked to someone else', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $owner = oauth_controller_user(['uuid' => 'owner', 'email' => 'owner@example.com']);
    oauth_controller_link($owner);

    $other    = oauth_controller_user(['uuid' => 'other', 'email' => 'other@example.com']);
    $fragment = oauth_link_callback($controller, $other);
    $response = $controller->completeLink(oauth_authed_complete($other, $fragment['handoff']));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('identity_already_linked')
        ->and(OAuthIdentity::query()->first()->user_uuid)->toBe('owner');
});

it('unlinks a provider when the user still has another way to sign in', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user = oauth_controller_user(['password' => 'hashed:secret']);
    oauth_controller_link($user);

    $payload = $controller->unlink(oauth_authed($user, '/', 'DELETE'), 'fakeprovider')->getData(true);

    expect(OAuthIdentity::query()->count())->toBe(0)
        ->and($payload['identities'])->toBe([])
        ->and(array_column($payload['available'], 'id'))->toBe(['fakeprovider']);
});

it('refuses to remove the last way a user can sign in', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    // No password and a single linked provider: removing it would lock them out.
    $user = oauth_controller_user(['password' => null]);
    oauth_controller_link($user);

    $response = $controller->unlink(oauth_authed($user, '/', 'DELETE'), 'fakeprovider');

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('last_credential')
        ->and(OAuthIdentity::query()->count())->toBe(1);
});

it('reports unlinking a provider that is not linked', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $response = $controller->unlink(oauth_authed(oauth_controller_user(), '/', 'DELETE'), 'fakeprovider');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true)['code'])->toBe('not_linked');
});

it('rate limits linking per user', function () {
    oauth_controller_database();
    app()->instance(Illuminate\Cache\RateLimiter::class, new OAuthControllerRateLimiterFake(0));
    Facade::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);
    [$controller] = oauth_controller_services();

    $response = $controller->link(oauth_authed(oauth_controller_user(), '/', 'POST'), 'fakeprovider');

    expect($response->getStatusCode())->toBe(429);
});

// ---------------------------------------------------------------------------
// Edge cases across the flow
// ---------------------------------------------------------------------------

/**
 * Loses the race for a provider subject: another request links it between the
 * controller's lookup and this link.
 */
class OAuthControllerRacingIdentityService extends OAuthIdentityService
{
    public function link(User $user, OAuthUserProfile $profile, string $method = Fleetbase\Events\OAuthIdentityLinked::METHOD_MANUAL): OAuthIdentity
    {
        throw new OAuthException('identity_already_linked');
    }
}

function oauth_controller_rate_limited(): void
{
    app()->instance(Illuminate\Cache\RateLimiter::class, new OAuthControllerRateLimiterFake(0));
    Facade::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);
}

it('treats both public oauth requests as open to anyone', function () {
    oauth_controller_database();

    // There is no session yet on either: obtaining one is the point.
    expect((new OAuthRedirectRequest())->authorize())->toBeTrue()
        ->and((new OAuthExchangeRequest())->authorize())->toBeTrue()
        ->and((new OAuthRedirectRequest())->messages())->toBe(['return_to.regex' => 'The return path must be a relative path within the console.']);
});

it('rate limits the redirect endpoint independently of the throttle middleware', function () {
    oauth_controller_database();
    oauth_controller_rate_limited();
    [$controller] = oauth_controller_services();

    $response = $controller->redirect(oauth_redirect_request(), 'fakeprovider');

    expect($response->getStatusCode())->toBe(429)
        ->and(OAuthState::query()->count())->toBe(0);
});

it('reports a callback that carries no authorization code', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    $response = $controller->callback(Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['state' => $query['state']]), 'fakeprovider');

    expect(oauth_fragment($response->getTargetUrl())['error'])->toBe('missing_code');
});

it('refuses a callback for a provider switched off mid flight', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $redirect = $controller->redirect(oauth_redirect_request(), 'fakeprovider');
    parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);

    OAuthControllerFakeDriver::$behaviour = ['enabled' => false, 'throw' => new RuntimeException('no exchange for a disabled provider')];

    $response = $controller->callback(
        Request::create('/int/v1/auth/oauth/fakeprovider/callback', 'GET', ['code' => 'auth-code', 'state' => $query['state']]),
        'fakeprovider'
    );

    expect(oauth_fragment($response->getTargetUrl())['error'])->toBe('provider_disabled');
});

it('refuses a handoff that names no provider subject', function () {
    oauth_controller_database();
    [$controller, $states] = oauth_controller_services();

    $handoff = $states->issue(OAuthState::PURPOSE_HANDOFF, ['profile' => ['provider' => 'fakeprovider', 'email' => 'ada@example.com']], 120, 'fakeprovider');

    $response = $controller->exchange(oauth_exchange_request(['code' => $handoff]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['code'])->toBe('invalid_exchange_code');
});

it('refuses an exchange for a provider removed from the configuration mid flight', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    oauth_controller_link(oauth_controller_user());
    $handoff = oauth_controller_handoff($controller);

    config(['oauth.providers' => []]);

    $response = $controller->exchange(oauth_exchange_request(['code' => $handoff]));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['code'])->toBe('provider_disabled');
});

it('falls back to asking the user to link when an automatic link loses a race', function () {
    oauth_controller_database();
    [, $states, , $config, $flow] = oauth_controller_services();

    $registry   = app(OAuthProviderRegistry::class);
    $controller = new OAuthController($registry, $flow, $states, new OAuthControllerRacingIdentityService(), $config);

    oauth_controller_user(['uuid' => 'user-1', 'email' => 'ada@example.com', 'type' => 'user']);

    $response = $controller->exchange(oauth_exchange_request(['code' => oauth_controller_handoff($controller)]));

    // Nobody is signed in on the strength of a link that did not happen.
    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('link_required')
        ->and(app('db')->connection('mysql')->table('personal_access_tokens')->count())->toBe(0);
});

it('refuses to start linking an unknown or switched off provider', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();
    $user         = oauth_controller_user();

    $unknown = $controller->link(oauth_authed($user, '/', 'POST'), 'nope');

    OAuthControllerFakeDriver::$behaviour = ['enabled' => false];
    $disabled                             = $controller->link(oauth_authed($user, '/', 'POST'), 'fakeprovider');

    expect($unknown->getStatusCode())->toBe(404)
        ->and($unknown->getData(true)['code'])->toBe('unknown_provider')
        ->and($disabled->getStatusCode())->toBe(403)
        ->and($disabled->getData(true)['code'])->toBe('provider_disabled')
        ->and(OAuthState::query()->count())->toBe(0);
});

it('rate limits completing a link per user', function () {
    oauth_controller_database();
    oauth_controller_rate_limited();
    [$controller] = oauth_controller_services();

    $response = $controller->completeLink(oauth_authed_complete(oauth_controller_user(), str_repeat('a', 64)));

    expect($response->getStatusCode())->toBe(429);
});

it('refuses to complete a link with an unknown code', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $response = $controller->completeLink(oauth_authed_complete(oauth_controller_user(), str_repeat('z', 64)));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['code'])->toBe('invalid_exchange_code');
});

it('refuses to complete a link for a provider switched off mid flight', function () {
    oauth_controller_database();
    [$controller] = oauth_controller_services();

    $user     = oauth_controller_user();
    $fragment = oauth_link_callback($controller, $user);

    OAuthControllerFakeDriver::$behaviour = ['enabled' => false];

    $response = $controller->completeLink(oauth_authed_complete($user, $fragment['handoff']));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['code'])->toBe('provider_disabled')
        ->and(OAuthIdentity::query()->count())->toBe(0);
});
