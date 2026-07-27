<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Http\Requests\ChangePasswordRequest;
use Fleetbase\Http\Requests\JoinOrganizationRequest;
use Fleetbase\Http\Requests\LoginRequest;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str as SupportStr;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return getcwd() . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('Fleetbase\\Http\\Controllers\\Internal\\v1\\event')) {
    eval('namespace Fleetbase\\Http\\Controllers\\Internal\\v1; function event($event = null) { return $event; }');
}

class AuthControllerLoginBootstrapCacheFake
{
    public array $rememberCalls = [];

    public array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        $this->rememberCalls[] = [$key, $ttl];

        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
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

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class AuthControllerLoginBootstrapHashFake
{
    public function make(mixed $value, array $options = []): string
    {
        return password_hash((string) $value, PASSWORD_BCRYPT);
    }

    public function check(mixed $value, string $hashedValue, array $options = []): bool
    {
        return password_verify((string) $value, $hashedValue);
    }
}

class AuthControllerLoginBootstrapResponseCacheFake
{
    public function clear(array $tags = []): bool
    {
        return true;
    }
}

class AuthControllerLoginBootstrapMailFake
{
    public array $sent       = [];
    private mixed $recipient = null;

    public function to(mixed $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function send(mixed $mailable): void
    {
        $this->sent[] = [$this->recipient, $mailable::class];
    }
}

class AuthControllerLoginBootstrapRedisFake
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

/**
 * Minimal stand-in for the resolved 'auth' guard so controller calls to the
 * Auth facade (Auth::validate / Auth::login) work without a real auth manager.
 */
class AuthControllerLoginBootstrapAuthGuardFake
{
    public array $loggedIn      = [];
    public bool $validateResult = true;

    public function validate(array $credentials = []): bool
    {
        return $this->validateResult;
    }

    public function login($user, bool $remember = false): void
    {
        $this->loggedIn[] = $user;
    }

    public function user()
    {
        return $this->loggedIn[count($this->loggedIn) - 1] ?? null;
    }
}

class AuthControllerLoginBootstrapPermissionRegistrarFake
{
    public string $pivotRole       = 'role_id';
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';

    public function getRoleClass(): string
    {
        return Fleetbase\Models\Role::class;
    }

