<?php

use Fleetbase\Http\Controllers\Internal\v1\RoleController;
use Fleetbase\Models\Policy;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
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
    public bool $teams = false;
    public string $teamsKey = 'team_id';
}

function role_controller_container(array $config = []): Container
{
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
    'exact administrator' => ['Administrator'],
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
