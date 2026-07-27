<?php

use Fleetbase\Attributes\SkipAuthorizationCheck;
use Fleetbase\Expansions\Builder as BuilderExpansion;
use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Requests\SignUpRequest;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\Directive;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Fleetbase\Traits\HasApiModelBehavior;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str as SupportStr;
use Spatie\Permission\PermissionRegistrar;

if (!function_exists('cache')) {
    function cache(mixed $key = null, mixed $default = null): mixed
    {
        $cache = app('cache');

        if ($key === null) {
            return $cache;
        }

        return $cache->get($key, $default);
    }
}

class AuthSupportCacheFake
{
    public array $values = [];

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
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
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
}

class AuthSupportHashFake implements HasherContract
{
    public function check($value, $hashedValue, array $options = []): bool
    {
        return hash_equals('hashed:' . $value, $hashedValue);
    }

    public function make($value, array $options = []): string
    {
        return 'hashed:' . $value;
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        return false;
    }

    public function info($hashedValue): array
    {
        return ['algoName' => 'test'];
    }
}

class AuthSupportResponseCacheFake
{
    public int $clears = 0;

    public function clear(): void
    {
        $this->clears++;
    }
}

class AuthSupportAuthFactoryFake
{
    public mixed $loggedInUser = null;

    public function guard(?string $name = null): self
    {
        return $this;
    }

    public function shouldUse(string $name): void
    {
    }

    public function getDefaultDriver(): string
    {
        return 'sanctum';
    }

    public function user(): mixed
    {
        return null;
    }

    public function login(mixed $user): void
    {
        $this->loggedInUser = $user;
    }
}

class AuthSupportAuthManagerFake extends AuthManager
{
    public function guard($name = null): AuthSupportAuthFactoryFake
    {
        return new AuthSupportAuthFactoryFake();
    }

    public function getDefaultDriver(): string
    {
        return 'sanctum';
    }
}

class AuthSupportPermissionRegistrarFake
{
    public string $pivotRole       = 'role_id';
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';

    public function forgetCachedPermissions(): void
    {
    }

    public function getRoleClass(): string
    {
        return Role::class;
    }

    public function getPermissionClass(): string
    {
        return Permission::class;
    }

    public function getPermissions(array $params = [], bool $onlyOne = false): mixed
    {
        $query = Permission::query();

        foreach ($params as $column => $value) {
            $query->where($column, $value);
        }

        return $onlyOne ? $query->first() : $query->get();
    }
}

class AuthSupportApiCredential extends ApiCredential
{
    public function trackLastUsed()
    {
        $this->last_used_at = now();

        return app('db')->table($this->getTable())->where('uuid', $this->uuid)->update([
            'last_used_at' => $this->last_used_at->toDateTimeString(),
            'updated_at'   => $this->last_used_at->toDateTimeString(),
        ]);
    }
}

class AuthSupportResourceController
{
    public function getService(): string
    {
        return 'iam';
    }

    public function getResourceSingularName(): string
    {
        return 'user';
    }

    public function queryRecord(): void
    {
    }
}

class AuthSupportSkipController
{
    #[SkipAuthorizationCheck]
    public function queryRecord(): void
    {
    }
}

class AuthSupportRouteFake
{
    public function __construct(private string $controllerAction)
    {
    }

    public function getAction(?string $key = null): string|array
    {
        if ($key === 'controller') {
            return $this->controllerAction;
        }

        return ['controller' => $this->controllerAction];
    }

    public function getActionMethod(): string
    {
        return str($this->controllerAction)->after('@')->toString();
    }
}

