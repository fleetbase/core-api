<?php

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Http\Controllers\Internal\v1\RoleController;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\Policy;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;

class RoleControllerCacheFake
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

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
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

class RoleControllerPermissionRegistrarFake
{
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';
}

class RoleControllerRoleFake extends EloquentModel
{
    protected $table      = 'roles';
    protected $primaryKey = 'id';
    public $incrementing  = false;
    public $timestamps    = false;
    protected $guarded    = [];

    public array $syncedPermissions    = [];
    public array $syncedPolicies       = [];
    public array $calls                = [];
    public bool $throwOnCreate         = false;
    public bool $throwOnUpdate         = false;
    public ?Throwable $createThrowable = null;
    public ?Throwable $updateThrowable = null;

    public function createRecordFromRequest(Request $request, ?callable $onBefore = null, ?callable $onAfter = null): self
    {
        $this->calls[] = ['createRecordFromRequest', $request->input('role.name')];

        if ($this->throwOnCreate) {
            throw new RuntimeException('role creation failed');
        }

        if ($this->createThrowable) {
            throw $this->createThrowable;
        }

        $record = new self([
            'id'         => 'role-created',
            'name'       => $request->input('role.name'),
            'guard_name' => 'sanctum',
        ]);

        if ($onBefore) {
            $onBefore($request, $record);
        }

        if ($onAfter) {
            $onAfter($request, $record);
        }

        return $record;
    }

    public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null): self
    {
        $this->calls[] = ['updateRecordFromRequest', $id, $request->input('role.name')];

        if ($this->throwOnUpdate) {
            throw new RuntimeException('role update failed');
        }

        if ($this->updateThrowable) {
            throw $this->updateThrowable;
        }

        $record = new self([
            'id'         => $id,
            'name'       => $request->input('role.name'),
            'guard_name' => 'sanctum',
        ]);

        if ($onBefore) {
            $onBefore($request, $record);
        }

        if ($onAfter) {
            $onAfter($request, $record);
        }

        return $record;
    }

    public function syncPermissions($permissions): self
    {
        $this->syncedPermissions = $permissions->pluck('id')->all();

        return $this;
    }

    public function syncPolicies($policies): self
    {
        $this->syncedPolicies = $policies->pluck('id')->all();

        return $this;
    }
}

function role_controller_boot_request_macros(): void
{
    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            return (array) $this->input($key, $default);
        });
    }

    if (!Request::hasMacro('isArray')) {
        Request::macro('isArray', function (string $key): bool {
            return $this->has($key) && is_array($this->input($key));
        });
    }
}

function role_controller_container(array $config = []): Container
{
    role_controller_boot_request_macros();

    $container = bind_test_container(array_merge([
        'auth.defaults.guard'                           => 'sanctum',
        'permission.models.permission'                  => Fleetbase\Models\Permission::class,
        'permission.models.role'                        => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'            => 'permissions',
        'permission.table_names.roles'                  => 'roles',
        'permission.table_names.model_has_permissions'  => 'model_has_permissions',
        'permission.table_names.role_has_permissions'   => 'role_has_permissions',
        'permission.column_names.model_morph_key'       => 'model_uuid',
    ], $config));

    $container->instance('cache', new RoleControllerCacheFake());
    $container->instance(PermissionRegistrar::class, new RoleControllerPermissionRegistrarFake());
    Facade::clearResolvedInstance('cache');

    return $container;
}