    public function getPermissionClass(): string
    {
        return Fleetbase\Models\Permission::class;
    }
}

class AuthControllerLoginBootstrapUserSpy extends User
{
    public function hasRole($roles, ?string $guard = null): bool
    {
        return false;
    }

    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        return false;
    }
}

function auth_controller_login_bootstrap_database(): Capsule
{
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();

    if (!SupportStr::hasMacro('humanize')) {
        SupportStr::macro('humanize', (new Fleetbase\Expansions\Str())->humanize());
    }

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.env'                                      => 'testing',
        'app.timezone'                                 => 'UTC',
        'app.key'                                      => 'base64:' . base64_encode(str_repeat('a', 32)),
        'auth.defaults.guard'                          => 'sanctum',
        'auth.guards.sanctum.provider'                 => 'users',
        'auth.providers.users.model'                   => User::class,
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'activitylog.enabled'                          => false,
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.column_names.model_morph_key'      => 'model_uuid',
    ]);
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));

    $cache = new AuthControllerLoginBootstrapCacheFake();
    $container->instance('cache', $cache);
    $container->instance('hash', new AuthControllerLoginBootstrapHashFake());
    $container->instance('redis', new AuthControllerLoginBootstrapRedisFake());
    $container->instance('responsecache', new AuthControllerLoginBootstrapResponseCacheFake());
    $container->instance(Spatie\Permission\PermissionRegistrar::class, new AuthControllerLoginBootstrapPermissionRegistrarFake());
    Cache::swap($cache);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('redis');
    Facade::clearResolvedInstance('responsecache');

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
        $table->string('phone')->nullable()->index();
        $table->string('password')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
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
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('phone')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->text('options')->nullable();
        $table->string('currency')->nullable();
        $table->string('country')->nullable();
        $table->string('timezone')->nullable();
        $table->string('plan')->nullable();
        $table->timestamp('trial_ends_at')->nullable();
        $table->string('status')->nullable();
        $table->string('type')->nullable();
        $table->string('slug')->nullable();
        $table->timestamp('onboarding_completed_at')->nullable();
        $table->string('onboarding_completed_by_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('user_uuid');
        $table->string('company_uuid');
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type');
        $table->string('model_uuid');
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
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->string('service')->nullable();
        $table->text('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('invites', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('protocol')->nullable();
        $table->string('reason')->nullable();
        $table->json('recipients')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    session()->flush();

    return $capsule;
}

function auth_controller_login_insert_user(Capsule $capsule, array $attributes = []): void
{
    $capsule->getConnection('mysql')->table('users')->insert(array_merge([
        'uuid'              => '11111111-1111-4111-8111-111111111111',
        'company_uuid'      => 'company-1',
        'name'              => 'Auth User',
        'email'             => 'auth@example.test',
        'phone'             => '+15555550123',
        'password'          => password_hash('correct-password', PASSWORD_BCRYPT),
        'type'              => 'admin',
        'status'            => 'active',
        'email_verified_at' => '2026-07-18 10:00:00',
        'phone_verified_at' => null,
        'last_login'        => null,
        'deleted_at'        => null,
        'created_at'        => '2026-07-18 10:00:00',
        'updated_at'        => '2026-07-18 10:00:00',
    ], $attributes));
}

function auth_controller_login_request(array $input): LoginRequest
{
    return LoginRequest::create('/int/v1/auth/login', 'POST', $input);
}

function auth_controller_bootstrap_request(User $user, string $token = 'bootstrap-token'): Request
{
    $request = Request::create('/int/v1/auth/bootstrap', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    ]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function auth_controller_authenticated_request(string $method, array $input, User $user, string $uri = '/int/v1/auth', ?string $requestClass = null): Request
{
    $requestClass ??= Request::class;
    $request = $requestClass::create($uri, $method, $input);
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    session(['user' => $user->uuid, 'company' => $user->company_uuid]);

    return $request;
}

function auth_controller_join_organization_request(User $user, string $publicId): JoinOrganizationRequest
{
    /** @var JoinOrganizationRequest $request */
    $request = auth_controller_authenticated_request(
        'POST',
        ['next' => $publicId],
        $user,
        '/int/v1/organizations/join',
        JoinOrganizationRequest::class
    );

    return $request;
}

function auth_controller_insert_company(Capsule $capsule, array $attributes = []): void
{
    $capsule->getConnection('mysql')->table('companies')->insert(array_merge([
        'uuid'                    => 'company-join',
        'public_id'               => 'company_join_public',
        'owner_uuid'              => 'owner-user',
        'name'                    => 'Joinable Company',
        'description'             => 'Accepts invited users',
        'phone'                   => '+15555550999',
        'logo_uuid'               => null,
        'backdrop_uuid'           => null,
        'options'                 => null,
        'currency'                => 'USD',
        'country'                 => 'US',
        'timezone'                => 'UTC',
        'plan'                    => 'starter',
        'trial_ends_at'           => null,
        'status'                  => 'active',
        'type'                    => 'business',
        'slug'                    => 'joinable-company',
        'onboarding_completed_at' => null,
        'created_at'              => '2026-07-18 10:00:00',
        'updated_at'              => '2026-07-18 10:00:00',
        'deleted_at'              => null,
    ], $attributes));
}

function auth_controller_insert_administrator_role(Capsule $capsule, ?string $companyUuid = null): void
{
    $capsule->getConnection('mysql')->table('roles')->insert([
        'id'           => 'role-' . ($companyUuid ?: 'global') . '-administrator',
        'company_uuid' => $companyUuid,
        'name'         => 'Administrator',
        'guard_name'   => 'sanctum',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
        'deleted_at'   => null,
    ]);
}

function auth_controller_insert_join_invite(Capsule $capsule, string $companyUuid, string $email): void
{
    $capsule->getConnection('mysql')->table('invites')->insert([
        'uuid'            => 'invite-' . $companyUuid,
        'company_uuid'    => $companyUuid,
        'subject_uuid'    => $companyUuid,
        'subject_type'    => Fleetbase\Models\Company::class,
        'created_by_uuid' => 'owner-user',
        'protocol'        => 'email',
        'reason'          => 'join_company',
        'recipients'      => json_encode([$email]),
        'expires_at'      => Carbon::now()->addDay()->toDateTimeString(),
        'deleted_at'      => null,
        'created_at'      => '2026-07-18 10:00:00',
        'updated_at'      => '2026-07-18 10:00:00',
    ]);
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('login rejects customer identities before password authentication', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'type' => 'customer',
    ]);

    $response = (new AuthController())->login(auth_controller_login_request([
        'identity' => 'auth@example.test',
        'password' => 'correct-password',
    ]));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'errors' => ['Customer accounts must sign in through the customer portal.'],
            'code'   => 'customer_login_not_allowed',
        ])
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(0);
});

