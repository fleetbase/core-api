<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Requests\LoginRequest;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

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

class AuthControllerLoginBootstrapPermissionRegistrarFake
{
    public string $pivotRole       = 'role_id';
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';
}

function auth_controller_login_bootstrap_database(): Capsule
{
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.env'                                      => 'testing',
        'app.timezone'                                 => 'UTC',
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
    $container->instance('responsecache', new AuthControllerLoginBootstrapResponseCacheFake());
    $container->instance(Spatie\Permission\PermissionRegistrar::class, new AuthControllerLoginBootstrapPermissionRegistrarFake());
    Cache::swap($cache);
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('hash');
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
