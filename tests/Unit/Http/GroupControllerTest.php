<?php

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Exports\GroupExport;
use Fleetbase\Http\Controllers\Internal\v1\GroupController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\Group;
use Fleetbase\Models\GroupUser;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class GroupControllerCacheFake
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

class GroupControllerErrorModel extends Group
{
    public ?Throwable $createThrowable = null;
    public ?Throwable $updateThrowable = null;

    public function __construct(private string $operation = 'update')
    {
        parent::__construct();
    }

    public function createRecordFromRequest($request, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        if ($this->createThrowable) {
            throw $this->createThrowable;
        }

        throw new RuntimeException('Unable to create group.');
    }

    public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        if ($this->updateThrowable) {
            throw $this->updateThrowable;
        }

        throw new RuntimeException("Unable to {$this->operation} group {$id}.");
    }
}

class GroupControllerExcelFake
{
    public ?object $export   = null;
    public ?string $filename = null;

    public function download(object $export, string $filename): Response
    {
        $this->export   = $export;
        $this->filename = $filename;

        return new Response('group export');
    }
}

class GroupControllerPermissionRegistrarFake
{
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';

    public function forgetWildcardPermissionIndex(mixed $record = null): void
    {
    }
}

function group_controller_container(array $config = []): Container
{
    $container = bind_test_container(array_merge([
        'auth.defaults.guard'                           => 'sanctum',
        'permission.models.permission'                  => Fleetbase\Models\Permission::class,
        'permission.models.role'                        => Fleetbase\Models\Role::class,
        'permission.table_names.permissions'            => 'permissions',
        'permission.table_names.policies'               => 'policies',
        'permission.table_names.roles'                  => 'roles',
        'permission.table_names.model_has_permissions'  => 'model_has_permissions',
        'permission.table_names.role_has_permissions'   => 'role_has_permissions',
        'permission.column_names.model_morph_key'       => 'model_uuid',
    ], $config));

    $cache = new GroupControllerCacheFake();
    $container->instance('cache', $cache);
    $container->instance('responsecache', $cache);
    $container->instance(PermissionRegistrar::class, new GroupControllerPermissionRegistrarFake());
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');

    return $container;
}

function group_controller_boot_request_macros(): void
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

    if (!Request::hasMacro('getController')) {
        Request::macro('getController', function () {
            return $this->route()?->controller;
        });
    }
}