test('login returns the generic credentials response for unknown wrong or passwordless identities', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'password' => null,
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'     => '22222222-2222-4222-8222-222222222222',
        'email'    => 'password@example.test',
        'phone'    => '+15555550124',
        'password' => password_hash('correct-password', PASSWORD_BCRYPT),
    ]);

    $passwordless = (new AuthController())->login(auth_controller_login_request([
        'identity' => 'auth@example.test',
        'password' => 'anything',
    ]));
    $wrongPassword = (new AuthController())->login(auth_controller_login_request([
        'identity' => 'password@example.test',
        'password' => 'wrong-password',
    ]));
    $unknown = (new AuthController())->login(auth_controller_login_request([
        'identity' => 'missing@example.test',
        'password' => 'correct-password',
    ]));

    foreach ([$passwordless, $wrongPassword, $unknown] as $response) {
        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe([
                'errors' => ['These credentials do not match our records.'],
                'code'   => 'invalid_credentials',
            ]);
    }
});

test('login rejects unverified non admin users after valid credentials', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'type'              => 'dispatcher',
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    $response = (new AuthController())->login(auth_controller_login_request([
        'identity' => '+15555550123',
        'password' => 'correct-password',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['User is not verified.'],
            'code'   => 'not_verified',
        ])
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(0);
});

test('login creates a sanctum token and updates last login for verified users', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 13:45:00', 'UTC'));

    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'type'              => 'dispatcher',
        'email_verified_at' => '2026-07-18 10:00:00',
    ]);

    $response = (new AuthController())->login(auth_controller_login_request([
        'identity' => 'auth@example.test',
        'password' => 'correct-password',
    ]));
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['type'])->toBe('dispatcher')
        ->and($payload['token'])->toContain('|')
        ->and(User::find('11111111-1111-4111-8111-111111111111')->last_login->toDateTimeString())->toBe('2026-07-18 13:45:00')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});

test('login reuses a matching existing auth token without issuing a new token', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule);

    $user      = User::find('11111111-1111-4111-8111-111111111111');
    $authToken = $user->createToken($user->uuid)->plainTextToken;

    $response = (new AuthController())->login(auth_controller_login_request([
        'identity'  => 'auth@example.test',
        'password'  => 'unused-password',
        'authToken' => $authToken,
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'token' => $authToken,
            'type'  => 'admin',
        ])
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});

test('login rejects matching customer auth tokens before reusing existing sessions', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'type' => 'customer',
    ]);

    $user      = User::find('11111111-1111-4111-8111-111111111111');
    $authToken = $user->createToken($user->uuid)->plainTextToken;

    $response = (new AuthController())->login(auth_controller_login_request([
        'identity'  => 'auth@example.test',
        'password'  => 'unused-password',
        'authToken' => $authToken,
    ]));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'errors' => ['Customer accounts must sign in through the customer portal.'],
            'code'   => 'customer_login_not_allowed',
        ])
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});

test('login does not honor an auth token whose owner does not match the claimed identity (token-swap)', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule);

    // A valid token belonging to auth@example.test.
    $user      = User::find('11111111-1111-4111-8111-111111111111');
    $authToken = $user->createToken($user->uuid)->plainTextToken;

    // The attacker presents that valid token but claims a DIFFERENT identity. The token
    // owner's email/phone does not match the claimed identity, so the token must be ignored
    // and the request must fall through to (failing) password authentication.
    $response = (new AuthController())->login(auth_controller_login_request([
        'identity'  => 'someone-else@example.test',
        'password'  => 'not-the-password',
        'authToken' => $authToken,
    ]));

    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(401)
        ->and($payload['code'])->toBe('invalid_credentials')
        // The victim's token was NOT returned, and no new token was issued.
        ->and($payload)->not->toHaveKey('token')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});

test('login starts two factor authentication without issuing a sanctum token when enabled', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'type'              => 'dispatcher',
        'email_verified_at' => '2026-07-18 10:00:00',
    ]);
    $capsule->getConnection('mysql')->table('settings')->insert([
        'key'   => 'user.11111111-1111-4111-8111-111111111111.2fa',
        'value' => json_encode(['enabled' => true, 'method' => 'email']),
    ]);

    $response = (new AuthController())->login(auth_controller_login_request([
        'identity' => 'auth@example.test',
        'password' => 'correct-password',
    ]));
    $payload = $response->getData(true);
    $redis   = app('redis');

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['isEnabled'])->toBeTrue()
        ->and($payload['twoFaSession'])->toBeString()
        ->and($payload['twoFaSession'])->not->toContain('two_fa_session')
        ->and($redis->sets)->toHaveCount(1)
        ->and($redis->sets[0]['key'])->toStartWith('two_fa_session:11111111-1111-4111-8111-111111111111:')
        ->and($redis->sets[0]['value'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(0);
});