function role_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = role_controller_container([
        'database.default'                              => 'mysql',
        'database.connections.mysql'                    => $connection,
        'fleetbase.connection.db'                       => 'mysql',
    ]);

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
    Facade::clearResolvedInstance('schema');

    session()->flush();
    session(['company' => 'company-1']);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
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
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('policies')->insert([
        ['id' => 'policy-global', 'company_uuid' => null, 'name' => 'Global Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'policy-company', 'company_uuid' => 'company-1', 'name' => 'Company Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'policy-other-company', 'company_uuid' => 'company-2', 'name' => 'Other Company Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function role_controller(): RoleController
{
    role_controller_container();

    return (new ReflectionClass(RoleController::class))->newInstanceWithoutConstructor();
}

function role_controller_with_model(RoleControllerRoleFake $model): RoleController
{
    role_controller_container();

    $controller           = (new ReflectionClass(RoleController::class))->newInstanceWithoutConstructor();
    $controller->model    = $model;
    $controller->resource = FleetbaseResource::class;

    return $controller;
}

function role_controller_reflect(RoleController $controller, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

afterEach(function () {
    session()->flush();
    config([
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('role controller rejects administrator and admin prefixed role names before generic creation', function (string $name) {
    $response = role_controller()->createRecord(Request::create('/int/v1/roles', 'POST', [
        'role' => [
            'name' => $name,
        ],
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Creating a role with name "Administrator" or a role name that starts with "Admin" is prohibited, as the name is system reserved.'],
        ]);
})->with([
    'exact administrator'     => ['Administrator'],
    'admin prefix lower case' => ['admin supervisor'],
    'admin prefix mixed case' => ['AdminOps'],
]);

test('role controller resolves assignable policies from global and active company scopes only', function () {
    role_controller_database();

    $policies = role_controller_reflect(role_controller(), 'getAssignablePolicies', [
        'policy-global',
        'policy-company',
        'policy-other-company',
        'missing-policy',
    ]);

    expect($policies)->toHaveCount(2)
        ->and($policies->pluck('id')->sort()->values()->all())->toBe(['policy-company', 'policy-global'])
        ->and($policies->every(fn (Policy $policy) => $policy->company_uuid === null || $policy->company_uuid === 'company-1'))->toBeTrue();
});

test('role controller create syncs requested permissions and assignable policies only', function () {
    $capsule = role_controller_database();
    $capsule->getConnection('mysql')->table('permissions')->insert([
        ['id' => 'permission-view', 'name' => 'iam view roles', 'guard_name' => 'sanctum', 'created_at' => '2026-07-18 00:00:00', 'updated_at' => '2026-07-18 00:00:00'],
        ['id' => 'permission-list', 'name' => 'iam list roles', 'guard_name' => 'sanctum', 'created_at' => '2026-07-18 00:00:00', 'updated_at' => '2026-07-18 00:00:00'],
    ]);

    $model      = new RoleControllerRoleFake();
    $controller = role_controller_with_model($model);
    $response   = $controller->createRecord(Request::create('/int/v1/roles', 'POST', [
        'role' => [
            'name'        => 'Dispatcher',
            'permissions' => ['permission-view', 'permission-list', 'missing-permission'],
            'policies'    => ['policy-global', 'policy-company', 'policy-other-company'],
        ],
    ]));

    $role = $response['role']->resource;

    expect($model->calls)->toBe([['createRecordFromRequest', 'Dispatcher']])
        ->and($role)->toBeInstanceOf(RoleControllerRoleFake::class)
        ->and($role->name)->toBe('Dispatcher')
        ->and(collect($role->syncedPermissions)->sort()->values()->all())->toBe(['permission-list', 'permission-view'])
        ->and(collect($role->syncedPolicies)->sort()->values()->all())->toBe(['policy-company', 'policy-global']);
});

test('role controller update syncs relation arrays and preserves scoped policy boundary', function () {
    $capsule = role_controller_database();
    $capsule->getConnection('mysql')->table('permissions')->insert([
        ['id' => 'permission-update', 'name' => 'iam update roles', 'guard_name' => 'sanctum', 'created_at' => '2026-07-18 00:00:00', 'updated_at' => '2026-07-18 00:00:00'],
    ]);

    $model      = new RoleControllerRoleFake();
    $controller = role_controller_with_model($model);
    $response   = $controller->updateRecord(Request::create('/int/v1/roles/role-1', 'PATCH', [
        'role' => [
            'name'        => 'Warehouse Manager',
            'permissions' => ['permission-update'],
            'policies'    => ['policy-company', 'policy-other-company'],
        ],
    ]), 'role-1');

    $role = $response['role']->resource;

    expect($model->calls)->toBe([['updateRecordFromRequest', 'role-1', 'Warehouse Manager']])
        ->and($role->id)->toBe('role-1')
        ->and($role->syncedPermissions)->toBe(['permission-update'])
        ->and($role->syncedPolicies)->toBe(['policy-company']);
});

test('role controller create and update return error responses when model persistence fails', function (string $method, array $modelFlags, array $arguments, string $message) {
    role_controller_database();

    $model = new RoleControllerRoleFake();
    foreach ($modelFlags as $property => $value) {
        $model->{$property} = $value;
    }

    $response = role_controller_with_model($model)->{$method}(...$arguments);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => [$message]]);
})->with([
    'create failure' => [
        'createRecord',
        ['throwOnCreate' => true],
        [Request::create('/int/v1/roles', 'POST', ['role' => ['name' => 'Dispatcher']])],
        'role creation failed',
    ],
    'update failure' => [
        'updateRecord',
        ['throwOnUpdate' => true],
        [Request::create('/int/v1/roles/role-1', 'PATCH', ['role' => ['name' => 'Dispatcher']]), 'role-1'],
        'role update failed',
    ],
]);

test('role controller returns validation and query errors from create and update without generic masking', function () {
    role_controller_database();

    $validationModel                  = new RoleControllerRoleFake();
    $validationModel->createThrowable = new FleetbaseRequestValidationException(['role.name' => ['The role name is required.']]);
    $validationModel->updateThrowable = new FleetbaseRequestValidationException(['role.name' => ['The role name is required.']]);

    $queryModel                  = new RoleControllerRoleFake();
    $queryModel->createThrowable = new QueryException('mysql', 'insert into roles', [], new RuntimeException('database unavailable'));
    $queryModel->updateThrowable = new QueryException('mysql', 'update roles', [], new RuntimeException('database unavailable'));

    $validationController = role_controller_with_model($validationModel);
    $queryController      = role_controller_with_model($queryModel);

    $createValidation = $validationController->createRecord(Request::create('/int/v1/roles', 'POST', ['role' => ['name' => 'Dispatcher']]));
    $updateValidation = $validationController->updateRecord(Request::create('/int/v1/roles/role-1', 'PATCH', ['role' => ['name' => 'Dispatcher']]), 'role-1');
    $createQuery      = $queryController->createRecord(Request::create('/int/v1/roles', 'POST', ['role' => ['name' => 'Dispatcher']]));
    $updateQuery      = $queryController->updateRecord(Request::create('/int/v1/roles/role-1', 'PATCH', ['role' => ['name' => 'Dispatcher']]), 'role-1');

    expect($createValidation->getStatusCode())->toBe(400)
        ->and($createValidation->getData(true))->toBe([
            'errors' => [
                'role.name' => ['The role name is required.'],
            ],
        ])
        ->and($updateValidation->getData(true))->toBe($createValidation->getData(true))
        ->and($createQuery->getStatusCode())->toBe(400)
        ->and($createQuery->getData(true)['errors'][0])->toContain('database unavailable')
        ->and($updateQuery->getStatusCode())->toBe(400)
        ->and($updateQuery->getData(true)['errors'][0])->toContain('database unavailable');
});