function group_controller_database(): Capsule
{
    group_controller_boot_request_macros();
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = group_controller_container([
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
    session(['company' => 'company-1', 'user' => 'user-admin']);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('groups', function ($table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name');
        $table->string('description')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('group_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable()->index();
        $table->string('group_uuid')->nullable()->index();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('email')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
    });
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
    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type');
        $table->string('model_uuid');
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
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-1', 'email' => 'ada@example.com', 'name' => 'Ada', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-2', 'email' => 'grace@example.com', 'name' => 'Grace', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-3', 'email' => 'katherine@example.com', 'name' => 'Katherine', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-other', 'email' => 'other@example.com', 'name' => 'Other', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('groups')->insert([
        ['uuid' => 'group-existing', '_key' => null, 'public_id' => 'group_existing', 'company_uuid' => 'company-1', 'name' => 'Existing Dispatch', 'description' => null, 'slug' => 'existing-dispatch', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'group-other', '_key' => null, 'public_id' => 'group_other', 'company_uuid' => 'company-1', 'name' => 'Other Group', 'description' => null, 'slug' => 'other-group', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('group_users')->insert([
        ['uuid' => 'membership-1', '_key' => null, 'company_uuid' => 'company-1', 'group_uuid' => 'group-existing', 'user_uuid' => 'user-1', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'membership-2', '_key' => null, 'company_uuid' => 'company-1', 'group_uuid' => 'group-existing', 'user_uuid' => 'user-2', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'membership-other', '_key' => null, 'company_uuid' => 'company-1', 'group_uuid' => 'group-other', 'user_uuid' => 'user-other', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function group_controller(): GroupController
{
    $_SERVER['REQUEST_METHOD'] ??= 'GET';

    return new GroupController(new Group(), FleetbaseResource::class);
}

function group_controller_request(string $method, string $uri, array $parameters = []): Request
{
    $_SERVER['REQUEST_METHOD'] = $method;

    $request = Request::create($uri, $method, $parameters);
    $route   = new Route($method, $uri, [
        'controller' => GroupController::class . '@' . (in_array($method, ['PATCH', 'PUT'], true) ? 'updateRecord' : 'createRecord'),
    ]);
    $route->controller = group_controller();
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function group_members(Capsule $capsule, string $groupUuid): array
{
    return $capsule->getConnection('mysql')
        ->table('group_users')
        ->whereNull('deleted_at')
        ->where('group_uuid', $groupUuid)
        ->pluck('user_uuid')
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

test('group controller creates a company scoped group and attaches requested users', function () {
    $capsule = group_controller_database();

    $result = group_controller()->createRecord(group_controller_request('POST', '/int/v1/groups', [
        'group' => [
            'name'        => 'Dispatch Supervisors',
            'description' => 'Users who can coordinate dispatch operations',
            'users'       => ['user-1', 'user-3'],
        ],
    ]));

    expect($result)->toBeArray()
        ->and($result['group'])->toBeInstanceOf(FleetbaseResource::class)
        ->and($result['group']->resource)->toBeInstanceOf(Group::class)
        ->and($result['group']->resource->company_uuid)->toBe('company-1')
        ->and($result['group']->resource->name)->toBe('Dispatch Supervisors')
        ->and($result['group']->resource->users->pluck('uuid')->sort()->values()->all())->toBe(['user-1', 'user-3']);

    expect(group_members($capsule, $result['group']->resource->uuid))->toBe(['user-1', 'user-3']);
});

test('group controller updates only the target group membership', function () {
    $capsule = group_controller_database();

    $result = group_controller()->updateRecord(group_controller_request('PATCH', '/int/v1/groups/group-existing', [
        'group' => [
            'name'  => 'Updated Dispatch',
            'users' => ['user-2', 'user-3'],
        ],
    ]), 'group-existing');

    expect($result)->toBeArray()
        ->and($result['group'])->toBeInstanceOf(FleetbaseResource::class)
        ->and($result['group']->resource->uuid)->toBe('group-existing')
        ->and($result['group']->resource->name)->toBe('Updated Dispatch')
        ->and(group_members($capsule, 'group-existing'))->toBe(['user-2', 'user-3'])
        ->and(group_members($capsule, 'group-other'))->toBe(['user-other']);

    expect(GroupUser::withTrashed()->where('uuid', 'membership-1')->first()->trashed())->toBeTrue()
        ->and(GroupUser::where('uuid', 'membership-other')->exists())->toBeTrue();
});

test('group controller returns stable error responses when create or update fails', function () {
    group_controller_container(['app.debug' => true]);

    $createController        = group_controller();
    $createController->model = new GroupControllerErrorModel('create');
    $updateController        = group_controller();
    $updateController->model = new GroupControllerErrorModel('update');

    $createResponse = $createController->createRecord(group_controller_request('POST', '/int/v1/groups', [
        'group' => [
            'name'  => 'Broken Group',
            'users' => [],
        ],
    ]));

    $updateResponse = $updateController->updateRecord(group_controller_request('PATCH', '/int/v1/groups/group-existing', [
        'group' => [
            'name'  => 'Broken Group',
            'users' => [],
        ],
    ]), 'group-existing');

    expect($createResponse->getStatusCode())->toBe(400)
        ->and($createResponse->getData(true))->toBe(['errors' => ['Unable to create group.']])
        ->and($updateResponse->getStatusCode())->toBe(400)
        ->and($updateResponse->getData(true))->toBe(['errors' => ['Unable to update group group-existing.']]);
});

test('group controller returns validation and query errors from create and update without generic masking', function () {
    group_controller_container(['app.debug' => true]);

    $validationModel                  = new GroupControllerErrorModel();
    $validationModel->createThrowable = new FleetbaseRequestValidationException(['group.name' => ['The group name is required.']]);
    $validationModel->updateThrowable = new FleetbaseRequestValidationException(['group.name' => ['The group name is required.']]);

    $queryModel                  = new GroupControllerErrorModel();
    $queryModel->createThrowable = new QueryException('mysql', 'insert into groups', [], new RuntimeException('database unavailable'));
    $queryModel->updateThrowable = new QueryException('mysql', 'update groups', [], new RuntimeException('database unavailable'));

    $validationController        = group_controller();
    $validationController->model = $validationModel;
    $queryController             = group_controller();
    $queryController->model      = $queryModel;

    $createValidation = $validationController->createRecord(group_controller_request('POST', '/int/v1/groups', [
        'group' => [
            'name'  => '',
            'users' => [],
        ],
    ]));
    $updateValidation = $validationController->updateRecord(group_controller_request('PATCH', '/int/v1/groups/group-existing', [
        'group' => [
            'name'  => '',
            'users' => [],
        ],
    ]), 'group-existing');
    $createQuery = $queryController->createRecord(group_controller_request('POST', '/int/v1/groups', [
        'group' => [
            'name'  => 'Broken Group',
            'users' => [],
        ],
    ]));
    $updateQuery = $queryController->updateRecord(group_controller_request('PATCH', '/int/v1/groups/group-existing', [
        'group' => [
            'name'  => 'Broken Group',
            'users' => [],
        ],
    ]), 'group-existing');

    expect($createValidation->getStatusCode())->toBe(400)
        ->and($createValidation->getData(true))->toBe([
            'errors' => [
                'group.name' => ['The group name is required.'],
            ],
        ])
        ->and($updateValidation->getData(true))->toBe($createValidation->getData(true))
        ->and($createQuery->getStatusCode())->toBe(400)
        ->and($createQuery->getData(true)['errors'][0])->toContain('database unavailable')
        ->and($updateQuery->getStatusCode())->toBe(400)
        ->and($updateQuery->getData(true)['errors'][0])->toContain('database unavailable');
});

test('group controller export downloads group exports with requested format', function () {
    group_controller_container();

    $excel = new GroupControllerExcelFake();
    app()->instance('excel', $excel);
    Facade::clearResolvedInstance('excel');

    $response = GroupController::export(ExportRequest::create('/int/v1/groups/export', 'GET', [
        'format' => 'csv',
    ]));

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getContent())->toBe('group export')
        ->and($excel->export)->toBeInstanceOf(GroupExport::class)
        ->and($excel->filename)->toStartWith('groups-')
        ->and($excel->filename)->toEndWith('.csv');
});