test('bootstrap returns cached session and organization response contracts', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'owner-user',
        'email' => 'owner@example.test',
        'name'  => 'Owner User',
    ]);
    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'                    => 'company-1',
        'public_id'               => 'company_public',
        'owner_uuid'              => 'owner-user',
        'name'                    => 'Example Company',
        'description'             => 'Primary account',
        'phone'                   => '+15555550111',
        'logo_uuid'               => null,
        'backdrop_uuid'           => null,
        'options'                 => json_encode(['onboarded' => true]),
        'currency'                => 'USD',
        'country'                 => 'US',
        'timezone'                => 'UTC',
        'plan'                    => 'starter',
        'trial_ends_at'           => null,
        'status'                  => 'active',
        'type'                    => 'business',
        'slug'                    => 'example-company',
        'onboarding_completed_at' => '2026-07-18 10:00:00',
        'created_at'              => '2026-07-18 10:00:00',
        'updated_at'              => '2026-07-18 11:00:00',
        'deleted_at'              => null,
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        'uuid'         => 'company-user-1',
        'user_uuid'    => 'owner-user',
        'company_uuid' => 'company-1',
        'status'       => 'active',
        'external'     => false,
        'deleted_at'   => null,
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);

    $user = User::find('owner-user');
    session(['impersonator' => 'admin-user']);

    $response = (new AuthController())->bootstrap(auth_controller_bootstrap_request($user, 'bootstrap-token'));
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and($payload['session'])->toMatchArray([
            'token'        => 'bootstrap-token',
            'user'         => 'owner-user',
            'verified'     => true,
            'type'         => 'admin',
            'impersonator' => 'admin-user',
        ])
        ->and($payload['organizations'])->toHaveCount(1)
        ->and($payload['organizations'][0]['name'])->toBe('Example Company')
        ->and($payload['organizations'][0]['owner']['name'])->toBe('Owner User')
        ->and($payload['organizations'][0]['owner']['email'])->toBe('owner@example.test')
        ->and($payload['organizations'][0]['branding'])->toHaveKeys(['id', 'uuid', 'default_theme']);
});

test('get user organizations returns active membership organizations with cache validators', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'member-user',
        'email' => 'member@example.test',
        'name'  => 'Member User',
        'type'  => 'user',
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'owner-user',
        'email' => 'owner@example.test',
        'name'  => 'Owner User',
    ]);
    $capsule->getConnection('mysql')->table('companies')->insert([
        [
            'id'                      => 1,
            'uuid'                    => 'company-1',
            'public_id'               => 'company_public_1',
            'owner_uuid'              => 'owner-user',
            'name'                    => 'Visible Company',
            'description'             => 'Visible membership',
            'phone'                   => '+15555550111',
            'logo_uuid'               => null,
            'backdrop_uuid'           => null,
            'options'                 => json_encode(['region' => 'west']),
            'currency'                => 'USD',
            'country'                 => 'US',
            'timezone'                => 'UTC',
            'plan'                    => 'starter',
            'trial_ends_at'           => null,
            'status'                  => 'active',
            'type'                    => 'business',
            'slug'                    => 'visible-company',
            'onboarding_completed_at' => '2026-07-18 10:00:00',
            'created_at'              => '2026-07-18 10:00:00',
            'updated_at'              => '2026-07-18 11:00:00',
            'deleted_at'              => null,
        ],
        [
            'id'                      => 2,
            'uuid'                    => 'company-2',
            'public_id'               => 'company_public_2',
            'owner_uuid'              => null,
            'name'                    => 'Installer Draft',
            'description'             => null,
            'phone'                   => null,
            'logo_uuid'               => null,
            'backdrop_uuid'           => null,
            'options'                 => null,
            'currency'                => null,
            'country'                 => null,
            'timezone'                => null,
            'plan'                    => null,
            'trial_ends_at'           => null,
            'status'                  => 'pending',
            'type'                    => null,
            'slug'                    => 'installer-draft',
            'onboarding_completed_at' => null,
            'created_at'              => '2026-07-18 10:00:00',
            'updated_at'              => '2026-07-18 11:00:00',
            'deleted_at'              => null,
        ],
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['uuid' => 'membership-visible', 'user_uuid' => 'member-user', 'company_uuid' => 'company-1', 'status' => 'active', 'external' => false, 'deleted_at' => null, 'created_at' => '2026-07-18 09:00:00', 'updated_at' => '2026-07-18 09:00:00'],
        ['uuid' => 'membership-draft', 'user_uuid' => 'member-user', 'company_uuid' => 'company-2', 'status' => 'active', 'external' => false, 'deleted_at' => null, 'created_at' => '2026-07-18 09:00:00', 'updated_at' => '2026-07-18 09:00:00'],
    ]);

    $response = (new AuthController())->getUserOrganizations(auth_controller_authenticated_request('GET', [], User::find('member-user'), '/int/v1/auth/organizations'));
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getEtag())->not->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($payload['data'])->toHaveCount(1)
        ->and($payload['data'][0]['uuid'])->toBe('company-1')
        ->and($payload['data'][0]['name'])->toBe('Visible Company')
        ->and($payload['data'][0]['owner'])->toBe([
            'uuid'  => 'owner-user',
            'name'  => 'Owner User',
            'email' => 'owner@example.test',
        ])
        ->and($payload['data'][0]['users_count'])->toBe(1)
        ->and($payload['data'][0]['onboarding_completed'])->toBeTrue();
});

