<?php

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Http\Controllers\Internal\v1\PolicyController;
use Fleetbase\Http\Resources\Policy as PolicyResource;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;

class PolicyControllerCacheFake
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

    public function clear(): bool
    {
        return $this->flush();
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ((int) ($this->values[$key] ?? 0)) + $value;

        return $this->values[$key];
    }
}

class PolicyControllerPermissionRegistrarFake
{
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';

    public function forgetWildcardPermissionIndex(mixed $record = null): void
    {
    }
}

class PolicyControllerFailingPolicy extends Policy
{
    public ?Throwable $throwable = null;

    public function createRecordFromRequest($request, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        throw $this->throwable;
    }

    public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        throw $this->throwable;
    }
}

function policy_controller_container(array $config = []): Container
{
    $container = bind_test_container(array_merge([
        'auth.defaults.guard'                           => 'sanctum',
        'permission.models.permission'                  => Permission::class,
        'permission.models.role'                        => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'            => 'permissions',
        'permission.table_names.roles'                  => 'roles',
        'permission.table_names.model_has_permissions'  => 'model_has_permissions',
        'permission.table_names.role_has_permissions'   => 'role_has_permissions',
        'permission.column_names.model_morph_key'       => 'model_uuid',
    ], $config));

    $cache = new PolicyControllerCacheFake();
    $container->instance('cache', $cache);
    $container->instance('responsecache', $cache);
    $container->instance(PermissionRegistrar::class, new PolicyControllerPermissionRegistrarFake());
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');

    return $container;
}

function policy_controller_boot_request_macros(): void
{
    if (!Request::hasMacro('or')) {
        Request::macro('or', function (array $params = [], $default = null) {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        });
    }

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $param) {
            return (array) $this->input($param, []);
        });
    }

    if (!Request::hasMacro('isArray')) {
        Request::macro('isArray', function (string $param) {
            return $this->has($param) && is_array($this->input($param));
        });
    }

    if (!Request::hasMacro('getController')) {
        Request::macro('getController', function () {
            return $this->route()?->controller;
        });
    }
}

