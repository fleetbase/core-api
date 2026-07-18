<?php

use Fleetbase\Http\Controllers\Internal\v1\UserController;
use Fleetbase\Http\Requests\Internal\ChangeCurrentUserEmailRequest;
use Fleetbase\Http\Requests\Internal\ChangeUserEmailRequest;
use Fleetbase\Http\Requests\Internal\UpdatePasswordRequest;
use Fleetbase\Http\Requests\Internal\ValidatePasswordRequest;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 3);

        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
    }
}

class UserControllerHashFake
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

class UserControllerCacheFake
{
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }
}

class UserControllerPermissionRegistrarFake
{
    public string $pivotRole       = 'role_id';
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';
}

class UserControllerRouteStub
{
    public UserController $controller;

    public function __construct(private string $method = 'current')
    {
        $this->controller = new UserController();
    }

    public function getAction(?string $key = null): mixed
    {
        $action = [
            'controller' => UserController::class . '@' . $this->method,
        ];

        return $key ? $action[$key] ?? null : $action;
    }

    public function getActionMethod(): string
    {
        return $this->method;
    }
}

function user_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'UTC'));

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.env'                                      => 'testing',
        'auth.defaults.guard'                          => 'sanctum',
        'auth.guards.sanctum.provider'                 => 'users',
        'auth.providers.users.model'                   => User::class,
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.column_names.model_morph_key'      => 'model_uuid',
    ]);

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

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            if (is_string($value) && str_contains($value, ',')) {
                return explode(',', $value);
            }

            return is_array($value) ? $value : $default;
        });
    }

    if (!Request::hasMacro('getController')) {
        Request::macro('getController', function (): mixed {
            return $this->route()?->controller;
        });
    }

    $container->instance('hash', new UserControllerHashFake());
    $container->instance('cache', new UserControllerCacheFake());
    $container->instance(Spatie\Permission\PermissionRegistrar::class, new UserControllerPermissionRegistrarFake());
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('cache');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');

    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'owner-1',
    ]);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable();
        $table->string('avatar_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->string('password')->nullable();
        $table->string('remember_token')->nullable();
        $table->string('secret')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->index();
        $table->string('user_uuid')->index();
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('verification_codes', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->text('meta')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('directives', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('permission_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('key')->nullable();
        $table->text('rules')->nullable();
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
        $table->string('description')->nullable();
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

    $now = '2026-07-18 10:00:00';
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_public_1', 'name' => 'Acme Logistics', 'owner_uuid' => 'owner-1', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-2', 'public_id' => 'company_public_2', 'name' => 'Beta Freight', 'owner_uuid' => 'foreign-1', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'owner-1', 'public_id' => 'user_owner_1', 'company_uuid' => 'company-1', 'email' => 'owner@example.test', 'name' => 'Owner One', 'password' => password_hash('old-password', PASSWORD_BCRYPT), 'type' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'member-1', 'public_id' => 'user_member_1', 'company_uuid' => 'company-1', 'email' => 'member@example.test', 'name' => 'Member One', 'password' => password_hash('old-password', PASSWORD_BCRYPT), 'type' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'single-1', 'public_id' => 'user_single_1', 'company_uuid' => 'company-1', 'email' => 'single@example.test', 'name' => 'Single Org', 'password' => null, 'type' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'foreign-1', 'public_id' => 'user_foreign_1', 'company_uuid' => 'company-2', 'email' => 'foreign@example.test', 'name' => 'Foreign User', 'password' => null, 'type' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'admin-1', 'public_id' => 'user_admin_1', 'company_uuid' => null, 'email' => 'admin@example.test', 'name' => 'Admin User', 'password' => null, 'type' => 'admin', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['uuid' => 'pivot-owner-1', 'company_uuid' => 'company-1', 'user_uuid' => 'owner-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'pivot-member-1', 'company_uuid' => 'company-1', 'user_uuid' => 'member-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'pivot-member-2', 'company_uuid' => 'company-2', 'user_uuid' => 'member-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'pivot-single-1', 'company_uuid' => 'company-1', 'user_uuid' => 'single-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'pivot-foreign-1', 'company_uuid' => 'company-2', 'user_uuid' => 'foreign-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function user_controller(): UserController
{
    return new UserController();
}

function user_controller_request(string $method = 'GET', array $input = [], ?User $user = null, string $action = 'current', ?string $requestClass = null): Request
{
    $requestClass ??= Request::class;
    $request = $requestClass::create('/int/v1/users', $method, $input);
    $request->setRouteResolver(fn () => new UserControllerRouteStub($action));
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);

    return $request;
}

function user_controller_user(string $uuid): User
{
    return User::where('uuid', $uuid)->firstOrFail();
}

afterEach(function () {
    session()->flush();
    Carbon::setTestNow();
    config([
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('user controller scopes query and lookup to the active company unless requester is system admin', function () {
    user_controller_database();

    $tenantQuery = User::query();
    user_controller()->onQueryRecord($tenantQuery, user_controller_request('GET', [], user_controller_user('owner-1'), 'queryRecord'));

    expect($tenantQuery->pluck('uuid')->sort()->values()->all())->toBe(['member-1', 'owner-1', 'single-1']);

    session()->flush();
    $emptyQuery = User::query();
    user_controller()->onQueryRecord($emptyQuery, user_controller_request('GET', [], user_controller_user('owner-1'), 'queryRecord'));

    expect($emptyQuery->count())->toBe(0);

    session(['company' => 'company-1', 'user' => 'admin-1']);
    $adminQuery = User::query();
    user_controller()->onQueryRecord($adminQuery, user_controller_request('GET', [], user_controller_user('admin-1'), 'queryRecord'));

    expect($adminQuery->pluck('uuid')->sort()->values()->all())->toBe(['admin-1', 'foreign-1', 'member-1', 'owner-1', 'single-1']);

    $visible = user_controller()->findRecord(user_controller_request('GET', [], user_controller_user('owner-1'), 'findRecord'), 'user_member_1');
    $foreign = user_controller()->findRecord(user_controller_request('GET', [], user_controller_user('owner-1'), 'findRecord'), 'user_foreign_1');

    expect($visible['user']->resource->uuid)->toBe('member-1')
        ->and($foreign->getStatusCode())->toBe(404)
        ->and($foreign->getData(true))->toBe(['errors' => ['User not found']]);
});

test('user controller blocks generic deletes and identity mutations for organization scoped users', function () {
    user_controller_database();

    $delete         = user_controller()->deleteRecord('user_member_1', user_controller_request('DELETE', [], user_controller_user('owner-1'), 'deleteRecord'));
    $missingDelete  = user_controller()->deleteRecord('user_foreign_1', user_controller_request('DELETE', [], user_controller_user('owner-1'), 'deleteRecord'));
    $identityUpdate = user_controller()->updateRecord(user_controller_request('PATCH', [
        'user' => [
            'email' => 'changed@example.test',
            'name'  => 'Changed Name',
        ],
    ], user_controller_user('owner-1'), 'updateRecord'), 'user_member_1');

    expect($delete->getStatusCode())->toBe(403)
        ->and($delete->getData(true))->toBe(['errors' => ['Use the remove-from-company endpoint to remove users from an organization.']])
        ->and($missingDelete->getStatusCode())->toBe(404)
        ->and($identityUpdate->getStatusCode())->toBe(422)
        ->and($identityUpdate->getData(true))->toBe(['errors' => ['Login identity fields cannot be updated from this endpoint.']])
        ->and(User::where('uuid', 'member-1')->value('email'))->toBe('member@example.test');
});

test('user controller activates deactivates verifies and removes users only through company scoped membership', function () {
    $capsule = user_controller_database();

    user_controller_request('POST', [], user_controller_user('owner-1'), 'deactivate');
    $deactivated = user_controller()->deactivate('member-1');

    expect($deactivated->getStatusCode())->toBe(200)
        ->and($deactivated->getData(true))->toBe([
            'message' => 'User deactivated',
            'status'  => 'inactive',
        ])
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-1')->value('status'))->toBe('inactive')
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-2')->value('status'))->toBe('active')
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'member-1')->value('status'))->toBe('active');

    user_controller_request('POST', [], user_controller_user('owner-1'), 'activate');
    $activated = user_controller()->activate('member-1');
    user_controller_request('POST', [], user_controller_user('owner-1'), 'verify');
    $verified = user_controller()->verify('member-1');

    expect($activated->getStatusCode())->toBe(200)
        ->and($activated->getData(true))->toBe([
            'message' => 'User activated',
            'status'  => 'active',
        ])
        ->and($verified->getStatusCode())->toBe(200)
        ->and($verified->getData(true)['message'])->toBe('User verified')
        ->and($verified->getData(true)['status'])->toBe('ok')
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'member-1')->value('email_verified_at'))->not->toBeNull();

    user_controller_request('POST', [], user_controller_user('owner-1'), 'deactivate');
    $selfDeactivate = user_controller()->deactivate('owner-1');
    user_controller_request('POST', [], user_controller_user('owner-1'), 'activate');
    $foreignActivate = user_controller()->activate('foreign-1');
    user_controller_request('POST', [], user_controller_user('owner-1'), 'removeFromCompany');
    $singleRemoval = user_controller()->removeFromCompany('single-1');

    expect($selfDeactivate->getStatusCode())->toBe(403)
        ->and($selfDeactivate->getData(true))->toBe(['errors' => ['You cannot deactivate your own account.']])
        ->and($foreignActivate->getStatusCode())->toBe(404)
        ->and($foreignActivate->getData(true))->toBe(['errors' => ['No user found']])
        ->and($singleRemoval->getStatusCode())->toBe(200)
        ->and($singleRemoval->getData(true))->toBe(['message' => 'User removed'])
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'single-1')->whereNotNull('deleted_at')->exists())->toBeTrue();
});

test('user controller covers current-user password locale and simple validation response contracts', function () {
    $capsule = user_controller_database();
    $user    = user_controller_user('owner-1');

    $missingCurrent  = user_controller()->current(user_controller_request('GET'));
    $missingPassword = user_controller()->setCurrentUserPassword(user_controller_request('POST', [
        'password' => 'new-password',
    ], null, 'setCurrentUserPassword', UpdatePasswordRequest::class));
    $passwordMismatch = user_controller()->changeUserPassword(user_controller_request('POST', [
        'password'              => 'new-password',
        'password_confirmation' => 'different-password',
    ], $user, 'changeUserPassword', UpdatePasswordRequest::class));

    expect($missingCurrent->getStatusCode())->toBe(401)
        ->and($missingCurrent->getData(true))->toBe(['errors' => ['No user session found']])
        ->and($missingPassword->getStatusCode())->toBe(400)
        ->and($missingPassword->getData(true))->toBe(['errors' => ['User not authenticated']])
        ->and($passwordMismatch->getStatusCode())->toBe(400)
        ->and($passwordMismatch->getData(true))->toBe(['errors' => ['Password is not matching']]);

    $changedPassword = user_controller()->changeUserPassword(user_controller_request('POST', [
        'password'              => 'new-password',
        'password_confirmation' => 'new-password',
    ], $user, 'changeUserPassword', UpdatePasswordRequest::class));
    $setLocale = user_controller()->setUserLocale(user_controller_request('POST', [
        'locale' => 'fr-fr',
    ], $user));
    $getLocale     = user_controller()->getUserLocale(user_controller_request('GET', [], $user));
    $validPassword = user_controller()->validatePassword(user_controller_request('POST', [], $user, 'validatePassword', ValidatePasswordRequest::class));

    expect($changedPassword->getData(true))->toBe(['status' => 'ok'])
        ->and(password_verify('new-password', $capsule->getConnection('mysql')->table('users')->where('uuid', 'owner-1')->value('password')))->toBeTrue()
        ->and($setLocale->getData(true))->toBe(['status' => 'ok'])
        ->and($getLocale->getData(true))->toBe(['status' => 'ok', 'locale' => 'fr-fr'])
        ->and($validPassword->getData(true))->toBe(['status' => 'ok']);
});

test('user controller rejects email change requests without an authorized actor or matching target state', function () {
    user_controller_database();

    session()->flush();
    $missingActor = user_controller()->changeCurrentUserEmail(user_controller_request('POST', [
        'email' => 'owner-new@example.test',
    ], null, 'changeCurrentUserEmail', ChangeCurrentUserEmailRequest::class));

    session(['company' => 'company-1', 'user' => 'owner-1']);
    $sameCurrentEmail = user_controller()->changeCurrentUserEmail(user_controller_request('POST', [
        'email' => 'OWNER@example.test',
    ], user_controller_user('owner-1'), 'changeCurrentUserEmail', ChangeCurrentUserEmailRequest::class));
    $sameManagedEmail = user_controller()->changeEmail(user_controller_request('POST', [
        'email' => 'MEMBER@example.test',
    ], user_controller_user('admin-1'), 'changeEmail', ChangeUserEmailRequest::class), 'member-1');
    $missingTarget = user_controller()->changeEmail(user_controller_request('POST', [
        'email' => 'new@example.test',
    ], user_controller_user('admin-1'), 'changeEmail', ChangeUserEmailRequest::class), 'foreign-1');

    expect($missingActor->getStatusCode())->toBe(401)
        ->and($missingActor->getData(true))->toBe(['errors' => ['No user session found']])
        ->and($sameCurrentEmail->getStatusCode())->toBe(400)
        ->and($sameCurrentEmail->getData(true))->toBe(['errors' => ['The new email address must be different from the current email address.']])
        ->and($sameManagedEmail->getStatusCode())->toBe(400)
        ->and($sameManagedEmail->getData(true))->toBe(['errors' => ['The new email address must be different from the current email address.']])
        ->and($missingTarget->getStatusCode())->toBe(404)
        ->and($missingTarget->getData(true))->toBe(['errors' => ['User not found to change email for.']]);
});