test('clear user organizations cache forgets legacy and current organization cache keys', function () {
    auth_controller_login_bootstrap_database();

    $cache = app('cache');
    $cache->put('user_organizations_member-user', ['legacy' => true]);
    $cache->put('user_organizations_v2_member-user', ['current' => true]);
    $cache->put('unrelated-member-user', ['keep' => true]);

    AuthController::clearUserOrganizationsCache('member-user');

    expect($cache->get('user_organizations_member-user'))->toBeNull()
        ->and($cache->get('user_organizations_v2_member-user'))->toBeNull()
        ->and($cache->get('unrelated-member-user'))->toBe(['keep' => true]);
});

test('join organization requires an invite before modifying membership or session', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'         => 'joining-user',
        'email'        => 'joining@example.test',
        'company_uuid' => 'company-current',
        'type'         => 'admin',
    ]);
    auth_controller_insert_company($capsule, [
        'uuid'      => 'company-join',
        'public_id' => 'company_join_public',
    ]);

    $response = (new AuthController())->joinOrganization(
        auth_controller_join_organization_request(User::find('joining-user'), 'company_join_public')
    );

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['User has not been invited to join this organization.'],
        ])
        ->and($capsule->getConnection('mysql')->table('company_users')->where('user_uuid', 'joining-user')->count())->toBe(0)
        ->and(session('company'))->toBe('company-current');
});

test('join organization rejects current organization invites and accepts valid invited memberships', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_insert_administrator_role($capsule, 'company-current');
    auth_controller_insert_administrator_role($capsule, 'company-join');
    auth_controller_login_insert_user($capsule, [
        'uuid'         => 'joining-user',
        'email'        => 'joining@example.test',
        'company_uuid' => 'company-current',
        'type'         => 'admin',
    ]);
    auth_controller_insert_company($capsule, [
        'uuid'      => 'company-current',
        'public_id' => 'company_current_public',
    ]);
    auth_controller_insert_company($capsule, [
        'uuid'      => 'company-join',
        'public_id' => 'company_join_public',
    ]);
    auth_controller_insert_join_invite($capsule, 'company-current', 'joining@example.test');
    auth_controller_insert_join_invite($capsule, 'company-join', 'joining@example.test');

    $alreadyMember = (new AuthController())->joinOrganization(
        auth_controller_join_organization_request(User::find('joining-user'), 'company_current_public')
    );
    $joined = (new AuthController())->joinOrganization(
        auth_controller_join_organization_request(User::find('joining-user'), 'company_join_public')
    );

    $membership = $capsule->getConnection('mysql')->table('company_users')
        ->where('user_uuid', 'joining-user')
        ->where('company_uuid', 'company-join')
        ->first();

    expect($alreadyMember->getStatusCode())->toBe(400)
        ->and($alreadyMember->getData(true))->toBe([
            'errors' => ['User is already a member of this organization.'],
        ])
        ->and($joined->getStatusCode())->toBe(200)
        ->and($joined->getData(true))->toBe(['status' => 'ok'])
        ->and($membership)->not->toBeNull()
        ->and($membership->status)->toBe('active')
        ->and(User::find('joining-user')->company_uuid)->toBe('company-join')
        ->and(session('company'))->toBe('company-join');
});

test('create organization assigns owner membership role onboarding flags and returns organization resource', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 16:00:00', 'UTC'));
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_insert_administrator_role($capsule, 'company-created');
    auth_controller_login_insert_user($capsule, [
        'uuid'         => 'creator-user',
        'email'        => 'creator@example.test',
        'company_uuid' => 'company-origin',
        'type'         => 'admin',
    ]);

    $resource = (new AuthController())->createOrganization(auth_controller_authenticated_request('POST', [
        'uuid'        => 'company-created',
        'name'        => 'Created Company',
        'description' => 'Created through auth controller',
        'phone'       => '+15555550000',
        'email'       => 'created@example.test',
        'currency'    => 'USD',
        'country'     => 'US',
        'timezone'    => 'UTC',
    ], User::find('creator-user'), '/int/v1/organizations'));

    $company    = $capsule->getConnection('mysql')->table('companies')->where('name', 'Created Company')->first();
    $membership = $capsule->getConnection('mysql')->table('company_users')
        ->where('user_uuid', 'creator-user')
        ->where('company_uuid', $company->uuid)
        ->first();

    expect($resource)->toBeInstanceOf(Fleetbase\Http\Resources\Organization::class)
        ->and($company->owner_uuid)->toBe('creator-user')
        ->and($company->name)->toBe('Created Company')
        ->and($company->onboarding_completed_at)->toBe('2026-07-18 16:00:00')
        ->and($company->onboarding_completed_by_uuid)->toBe('creator-user')
        ->and($membership)->not->toBeNull()
        ->and($membership->status)->toBe('active')
        ->and(User::find('creator-user')->company_uuid)->toBe($company->uuid)
        ->and(session('company'))->toBe($company->uuid);
});