function policy_controller_database(): Capsule
{
    policy_controller_boot_request_macros();
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = policy_controller_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
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
        $table->string('description')->nullable();
        $table->string('service')->nullable();
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
    $schema->create('directives', function ($table) {
        $table->string('id')->primary();
        $table->string('permission_uuid')->nullable()->index();
        $table->json('rules')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('permissions')->insert([
        ['id' => 'perm-view', 'name' => 'iam view policies', 'guard_name' => 'sanctum', 'description' => null, 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'perm-edit', 'name' => 'iam edit policies', 'guard_name' => 'sanctum', 'description' => null, 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'perm-delete', 'name' => 'iam delete policies', 'guard_name' => 'sanctum', 'description' => null, 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('policies')->insert([
        ['id' => 'policy-company', 'company_uuid' => 'company-1', 'name' => 'Company Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'policy-other-company', 'company_uuid' => 'company-2', 'name' => 'Other Company Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'description' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('model_has_permissions')->insert([
        ['permission_id' => 'perm-view', 'model_type' => Policy::class, 'model_uuid' => 'policy-company'],
        ['permission_id' => 'perm-delete', 'model_type' => Policy::class, 'model_uuid' => 'policy-company'],
    ]);

    return $capsule;
}

function policy_controller(): PolicyController
{
    $_SERVER['REQUEST_METHOD'] ??= 'GET';

    return new PolicyController(new Policy());
}

function policy_controller_with_model(Policy $model): PolicyController
{
    $_SERVER['REQUEST_METHOD'] ??= 'GET';

    $controller        = new PolicyController(new Policy());
    $controller->model = $model;

    return $controller;
}

function policy_controller_request(string $method, string $uri, array $parameters = []): Request
{
    $_SERVER['REQUEST_METHOD'] = $method;

    $request = Request::create($uri, $method, $parameters);
    $route   = new Route($method, $uri, [
        'controller' => PolicyController::class . '@' . (in_array($method, ['PATCH', 'PUT'], true) ? 'updateRecord' : 'createRecord'),
    ]);
    $route->controller = policy_controller();
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function policy_permission_ids(Capsule $capsule, string $policyId): array
{
    return $capsule->getConnection('mysql')
        ->table('model_has_permissions')
        ->where('model_type', Policy::class)
        ->where('model_uuid', $policyId)
        ->pluck('permission_id')
        ->sort()
        ->values()
        ->all();
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

test('policy controller creates organization policies and syncs requested permissions', function () {
    $capsule = policy_controller_database();

    $result = policy_controller()->createRecord(policy_controller_request('POST', '/int/v1/policies', [
        'policy' => [
            'name'        => 'Dispatch Control',
            'description' => 'Controls dispatch permissions',
            'permissions' => ['perm-view', 'perm-edit'],
        ],
    ]));

    expect($result)->toBeArray()
        ->and($result['policy'])->toBeInstanceOf(PolicyResource::class)
        ->and($result['policy']->resource)->toBeInstanceOf(Policy::class)
        ->and($result['policy']->resource->company_uuid)->toBe('company-1')
        ->and($result['policy']->resource->name)->toBe('Dispatch Control');

    expect(policy_permission_ids($capsule, $result['policy']->resource->id))->toBe(['perm-edit', 'perm-view']);
});

test('policy controller updates policies inside the active company and replaces permissions', function () {
    $capsule = policy_controller_database();

    $result = policy_controller()->updateRecord(policy_controller_request('PATCH', '/int/v1/policies/policy-company', [
        'policy' => [
            'name'        => 'Updated Policy',
            'permissions' => ['perm-edit'],
        ],
    ]), 'policy-company');

    expect($result)->toBeArray()
        ->and($result['policy'])->toBeInstanceOf(PolicyResource::class)
        ->and($result['policy']->resource->id)->toBe('policy-company')
        ->and($result['policy']->resource->name)->toBe('Updated Policy');

    expect(policy_permission_ids($capsule, 'policy-company'))->toBe(['perm-edit']);
});

test('policy controller deletes only policies owned by the active company', function () {
    $capsule = policy_controller_database();

    $response = policy_controller()->deleteRecord('ignored', Request::create('/int/v1/policies/policy-company', 'DELETE'));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'OK', 'message' => 'Policy deleted.'])
        ->and(Policy::withTrashed()->where('id', 'policy-company')->first()->trashed())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('policies')->whereNull('deleted_at')->where('id', 'policy-other-company')->exists())->toBeTrue();
});

test('policy controller rejects delete attempts for another company policy', function () {
    policy_controller_database();

    $response = policy_controller()->deleteRecord('ignored', Request::create('/int/v1/policies/policy-other-company', 'DELETE'));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Unable to find policy for deletion.'],
        ])
        ->and(Policy::where('id', 'policy-other-company')->exists())->toBeTrue();
});

test('policy controller returns validation and query errors from create and update without generic masking', function () {
    policy_controller_database();

    $validationPolicy            = new PolicyControllerFailingPolicy();
    $validationPolicy->throwable = new FleetbaseRequestValidationException(['policy.name' => ['The policy name is required.']]);
    $queryPolicy                 = new PolicyControllerFailingPolicy();
    $queryPolicy->throwable      = new QueryException('mysql', 'select * from policies', [], new RuntimeException('database unavailable'));
    $genericPolicy               = new PolicyControllerFailingPolicy();
    $genericPolicy->throwable    = new RuntimeException('sync failed');

    $validationController = policy_controller_with_model($validationPolicy);
    $queryController      = policy_controller_with_model($queryPolicy);
    $genericController    = policy_controller_with_model($genericPolicy);

    $createValidation = $validationController->createRecord(policy_controller_request('POST', '/int/v1/policies', []));
    $updateValidation = $validationController->updateRecord(policy_controller_request('PATCH', '/int/v1/policies/policy-company', []), 'policy-company');
    $createQuery      = $queryController->createRecord(policy_controller_request('POST', '/int/v1/policies', []));
    $updateQuery      = $queryController->updateRecord(policy_controller_request('PATCH', '/int/v1/policies/policy-company', []), 'policy-company');
    $createGeneric    = $genericController->createRecord(policy_controller_request('POST', '/int/v1/policies', []));
    $updateGeneric    = $genericController->updateRecord(policy_controller_request('PATCH', '/int/v1/policies/policy-company', []), 'policy-company');

    expect($createValidation)->toBeInstanceOf(JsonResponse::class)
        ->and($createValidation->getStatusCode())->toBe(400)
        ->and($createValidation->getData(true))->toBe([
            'errors' => [
                'policy.name' => ['The policy name is required.'],
            ],
        ])
        ->and($updateValidation->getData(true))->toBe($createValidation->getData(true))
        ->and($createQuery->getStatusCode())->toBe(400)
        ->and($createQuery->getData(true)['errors'][0])->toContain('database unavailable')
        ->and($updateQuery->getData(true)['errors'][0])->toContain('database unavailable')
        ->and($createGeneric->getStatusCode())->toBe(400)
        ->and($createGeneric->getData(true))->toBe(['errors' => ['sync failed']])
        ->and($updateGeneric->getStatusCode())->toBe(400)
        ->and($updateGeneric->getData(true))->toBe(['errors' => ['sync failed']]);
});