function auth_support_fixtures(): array
{
    Request::flushMacros();

    if (!function_exists('Fleetbase\\Observers\\event')) {
        eval('namespace Fleetbase\\Observers; function event($event = null) { return $event; }');
    }

    if (!SupportStr::hasMacro('humanize')) {
        SupportStr::macro('humanize', (new StrExpansion())->humanize());
    }

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.timezone'                    => 'UTC',
        'activitylog.default_auth_driver' => 'sanctum',
        'activitylog.default_log_name'    => 'default',
        'auth.defaults.guard'             => 'sanctum',
        'auth.guards.sanctum'             => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
        'auth.providers.users'        => [
            'driver' => 'eloquent',
            'model'  => User::class,
        ],
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connectionConfig,
        'fleetbase.connection.db'                      => 'mysql',
        'permission.models.permission'                 => Permission::class,
        'permission.models.role'                       => Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.column_names.model_morph_key'      => 'model_uuid',
    ]);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    $container->instance('cache', new AuthSupportCacheFake());
    $hash = new AuthSupportHashFake();
    $container->instance('hash', $hash);
    $container->instance(HasherContract::class, $hash);
    $auth = new AuthSupportAuthFactoryFake();
    $container->instance('auth', $auth);
    $container->instance(AuthFactory::class, $auth);
    $container->instance(AuthManager::class, new AuthSupportAuthManagerFake($container));
    $container->instance('responsecache', new AuthSupportResponseCacheFake());
    $container->instance(PermissionRegistrar::class, new AuthSupportPermissionRegistrarFake());
    Facade::clearResolvedInstance('auth');
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('responsecache');

    if (!Request::hasMacro('or')) {
        Request::macro('or', function (array $params = [], mixed $default = null): mixed {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        });
    }

    if (!Request::hasMacro('getController')) {
        Request::macro('getController', fn () => $this->attributes->get('_controller'));
    }

    session()->flush();

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach (['settings', 'activities', 'directives', 'model_has_policies', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'policies', 'permissions', 'api_credentials', 'company_users', 'users', 'companies'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('slug')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->string('password')->nullable();
        $table->string('slug')->nullable();
        $table->string('type')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->string('service')->nullable();
        $table->timestamps();
    });
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('directives', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('permission_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('key')->nullable();
        $table->json('rules')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('activities', function ($table) {
        $table->increments('id');
        $table->string('log_name')->nullable();
        $table->text('description')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->string('event')->nullable();
        $table->text('properties')->nullable();
        $table->string('batch_uuid')->nullable();
        $table->timestamps();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->nullable()->index();
        $table->text('value')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->string('secret')->nullable();
        $table->boolean('test_mode')->default(false);
        $table->string('api')->nullable();
        $table->text('browser_origins')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $now = '2026-07-17 10:00:00';
    app('db')->table('companies')->insert([
        [
            'uuid'       => '22222222-2222-4222-8222-222222222222',
            'public_id'  => 'company_live',
            'name'       => 'Primary Company',
            'email'      => 'primary@example.com',
            'timezone'   => 'Asia/Ulaanbaatar',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'uuid'       => '33333333-3333-4333-8333-333333333333',
            'public_id'  => 'company_fallback',
            'name'       => 'Fallback Company',
            'email'      => 'fallback@example.com',
            'timezone'   => 'Europe/Berlin',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);
    app('db')->table('users')->insert([
        [
            'uuid'         => '11111111-1111-4111-8111-111111111111',
            'company_uuid' => '22222222-2222-4222-8222-222222222222',
            'email'        => 'admin@example.com',
            'phone'        => '+15555550100',
            'username'     => 'admin-user',
            'name'         => 'Admin User',
            'password'     => 'hashed:secret',
            'type'         => 'admin',
            'timezone'     => 'Pacific/Auckland',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => '44444444-4444-4444-8444-444444444444',
            'company_uuid' => null,
            'email'        => 'driver@example.com',
            'phone'        => '+15555550101',
            'username'     => 'driver-user',
            'name'         => 'Driver User',
            'password'     => 'hashed:driver',
            'type'         => 'driver',
            'timezone'     => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);
    app('db')->table('company_users')->insert([
        [
            'uuid'         => '55555555-5555-4555-8555-555555555555',
            'company_uuid' => '33333333-3333-4333-8333-333333333333',
            'user_uuid'    => '44444444-4444-4444-8444-444444444444',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
        [
            'uuid'         => '88888888-8888-4888-8888-888888888888',
            'company_uuid' => '22222222-2222-4222-8222-222222222222',
            'user_uuid'    => '11111111-1111-4111-8111-111111111111',
            'created_at'   => $now,
            'updated_at'   => $now,
        ],
    ]);
    app('db')->table('roles')->insert([
        'id'           => 'role-administrator',
        'company_uuid' => null,
        'name'         => 'Administrator',
        'guard_name'   => 'sanctum',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    app('db')->table('api_credentials')->insert([
        'uuid'            => '66666666-6666-4666-8666-666666666666',
        '_key'            => 'api-key-row',
        'user_uuid'       => '11111111-1111-4111-8111-111111111111',
        'company_uuid'    => '22222222-2222-4222-8222-222222222222',
        'name'            => 'Test API Key',
        'key'             => 'flb_test_key',
        'secret'          => 'hashed-secret',
        'test_mode'       => true,
        'api'             => 'console',
        'browser_origins' => json_encode([]),
        'expires_at'      => Carbon::now()->addHour()->toDateTimeString(),
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    return [
        User::where('uuid', '11111111-1111-4111-8111-111111111111')->first(),
        User::where('uuid', '44444444-4444-4444-8444-444444444444')->first(),
        AuthSupportApiCredential::where('uuid', '66666666-6666-4666-8666-666666666666')->first(),
    ];
}

function auth_support_request(string $method = 'GET', ?string $controllerClass = null): Request
{
    $controllerClass ??= AuthSupportResourceController::class;
    $controller = app($controllerClass);
    $request    = Request::create('/int/v1/users', $method);
    $request->attributes->set('_controller', $controller);
    $request->setRouteResolver(fn () => new AuthSupportRouteFake($controllerClass . '@queryRecord'));

    return $request;
}

afterEach(function () {
    Carbon::setTestNow();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
    session()->flush();
});

test('auth support sets user sessions and checks passwords through the configured hash driver', function () {
    [$admin] = auth_support_fixtures();

    expect(Auth::setSession(null))->toBeFalse()
        ->and(Auth::setSession($admin))->toBeTrue()
        ->and(session('company'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and(session('user'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(session('is_admin'))->toBeTrue()
        ->and(session('is_customer'))->toBeFalse()
        ->and(session('is_driver'))->toBeFalse()
        ->and(Auth::checkPassword('secret', 'hashed:secret'))->toBeTrue()
        ->and(Auth::isInvalidPassword('wrong', 'hashed:secret'))->toBeTrue();

    expect(Auth::setSession($admin, true))->toBeTrue()
        ->and(app('auth')->loggedInUser->uuid)->toBe($admin->uuid);
});

test('auth support registers lowercase owner and organization records and joins the owner', function () {
    auth_support_fixtures();

    $owner = Auth::register([
        'email'    => 'Owner@Example.TEST',
        'name'     => 'Owner User',
        'password' => 'hashed:owner',
    ], [
        'email' => 'Company@Example.TEST',
        'name'  => 'Registered Company',
    ]);

    $company = Company::where('owner_uuid', $owner->uuid)->first();

    expect($owner)->toBeInstanceOf(User::class)
        ->and($owner->email)->toBe('owner@example.test')
        ->and($company)->toBeInstanceOf(Company::class)
        ->and($company->name)->toBe('Registered Company')
        ->and(app('db')->table('company_users')->where('user_uuid', $owner->uuid)->where('company_uuid', $company->uuid)->exists())->toBeTrue();
});

test('auth controller sign up registers an owner and organization then returns an access token', function () {
    auth_support_fixtures();

    // createToken persists to personal_access_tokens; the shared fixture omits it.
    app('db')->connection('mysql')->getSchemaBuilder()->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    $request = SignUpRequest::create('/int/v1/auth/sign-up', 'POST', [
        'user' => [
            'email'    => 'Founder@Example.TEST',
            'name'     => 'Founder User',
            'password' => 'hashed:founder',
        ],
        'company' => [
            'email' => 'HQ@Example.TEST',
            'name'  => 'Founder Company',
        ],
    ]);

    $response = (new AuthController())->signUp($request);
    $payload  = $response->getData(true);

    $owner   = User::where('email', 'founder@example.test')->first();
    $company = Company::where('owner_uuid', $owner?->uuid)->first();

    expect($payload['token'])->toBeString()
        ->and($payload['token'])->not->toBeEmpty()
        ->and($owner)->toBeInstanceOf(User::class)
        ->and($company)->toBeInstanceOf(Company::class)
        ->and($company->name)->toBe('Founder Company')
        ->and($owner->company_uuid)->toBe($company->uuid)
        ->and(app('db')->table('company_users')->where('user_uuid', $owner->uuid)->where('company_uuid', $company->uuid)->exists())->toBeTrue()
        ->and(app('db')->table('personal_access_tokens')->where('tokenable_id', $owner->uuid)->count())->toBe(1);
});

test('auth support stores api credential session context and tracks key usage', function () {
    [, , $credential] = auth_support_fixtures();
    Carbon::setTestNow(Carbon::parse('2026-07-17 11:30:00'));

    expect(Auth::setSession($credential))->toBeTrue()
        ->and(session('company'))->toBe('22222222-2222-4222-8222-222222222222')
        ->and(session('user'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(session('is_admin'))->toBeTrue();

    $credential->refresh();
    expect($credential->last_used_at->toISOString())->toBe('2026-07-17T11:30:00.000000Z');

    expect(Auth::setApiKey($credential))->toBeTrue()
        ->and(session('api_credential'))->toBe($credential->uuid)
        ->and(session('api_key'))->toBe('flb_test_key')
        ->and(session('api_secret'))->toBe('hashed-secret')
        ->and(session('api_environment'))->toBe('test')
        ->and(session('api_test_mode'))->toBeTrue()
        ->and(Auth::getApiKey()->uuid)->toBe($credential->uuid);
});

test('auth support returns null when no api credential session exists', function () {
    auth_support_fixtures();

    expect(Auth::getApiKey())->toBeNull();
});

test('auth support applies sandbox session from headers or api credential fallback', function () {
    [, , $credential] = auth_support_fixtures();

    $headerRequest = Request::create('/v1/orders', 'GET', [], [], [], [
        'HTTP_ACCESS_CONSOLE_SANDBOX'     => '1',
        'HTTP_ACCESS_CONSOLE_SANDBOX_KEY' => 'header-key',
    ]);

    expect(Auth::setSandboxSession($headerRequest))->toBeTrue()
        ->and(config('database.default'))->toBe('sandbox')
        ->and(config('fleetbase.connection.db'))->toBe('sandbox')
        ->and(session('is_sandbox'))->toBeTrue()
        ->and(session('sandbox_api_credential'))->toBe('header-key');

    session()->flush();
    config(['database.default' => 'mysql', 'fleetbase.connection.db' => 'mysql']);
    expect(Auth::setSandboxSession(Request::create('/v1/orders'), $credential))->toBeTrue()
        ->and(config('database.default'))->toBe('sandbox')
        ->and(session('sandbox_api_credential'))->toBe($credential->uuid);
});

test('auth support resolves companies from session request params and user membership fallback', function () {
    [$admin, $driver] = auth_support_fixtures();

    session(['company' => '22222222-2222-4222-8222-222222222222']);
    expect(Auth::getCompany(['uuid', 'name'])->name)->toBe('Primary Company');

    session()->flush();
    app()->instance('request', Request::create('/test', 'GET', ['company' => 'company_fallback']));
    expect(Auth::getCompany()->uuid)->toBe('33333333-3333-4333-8333-333333333333');

    expect(Auth::getCompanySessionForUser($admin)->uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and(Auth::getCompanySessionForUser($driver)->uuid)->toBe('33333333-3333-4333-8333-333333333333');
});

test('auth support resolves companies directly from request ids and public ids', function () {
    auth_support_fixtures();

    $uuidRequest   = Request::create('/int/v1/test', 'GET', ['company_uuid' => '22222222-2222-4222-8222-222222222222']);
    $publicRequest = Request::create('/int/v1/test', 'GET', ['company' => 'company_fallback']);

    expect(Auth::getCompanyFromRequest($uuidRequest)->name)->toBe('Primary Company')
        ->and(Auth::getCompanyFromRequest($publicRequest)->uuid)->toBe('33333333-3333-4333-8333-333333333333');
});

test('auth support resolves request permissions and wildcard user permission checks', function () {
    [$admin] = auth_support_fixtures();
    session(['user' => $admin->uuid]);

    app('db')->table('permissions')->insert([
        ['id' => 'permission-list-user', 'name' => 'iam list user', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 'permission-wildcard-user', 'name' => 'iam * user', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 'permission-wildcard-service', 'name' => 'iam *', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 'permission-unrelated', 'name' => 'fleetops view order', 'guard_name' => 'sanctum', 'service' => 'fleetops', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app('db')->table('model_has_permissions')->insert([
        'permission_id' => 'permission-wildcard-user',
        'model_type'    => Fleetbase\Models\CompanyUser::class,
        'model_uuid'    => '88888888-8888-4888-8888-888888888888',
    ]);

    $request     = auth_support_request();
    $permissions = Auth::resolvePermissionsFromRequest($request);

    expect(Auth::getRequiredPermissionNameFromRequest($request))->toBe('list user')
        ->and(Auth::isResourceGuarded('user'))->toBeTrue()
        ->and(Auth::isResourceGuarded('dashboard'))->toBeFalse()
        ->and($permissions->pluck('id')->all())->toBe([
            'permission-list-user',
            'permission-wildcard-user',
            'permission-wildcard-service',
        ])
        ->and(Auth::can('iam update user'))->toBeTrue()
        ->and(Auth::cannot('fleetops view order'))->toBeTrue();

    expect(Auth::resolvePermissionsFromRequest(auth_support_request('GET', AuthSupportSkipController::class))->isEmpty())->toBeTrue();
});

test('auth support filters and applies directives for assigned role and policy subjects', function () {
    [$admin] = auth_support_fixtures();
    session(['user' => $admin->uuid]);

    app('db')->table('permissions')->insert([
        ['id' => 'permission-list-user', 'name' => 'iam list user', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app('db')->table('roles')->insert([
        ['id' => 'role-assigned', 'company_uuid' => $admin->company_uuid, 'name' => 'Assigned Role', 'guard_name' => 'sanctum', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 'role-missing', 'company_uuid' => $admin->company_uuid, 'name' => 'Missing Role', 'guard_name' => 'sanctum', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app('db')->table('policies')->insert([
        ['id' => 'policy-assigned', 'company_uuid' => $admin->company_uuid, 'name' => 'Assigned Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    app('db')->table('model_has_roles')->insert([
        'role_id'    => 'role-assigned',
        'model_type' => Fleetbase\Models\CompanyUser::class,
        'model_uuid' => '88888888-8888-4888-8888-888888888888',
    ]);
    app('db')->table('model_has_policies')->insert([
        'policy_id'  => 'policy-assigned',
        'model_type' => Fleetbase\Models\CompanyUser::class,
        'model_uuid' => '88888888-8888-4888-8888-888888888888',
    ]);
    app('db')->table('directives')->insert([
        [
            'uuid'            => 'directive-assigned',
            'company_uuid'    => $admin->company_uuid,
            'permission_uuid' => 'permission-list-user',
            'subject_type'    => Role::class,
            'subject_uuid'    => 'role-assigned',
            'key'             => 'assigned',
            'rules'           => json_encode(['where', 'type', '=', 'admin']),
            'created_at'      => now(),
            'updated_at'      => now(),
        ],
        [
            'uuid'            => 'directive-policy',
            'company_uuid'    => $admin->company_uuid,
            'permission_uuid' => 'permission-list-user',
            'subject_type'    => Policy::class,
            'subject_uuid'    => 'policy-assigned',
            'key'             => 'policy',
            'rules'           => json_encode(['where', 'type', '=', 'admin']),
            'created_at'      => now(),
            'updated_at'      => now(),
        ],
        [
            'uuid'            => 'directive-unassigned',
            'company_uuid'    => $admin->company_uuid,
            'permission_uuid' => 'permission-list-user',
            'subject_type'    => Role::class,
            'subject_uuid'    => 'role-missing',
            'key'             => 'missing',
            'rules'           => json_encode(['where', 'type', '=', 'driver']),
            'created_at'      => now(),
            'updated_at'      => now(),
        ],
        [
            'uuid'            => 'directive-unknown-subject',
            'company_uuid'    => $admin->company_uuid,
            'permission_uuid' => 'permission-list-user',
            'subject_type'    => User::class,
            'subject_uuid'    => $admin->uuid,
            'key'             => 'unknown-subject',
            'rules'           => json_encode(['where', 'type', '=', 'driver']),
            'created_at'      => now(),
            'updated_at'      => now(),
        ],
    ]);

    $directives = Auth::getDirectivesForPermissions(['iam list user']);
    $query      = User::query();
    Auth::applyDirectivesToQuery($query, auth_support_request());

    expect($directives->pluck('uuid')->values()->all())->toBe(['directive-assigned', 'directive-policy'])
        ->and($query->toSql())->toContain('"type" = ?')
        ->and($query->getBindings())->toBe(['admin', 'admin'])
        ->and(Directive::decodeKey(Directive::createKey(['where', 'type', 'admin'])))->toBe(['where', 'type', 'admin']);
});

test('builder permission directive macro applies eligible directive rules to the builder', function () {
    [$admin] = auth_support_fixtures();
    session(['user' => $admin->uuid]);

    EloquentBuilder::macro('applyDirectivesForPermissions', (new BuilderExpansion())->applyDirectivesForPermissions());

    app('db')->table('permissions')->insert([
        ['id' => 'permission-list-user', 'name' => 'iam list user', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app('db')->table('roles')->insert([
        ['id' => 'role-builder-directive', 'company_uuid' => $admin->company_uuid, 'name' => 'Builder Directive', 'guard_name' => 'sanctum', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app('db')->table('model_has_roles')->insert([
        'role_id'    => 'role-builder-directive',
        'model_type' => Fleetbase\Models\CompanyUser::class,
        'model_uuid' => '88888888-8888-4888-8888-888888888888',
    ]);
    app('db')->table('directives')->insert([
        'uuid'            => 'directive-builder',
        'company_uuid'    => $admin->company_uuid,
        'permission_uuid' => 'permission-list-user',
        'subject_type'    => Role::class,
        'subject_uuid'    => 'role-builder-directive',
        'key'             => 'builder',
        'rules'           => json_encode(['where', 'type', '=', 'admin']),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $query      = User::query()->applyDirectivesForPermissions('iam list user');
    $traitQuery = User::query();
    (new class {
        use HasApiModelBehavior;
    })->applyDirectivesToQuery(auth_support_request(), $traitQuery);

    expect($query->toSql())->toContain('"type" = ?')
        ->and($query->getBindings())->toBe(['admin'])
        ->and($query->pluck('uuid')->all())->toBe(['11111111-1111-4111-8111-111111111111'])
        ->and($traitQuery->toSql())->toContain('"type" = ?')
        ->and($traitQuery->getBindings())->toBe(['admin']);
});

test('auth support resolves user timezone before company and app fallbacks', function () {
    [$admin, $driver] = auth_support_fixtures();

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $admin);
    expect(Auth::getUserTimezone($request))->toBe('Pacific/Auckland');

    $requestWithoutUserTimezone = Request::create('/test');
    $requestWithoutUserTimezone->setUserResolver(fn () => $driver);
    expect(Auth::getUserTimezone($requestWithoutUserTimezone))->toBe('Europe/Berlin');

    $missingCompany = new User([
        'uuid'         => '77777777-7777-4777-8777-777777777777',
        'timezone'     => null,
        'company_uuid' => null,
    ]);
    $fallbackRequest = Request::create('/test');
    $fallbackRequest->setUserResolver(fn () => $missingCompany);

    expect(Auth::getUserTimezone($fallbackRequest))->toBe('UTC');
});