test('create organization reports persistence failures without replacing the active session', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'         => 'creator-user',
        'email'        => 'creator@example.test',
        'company_uuid' => 'company-origin',
        'type'         => 'admin',
    ]);

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('companies');

    $response = (new AuthController())->createOrganization(auth_controller_authenticated_request('POST', [
        'uuid' => 'company-created',
        'name' => 'Created Company',
    ], User::find('creator-user'), '/int/v1/organizations'));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['errors'][0])->toContain('companies')
        ->and(session('company'))->toBe('company-origin');
});

test('auth services response returns unique configured authorization schema names', function () {
    auth_controller_login_bootstrap_database();

    $response = (new AuthController())->services();
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload)->toBeArray()
        ->and($payload)->toBe(array_values(array_unique($payload)));
});

test('admin password change enforces authorization target and confirmation contracts', function () {
    $capsule = auth_controller_login_bootstrap_database();
    $mail    = new AuthControllerLoginBootstrapMailFake();
    Mail::swap($mail);
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'admin-user',
        'email' => 'admin@example.test',
        'type'  => 'admin',
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'     => 'target-user',
        'email'    => 'target@example.test',
        'password' => password_hash('old-password', PASSWORD_BCRYPT),
        'type'     => 'user',
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'other-user',
        'email' => 'other@example.test',
        'type'  => 'user',
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['uuid' => 'target-membership', 'user_uuid' => 'target-user', 'company_uuid' => 'company-1', 'status' => 'active', 'external' => false, 'deleted_at' => null, 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00'],
    ]);

    $missingActor = (new AuthController())->changeUserPassword(ChangePasswordRequest::create('/int/v1/auth/change-password', 'POST', [
        'user'                  => 'target-user',
        'password'              => 'New-password1!',
        'password_confirmation' => 'New-password1!',
    ]));
    $limitedActor = new AuthControllerLoginBootstrapUserSpy([
        'uuid'         => 'limited-user',
        'company_uuid' => 'company-1',
        'email'        => 'limited@example.test',
        'type'         => 'user',
    ]);
    $limitedActor->exists = true;

    $unauthorized = (new AuthController())->changeUserPassword(auth_controller_authenticated_request('POST', [
        'user'                  => 'target-user',
        'password'              => 'New-password1!',
        'password_confirmation' => 'New-password1!',
    ], $limitedActor, '/int/v1/auth/change-password', ChangePasswordRequest::class));
    $missingTarget = (new AuthController())->changeUserPassword(auth_controller_authenticated_request('POST', [
        'password'              => 'New-password1!',
        'password_confirmation' => 'New-password1!',
    ], User::find('admin-user'), '/int/v1/auth/change-password', ChangePasswordRequest::class));
    $mismatch = (new AuthController())->changeUserPassword(auth_controller_authenticated_request('POST', [
        'user'                  => 'target-user',
        'password'              => 'New-password1!',
        'password_confirmation' => 'Different-password1!',
    ], User::find('admin-user'), '/int/v1/auth/change-password', ChangePasswordRequest::class));
    $foreignTarget = (new AuthController())->changeUserPassword(auth_controller_authenticated_request('POST', [
        'user'                  => 'other-user',
        'password'              => 'New-password1!',
        'password_confirmation' => 'New-password1!',
    ], User::find('admin-user'), '/int/v1/auth/change-password', ChangePasswordRequest::class));
    $success = (new AuthController())->changeUserPassword(auth_controller_authenticated_request('POST', [
        'user'                  => 'target-user',
        'password'              => 'New-password1!',
        'password_confirmation' => 'New-password1!',
        'send_credentials'      => true,
    ], User::find('admin-user'), '/int/v1/auth/change-password', ChangePasswordRequest::class));

    expect($missingActor->getStatusCode())->toBe(401)
        ->and($missingActor->getData(true))->toBe(['errors' => ['Not authorized to change user password.']])
        ->and($unauthorized->getStatusCode())->toBe(401)
        ->and($unauthorized->getData(true))->toBe(['errors' => ['Not authorized to change user password.']])
        ->and($missingTarget->getStatusCode())->toBe(400)
        ->and($missingTarget->getData(true))->toBe(['errors' => ['No user specified to change password for.']])
        ->and($mismatch->getStatusCode())->toBe(400)
        ->and($mismatch->getData(true))->toBe(['errors' => ['Passwords do not match.']])
        ->and($foreignTarget->getStatusCode())->toBe(400)
        ->and($foreignTarget->getData(true))->toBe(['errors' => ['User not found to change password for.']])
        ->and($success->getStatusCode())->toBe(200)
        ->and($success->getData(true))->toBe(['status' => 'ok'])
        ->and(password_verify('New-password1!', User::find('target-user')->password))->toBeTrue()
        ->and($mail->sent)->toHaveCount(1)
        ->and($mail->sent[0][0]->uuid)->toBe('target-user')
        ->and($mail->sent[0][1])->toBe(Fleetbase\Mail\UserCredentialsMail::class);
});

test('admin impersonation protects role target and session token contracts', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'admin-user',
        'email' => 'admin@example.test',
        'type'  => 'admin',
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'regular-user',
        'email' => 'regular@example.test',
        'type'  => 'user',
    ]);

    $unauthorized = (new AuthController())->impersonate(auth_controller_authenticated_request('POST', [
        'user' => 'admin-user',
    ], User::find('regular-user'), '/int/v1/auth/impersonate', AdminRequest::class));
    $missingSelected = (new AuthController())->impersonate(auth_controller_authenticated_request('POST', [], User::find('admin-user'), '/int/v1/auth/impersonate', AdminRequest::class));
    $missingTarget   = (new AuthController())->impersonate(auth_controller_authenticated_request('POST', [
        'user' => 'missing-user',
    ], User::find('admin-user'), '/int/v1/auth/impersonate', AdminRequest::class));
    $success = (new AuthController())->impersonate(auth_controller_authenticated_request('POST', [
        'user' => 'regular-user',
    ], User::find('admin-user'), '/int/v1/auth/impersonate', AdminRequest::class));

    expect($unauthorized->getStatusCode())->toBe(400)
        ->and($unauthorized->getData(true))->toBe(['errors' => ['Not authorized to impersonate users.']])
        ->and($missingSelected->getStatusCode())->toBe(400)
        ->and($missingSelected->getData(true))->toBe(['errors' => ['Not target user selected to impersonate.']])
        ->and($missingTarget->getStatusCode())->toBe(400)
        ->and($missingTarget->getData(true))->toBe(['errors' => ['The selected user to impersonate was not found.']])
        ->and($success->getStatusCode())->toBe(200)
        ->and($success->getData(true)['status'])->toBe('ok')
        ->and($success->getData(true)['token'])->toContain('|')
        ->and(session('user'))->toBe('regular-user')
        ->and(session('impersonator'))->toBe('admin-user')
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->where('tokenable_id', 'regular-user')->count())->toBe(1);
});

test('admin impersonation reports token creation failures after successful authorization', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'admin-user',
        'email' => 'admin@example.test',
        'type'  => 'admin',
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'regular-user',
        'email' => 'regular@example.test',
        'type'  => 'user',
    ]);

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('personal_access_tokens');

    $response = (new AuthController())->impersonate(auth_controller_authenticated_request('POST', [
        'user' => 'regular-user',
    ], User::find('admin-user'), '/int/v1/auth/impersonate', AdminRequest::class));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['errors'][0])->toContain('personal_access_tokens')
        ->and(session('user'))->toBe('regular-user')
        ->and(session('impersonator'))->toBe('admin-user');
});

test('end impersonation validates impersonator session and restores admin access token', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'admin-user',
        'email' => 'admin@example.test',
        'type'  => 'admin',
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'regular-user',
        'email' => 'regular@example.test',
        'type'  => 'user',
    ]);

    session()->flush();
    $missingSession = (new AuthController())->endImpersonation();

    session(['impersonator' => 'missing-admin']);
    $missingUser = (new AuthController())->endImpersonation();

    session(['impersonator' => 'regular-user']);
    $notAdmin = (new AuthController())->endImpersonation();

    session(['impersonator' => 'admin-user', 'user' => 'regular-user']);
    $success = (new AuthController())->endImpersonation();
    $payload = $success->getData(true);

    expect($missingSession->getStatusCode())->toBe(400)
        ->and($missingSession->getData(true))->toBe(['errors' => ['Not impersonator session found.']])
        ->and($missingUser->getStatusCode())->toBe(400)
        ->and($missingUser->getData(true))->toBe(['errors' => ['The impersonator user was not found.']])
        ->and($notAdmin->getStatusCode())->toBe(400)
        ->and($notAdmin->getData(true))->toBe(['errors' => ['The impersonator does not have permissions. Logout.']])
        ->and($success->getStatusCode())->toBe(200)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['token'])->toContain('|')
        ->and(session('user'))->toBe('admin-user')
        ->and(session('impersonator'))->toBeNull()
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->where('tokenable_id', 'admin-user')->count())->toBe(1);
});

test('end impersonation reports token creation failures after restoring the impersonator session', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'admin-user',
        'email' => 'admin@example.test',
        'type'  => 'admin',
    ]);
    auth_controller_login_insert_user($capsule, [
        'uuid'  => 'regular-user',
        'email' => 'regular@example.test',
        'type'  => 'user',
    ]);

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('personal_access_tokens');

    session(['impersonator' => 'admin-user', 'user' => 'regular-user']);
    $response = (new AuthController())->endImpersonation();

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['errors'][0])->toContain('personal_access_tokens')
        ->and(session('user'))->toBe('admin-user')
        ->and(session('impersonator'))->toBeNull();
});

// -----------------------------------------------------------------------------
// authenticateSmsCode — SMS-2FA security contracts (bypass gating + replay).
// These assert the security-critical branches that run before/around the user
// lookup, so they do not depend on the Auth::login guard path.
// -----------------------------------------------------------------------------

function auth_controller_sms_request(array $input): Request
{
    return Request::create('/int/v1/auth/authenticate-sms', 'POST', $input);
}

test('authenticate sms code rejects an invalid verification code', function () {
    auth_controller_login_bootstrap_database();

    $response = (new AuthController())->authenticateSmsCode(auth_controller_sms_request([
        'phone' => '+15555550123',
        'code'  => '000000',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['Invalid verification code']]);
});

test('authenticate sms code refuses the bypass code in production', function () {
    auth_controller_login_bootstrap_database();
    config([
        'app.env'                        => 'production',
        'fleetbase.sms_auth_bypass_code' => 'BYPASS-PROD',
    ]);

    // No stored OTP; the only candidate is the bypass code, which must NOT be honored
    // in a production environment.
    $response = (new AuthController())->authenticateSmsCode(auth_controller_sms_request([
        'phone' => '+15555550123',
        'code'  => 'BYPASS-PROD',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['Invalid verification code']]);
});

test('authenticate sms code accepts the bypass code outside production', function () {
    auth_controller_login_bootstrap_database();
    config(['fleetbase.sms_auth_bypass_code' => 'BYPASS-DEV']);

    // Non-production env (default 'testing') with no user for the phone: the bypass code
    // passes validation and falls through to the failing user lookup, proving it was
    // accepted only because the environment is not production.
    $response = (new AuthController())->authenticateSmsCode(auth_controller_sms_request([
        'phone' => '+15555559999',
        'code'  => 'BYPASS-DEV',
    ]));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe('Authentication failed');
});

test('authenticate sms code consumes the stored otp to prevent replay', function () {
    auth_controller_login_bootstrap_database();

    $phone = '+15555559999';
    $key   = SupportStr::slug($phone . '_verify_code', '_');
    app('redis')->set($key, '654321');

    // First use: valid OTP but no user for this phone -> 401, and the OTP is deleted.
    $first = (new AuthController())->authenticateSmsCode(auth_controller_sms_request([
        'phone' => $phone,
        'code'  => '654321',
    ]));

    expect($first->getStatusCode())->toBe(401)
        ->and(app('redis')->get($key))->toBeNull();

    // Replaying the same code now fails because the stored OTP was consumed.
    $replay = (new AuthController())->authenticateSmsCode(auth_controller_sms_request([
        'phone' => $phone,
        'code'  => '654321',
    ]));

    expect($replay->getStatusCode())->toBe(400)
        ->and($replay->getData(true))->toBe(['errors' => ['Invalid verification code']]);
});

test('authenticate sms code authenticates a matching user and issues a token on a valid otp', function () {
    $capsule = auth_controller_login_bootstrap_database();
    auth_controller_login_insert_user($capsule); // phone +15555550123

    $authGuard = new AuthControllerLoginBootstrapAuthGuardFake();
    app()->instance('auth', $authGuard);
    Facade::clearResolvedInstance('auth');

    $phone = '+15555550123';
    $key   = SupportStr::slug($phone . '_verify_code', '_');
    app('redis')->set($key, '123456');

    $response = (new AuthController())->authenticateSmsCode(auth_controller_sms_request([
        'phone' => $phone,
        'code'  => '123456',
    ]));

    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['token'])->toBeString()
        ->and($payload['token'])->not->toBeEmpty()
        ->and($payload['user']['uuid'])->toBe('11111111-1111-4111-8111-111111111111')
        // The user was logged in through the guard, the OTP consumed, and a token issued.
        ->and($authGuard->loggedIn)->toHaveCount(1)
        ->and(app('redis')->get($key))->toBeNull()
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->count())->toBe(1);
});
