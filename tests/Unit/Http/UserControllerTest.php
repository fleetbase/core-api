<?php

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Exports\UserExport;
use Fleetbase\Http\Controllers\Internal\v1\UserController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\AcceptCompanyInvite;
use Fleetbase\Http\Requests\Internal\ChangeCurrentUserEmailRequest;
use Fleetbase\Http\Requests\Internal\ChangeUserEmailRequest;
use Fleetbase\Http\Requests\Internal\InviteUserRequest;
use Fleetbase\Http\Requests\Internal\ResendUserInvite;
use Fleetbase\Http\Requests\Internal\UpdatePasswordRequest;
use Fleetbase\Http\Requests\Internal\ValidatePasswordRequest;
use Fleetbase\Models\Role;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 3);

        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
    }
}

if (!function_exists('Fleetbase\Http\Controllers\Internal\v1\event')) {
    eval('namespace Fleetbase\\Http\\Controllers\\Internal\\v1; function event($event = null) { return $event; }');
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

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->values[$key] ??= $callback();
    }

    public function tags(array|string $names): self
    {
        return $this;
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

class UserControllerPermissionRegistrarFake
{
    public string $pivotRole       = 'role_id';
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';

    public function getRoleClass(): string
    {
        return Role::class;
    }

    public function getPermissionClass(): string
    {
        return Fleetbase\Models\Permission::class;
    }

    public function setPermissionClass(string $permissionClass): self
    {
        return $this;
    }

    public function getPermissions(array $params = [], bool $onlyOne = false): Illuminate\Database\Eloquent\Collection
    {
        $query = Fleetbase\Models\Permission::query();

        foreach ($params as $field => $value) {
            $query->where($field, $value);
        }

        return $query->get();
    }

    public function forgetWildcardPermissionIndex(mixed $record = null): void
    {
    }

    public function forgetCachedPermissions(): void
    {
    }
}

class UserControllerNotificationDispatcherFake implements Illuminate\Contracts\Notifications\Dispatcher
{
    public array $sent = [];

    public function send($notifiables, $notification): void
    {
        $this->sent[] = [$notifiables, $notification, null];
    }

    public function sendNow($notifiables, $notification, ?array $channels = null): void
    {
        $this->sent[] = [$notifiables, $notification, $channels];
    }
}

class UserControllerExcelFake
{
    public ?object $export   = null;
    public ?string $filename = null;

    public function download(object $export, string $filename): Response
    {
        $this->export   = $export;
        $this->filename = $filename;

        return new Response('user export');
    }
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
            'namespace'  => 'Fleetbase\\Http\\Controllers\\Internal\\v1',
        ];

        return $key ? $action[$key] ?? null : $action;
    }

    public function uri(): string
    {
        return 'int/v1/users';
    }

    public function getActionMethod(): string
    {
        return $this->method;
    }
}

class UserControllerWithoutRequestValidation extends UserController
{
    public $createRequest;

    public $updateRequest;

    public function validateRequest(Request $request): void
    {
    }
}

class UserControllerThrowingModel
{
    public function __construct(private Throwable $exception)
    {
    }

    public function createRecordFromRequest(Request $request, ?callable $onBefore = null, ?callable $onAfter = null, array $options = []): never
    {
        throw $this->exception;
    }

    public function getApiPayloadFromRequest(Request $request): array
    {
        throw $this->exception;
    }

    public function withCounts(Request $request, mixed $query): mixed
    {
        return $query;
    }

    public function withRelationships(Request $request, mixed $query): mixed
    {
        return $query;
    }

    public function applyDirectivesToQuery(Request $request, mixed $query): mixed
    {
        return $query;
    }

    public function getSingularName(): string
    {
        return 'user';
    }
}

class UserControllerInvalidParamModel
{
    public function getApiPayloadFromRequest(Request $request): array
    {
        return ['created_at' => '2026-07-18 10:00:00'];
    }

    public function fillSessionAttributes(?array $target = [], array $except = [], array $only = []): array
    {
        return $target ?? [];
    }

    public function isColumn(string $key): bool
    {
        return false;
    }

    public function isInvalidUpdateParam(string $key): bool
    {
        return $key === 'created_at';
    }

    public function withCounts(Request $request, mixed $query): mixed
    {
        return $query;
    }

    public function withRelationships(Request $request, mixed $query): mixed
    {
        return $query;
    }

    public function applyDirectivesToQuery(Request $request, mixed $query): mixed
    {
        return $query;
    }

    public function getSingularName(): string
    {
        return 'user';
    }
}

class UserControllerFillSessionFailureModel extends UserControllerInvalidParamModel
{
    public function __construct(private Throwable $exception)
    {
    }

    public function getApiPayloadFromRequest(Request $request): array
    {
        return ['name' => 'Changed Name'];
    }

    public function fillSessionAttributes(?array $target = [], array $except = [], array $only = []): array
    {
        throw $this->exception;
    }
}

class UserControllerCamelPayloadModel extends UserControllerInvalidParamModel
{
    public function getApiPayloadFromRequest(Request $request): array
    {
        return [
            'email' => 'MEMBER@example.test',
            'name'  => 'Camel Payload',
        ];
    }

    public function getSingularName(): string
    {
        return 'user-profile';
    }
}

function user_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    EloquentModel::unsetEventDispatcher();
    Request::flushMacros();
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
        'permission.models.role'                       => Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'activitylog.enabled'                          => false,
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

    if (!Request::hasMacro('isArray')) {
        Request::macro('isArray', function (string $key): bool {
            return is_array($this->input($key));
        });
    }

    if (!Request::hasMacro('getController')) {
        Request::macro('getController', function (): mixed {
            return $this->route()?->controller;
        });
    }

    EloquentBuilder::macro('fastPaginate', function (int $perPage = 15, array $columns = ['*']) {
        return $this->limit($perPage)->get($columns);
    });

    $container->instance('hash', new UserControllerHashFake());
    $container->instance('cache', new UserControllerCacheFake());
    $container->instance(Illuminate\Contracts\Config\Repository::class, $container->make('config'));
    $container->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    $container->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new UserControllerNotificationDispatcherFake());
    $container->instance(Spatie\Permission\PermissionRegistrar::class, new UserControllerPermissionRegistrarFake());
    Facade::clearResolvedInstance('hash');
    Facade::clearResolvedInstance('cache');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getConnection('mysql')->getPdo()->sqliteCreateFunction('JSON_CONTAINS', function (?string $json, ?string $needle): int {
        $values = json_decode((string) $json, true) ?: [];
        $needle = json_decode((string) $needle, true) ?? trim((string) $needle, '"');

        return in_array($needle, $values, true) ? 1 : 0;
    }, 2);
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
        $table->string('ip_address')->nullable();
        $table->string('slug')->nullable();
        $table->string('name')->nullable();
        $table->string('password')->nullable();
        $table->string('remember_token')->nullable();
        $table->string('secret')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('timezone')->nullable();
        $table->string('country')->nullable();
        $table->text('meta')->nullable();
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
    $schema->create('invites', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('_key')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('created_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('uri')->nullable();
        $table->string('code')->nullable();
        $table->string('protocol')->nullable();
        $table->text('recipients')->nullable();
        $table->string('reason')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('personal_access_tokens', function ($table) {
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
    $capsule->getConnection('mysql')->table('roles')->insert([
        ['id' => 'Administrator', 'company_uuid' => null, 'name' => 'Administrator', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function user_controller(): UserController
{
    return new UserController();
}

function user_controller_without_request_validation(): UserControllerWithoutRequestValidation
{
    return new UserControllerWithoutRequestValidation();
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

function user_controller_assert_created_user_response(mixed $response): object|array
{
    if ($response instanceof JsonResponse) {
        $payload = $response->getData(true);

        Assert::assertArrayHasKey(
            'user',
            $payload,
            'Expected user creation to return a user payload, got JSON response: ' . json_encode($payload)
        );

        return $payload['user'];
    }

    Assert::assertIsArray($response, 'Expected user creation to return an array payload.');
    Assert::assertArrayHasKey('user', $response);

    return $response['user'];
}

afterEach(function () {
    session()->flush();
    Carbon::setTestNow();
    Illuminate\Http\Resources\Json\JsonResource::wrap('data');
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

test('user controller restores sandbox connection settings after generic user queries', function () {
    user_controller_database();
    config([
        'database.default'             => 'sandbox',
        'database.connections.sandbox' => config('database.connections.mysql'),
        'fleetbase.connection.db'      => 'sandbox',
    ]);

    $response = user_controller()->queryRecord(user_controller_request('GET', [], user_controller_user('owner-1'), 'queryRecord'));

    expect(config('database.default'))->toBe('sandbox')
        ->and(config('fleetbase.connection.db'))->toBe('sandbox')
        ->and($response)->toHaveProperty('collection')
        ->and($response->collection->pluck('uuid')->sort()->values()->all())->toBe(['member-1', 'owner-1', 'single-1']);
});

test('user controller search and export endpoints expose compact response and download contracts', function () {
    user_controller_database();

    $searchResponse = user_controller()->searchRecords(user_controller_request('GET', [
        'query' => 'example.test',
    ], user_controller_user('owner-1'), 'searchRecords'));
    $searchPayload = $searchResponse->getData(true);

    $excel = new UserControllerExcelFake();
    app()->instance('excel', $excel);
    Facade::clearResolvedInstance('excel');

    $exportResponse = user_controller()->export(ExportRequest::create('/int/v1/users/export', 'GET', [
        'format'     => 'csv',
        'selections' => ['member-1', 'foreign-1'],
    ]));

    expect($searchResponse->getStatusCode())->toBe(200)
        ->and($searchPayload)->toHaveCount(5)
        ->and($searchPayload[0])->toHaveKeys(['uuid', 'name'])
        ->and($searchPayload[0])->not->toHaveKeys(['email', 'phone', 'password'])
        ->and(collect($searchPayload)->pluck('name')->all())->toContain('Owner One', 'Foreign User')
        ->and($exportResponse)->toBeInstanceOf(Response::class)
        ->and($exportResponse->getContent())->toBe('user export')
        ->and($excel->export)->toBeInstanceOf(UserExport::class)
        ->and($excel->export->collection()->pluck('uuid')->all())->toBe(['member-1'])
        ->and($excel->filename)->toMatch('/^users-\d{4}-\d{2}-\d{2}-\d{4}\.csv$/')
        ->and($excel->filename)->toEndWith('.csv');
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

test('user controller visible user resolution requires company session for organization scoped callers', function () {
    user_controller_database();

    session()->flush();

    $resolver = new ReflectionMethod(UserController::class, 'resolveVisibleUser');
    $resolver->setAccessible(true);

    $resolved = $resolver->invoke(
        user_controller(),
        'user_member_1',
        user_controller_request('GET', [], user_controller_user('owner-1'), 'findRecord')
    );

    expect($resolved)->toBeNull();
});

test('user controller creates users through the generic record endpoint with scoped assignments', function () {
    $capsule = user_controller_database();
    EloquentModel::setEventDispatcher(new Dispatcher(app()));

    $db = $capsule->getConnection('mysql');
    $db->table('roles')->insert([
        'id'           => 'Dispatcher',
        'company_uuid' => 'company-1',
        'name'         => 'Dispatcher',
        'guard_name'   => 'sanctum',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);
    $db->table('permissions')->insert([
        'id'          => 'permission-create-user',
        'name'        => 'iam create user',
        'guard_name'  => 'sanctum',
        'description' => 'Create users',
        'created_at'  => '2026-07-18 10:00:00',
        'updated_at'  => '2026-07-18 10:00:00',
    ]);
    $db->table('policies')->insert([
        'id'           => 'policy-create-user',
        'company_uuid' => 'company-1',
        'name'         => 'Create user policy',
        'guard_name'   => 'sanctum',
        'service'      => 'iam',
        'description'  => 'Create users',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);

    $created = user_controller_without_request_validation()->createRecord(user_controller_request('POST', [
        'user' => [
            'email'       => 'new-person@fleetbase.io',
            'name'        => 'New Person',
            'phone'       => '+15551234567',
            'role_uuid'   => 'Dispatcher',
            'permissions' => ['permission-create-user'],
            'policies'    => ['policy-create-user'],
            'timezone'    => 'Asia/Ulaanbaatar',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    $createdUser = User::where('email', 'new-person@fleetbase.io')->first();
    $companyUser = $db->table('company_users')->where('company_uuid', 'company-1')->where('user_uuid', $createdUser?->uuid)->first();

    $createdUserPayload  = user_controller_assert_created_user_response($created);
    $createdUserResource = is_array($createdUserPayload) ? (object) $createdUserPayload : $createdUserPayload->resource;

    expect($createdUserResource->email)->toBe('new-person@fleetbase.io')
        ->and($createdUserResource->company_uuid)->toBe('company-1')
        ->and($createdUserResource->timezone)->toBe('Asia/Ulaanbaatar')
        ->and($createdUserResource->type)->toBe('user')
        ->and($companyUser)->not->toBeNull()
        ->and($db->table('model_has_roles')->where('model_type', Fleetbase\Models\CompanyUser::class)->where('model_uuid', $companyUser->uuid)->where('role_id', 'Dispatcher')->exists())->toBeTrue()
        ->and($db->table('model_has_permissions')->where('model_type', Fleetbase\Models\CompanyUser::class)->where('model_uuid', $companyUser->uuid)->where('permission_id', 'permission-create-user')->exists())->toBeTrue()
        ->and($db->table('model_has_policies')->where('model_type', Fleetbase\Models\CompanyUser::class)->where('model_uuid', $companyUser->uuid)->where('policy_id', 'policy-create-user')->exists())->toBeTrue();
});

test('user controller create record rejects duplicate active-company members and unavailable roles', function () {
    user_controller_database();

    $duplicateMember = user_controller_without_request_validation()->createRecord(user_controller_request('POST', [
        'user' => [
            'email' => 'member@example.test',
            'name'  => 'Member One',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));
    $invalidRole = user_controller_without_request_validation()->createRecord(user_controller_request('POST', [
        'user' => [
            'email'     => 'another-person@fleetbase.io',
            'name'      => 'Another Person',
            'role_uuid' => 'role-other-company',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    expect($duplicateMember->getStatusCode())->toBe(400)
        ->and($duplicateMember->getData(true))->toBe(['errors' => ['This user is already a member of your organisation.']])
        ->and($invalidRole->getStatusCode())->toBe(404)
        ->and($invalidRole->getData(true))->toBe(['errors' => ['The selected role is not available for this organisation.']]);
});

test('user controller create record invites existing users from another organization', function () {
    $capsule = user_controller_database();
    EloquentModel::setEventDispatcher(new Dispatcher(app()));

    $invite = user_controller_without_request_validation()->createRecord(user_controller_request('POST', [
        'user' => [
            'email'     => 'foreign@example.test',
            'role_uuid' => 'Administrator',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    expect($invite->getStatusCode())->toBe(200)
        ->and($invite->getData(true)['invited'])->toBeTrue()
        ->and($invite->getData(true)['user']['uuid'])->toBe('foreign-1')
        ->and(User::where('email', 'foreign@example.test')->count())->toBe(1)
        ->and($capsule->getConnection('mysql')->table('company_users')->where('company_uuid', 'company-1')->where('user_uuid', 'foreign-1')->exists())->toBeFalse()
        ->and($capsule->getConnection('mysql')->table('invites')->where('company_uuid', 'company-1')->where('reason', 'join_company')->count())->toBe(1);
});

test('user controller create record reports existing-user invite precondition failures', function () {
    user_controller_database();

    session()->flush();
    $missingCompany = user_controller_without_request_validation()->createRecord(user_controller_request('POST', [
        'user' => [
            'email' => 'foreign@example.test',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    session(['company' => 'company-1', 'user' => 'owner-1']);
    $invalidRole = user_controller_without_request_validation()->createRecord(user_controller_request('POST', [
        'user' => [
            'email' => 'foreign@example.test',
            'role'  => 'missing-role',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    expect($missingCompany->getStatusCode())->toBe(400)
        ->and($missingCompany->getData(true))->toBe(['errors' => ['Unable to determine the current organisation.']])
        ->and($invalidRole->getStatusCode())->toBe(404)
        ->and($invalidRole->getData(true))->toBe(['errors' => ['The selected role is not available for this organisation.']]);
});

test('user controller role resolution accepts only global and active-company role instances', function () {
    $capsule = user_controller_database();
    $db      = $capsule->getConnection('mysql');
    $db->table('roles')->insert([
        ['id' => 'CompanyOneOperator', 'company_uuid' => 'company-1', 'name' => 'Company One Operator', 'guard_name' => 'sanctum', 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00'],
        ['id' => 'CompanyTwoOperator', 'company_uuid' => 'company-2', 'name' => 'Company Two Operator', 'guard_name' => 'sanctum', 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00'],
    ]);

    $resolver = new ReflectionMethod(UserController::class, 'resolveAssignableRole');
    $resolver->setAccessible(true);

    expect($resolver->invoke(user_controller(), Role::find('Administrator'))?->id)->toBe('Administrator')
        ->and($resolver->invoke(user_controller(), Role::find('CompanyOneOperator'))?->id)->toBe('CompanyOneOperator')
        ->and($resolver->invoke(user_controller(), Role::find('CompanyTwoOperator')))->toBeNull()
        ->and($resolver->invoke(user_controller(), null))->toBeNull();
});

test('user controller strips unchanged identity fields from flat update payloads and compares timestamp identities', function () {
    user_controller_database();

    $request = user_controller_request('PATCH', [
        'email' => 'MEMBER@example.test',
        'name'  => 'Member Renamed',
    ], user_controller_user('owner-1'), 'updateRecord');
    $stripper = new ReflectionMethod(UserController::class, 'stripUnchangedIdentityFields');
    $stripper->setAccessible(true);
    $identityMatcher = new ReflectionMethod(UserController::class, 'identityValueMatches');
    $identityMatcher->setAccessible(true);
    $controller = user_controller();

    expect($stripper->invoke($controller, $request, user_controller_user('member-1')))->toBeTrue()
        ->and($request->all())->toBe(['name' => 'Member Renamed'])
        ->and($identityMatcher->invoke($controller, 'phone_verified_at', null, null))->toBeTrue()
        ->and($identityMatcher->invoke($controller, 'phone_verified_at', null, '2026-07-18 10:00:00'))->toBeFalse();

    $camelRequest = user_controller_request('PATCH', [
        'userProfile' => [
            'email' => 'MEMBER@example.test',
            'name'  => 'Camel Payload',
        ],
    ], user_controller_user('owner-1'), 'updateRecord');
    $camelController        = user_controller();
    $camelController->model = new UserControllerCamelPayloadModel();

    expect($stripper->invoke($camelController, $camelRequest, user_controller_user('member-1')))->toBeTrue()
        ->and($camelRequest->all())->toBe([
            'userProfile' => [
                'name' => 'Camel Payload',
            ],
        ]);
});

test('user controller updates mutable user fields while preserving unchanged identity fields', function () {
    $capsule = user_controller_database();
    $db      = $capsule->getConnection('mysql');
    $db->table('users')->where('uuid', 'member-1')->update([
        'email_verified_at' => '2026-07-18 10:00:00',
        'remember_token'    => 'existing-token',
    ]);
    $db->table('roles')->insert([
        'id'           => 'Operator',
        'company_uuid' => 'company-1',
        'name'         => 'Operator',
        'guard_name'   => 'sanctum',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);
    $db->table('permissions')->insert([
        'id'          => 'permission-update-user',
        'name'        => 'iam update user',
        'guard_name'  => 'sanctum',
        'description' => 'Update users',
        'created_at'  => '2026-07-18 10:00:00',
        'updated_at'  => '2026-07-18 10:00:00',
    ]);
    $db->table('policies')->insert([
        'id'           => 'policy-update-user',
        'company_uuid' => 'company-1',
        'name'         => 'Update user policy',
        'guard_name'   => 'sanctum',
        'service'      => 'iam',
        'description'  => 'Update users',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);

    $updated = user_controller_without_request_validation()->updateRecord(user_controller_request('PATCH', [
        'user' => [
            'email'             => 'MEMBER@example.test',
            'email_verified_at' => '2026-07-18T10:00:00+00:00',
            'remember_token'    => 'existing-token',
            'name'              => 'Member Updated',
            'phone'             => '+15557654321',
            'slug'              => 'should-not-persist',
            'role'              => 'Operator',
            'permissions'       => ['permission-update-user'],
            'policies'          => ['policy-update-user'],
        ],
    ], user_controller_user('owner-1'), 'updateRecord'), 'user_member_1');

    $member      = User::where('uuid', 'member-1')->first();
    $companyUser = $db->table('company_users')->where('company_uuid', 'company-1')->where('user_uuid', 'member-1')->first();

    expect($updated['user']->resource->uuid)->toBe('member-1')
        ->and($member->name)->toBe('Member Updated')
        ->and($member->phone)->toBe('+15557654321')
        ->and($member->email)->toBe('member@example.test')
        ->and($member->slug)->not->toBe('should-not-persist')
        ->and($db->table('model_has_roles')->where('model_type', Fleetbase\Models\CompanyUser::class)->where('model_uuid', $companyUser->uuid)->where('role_id', 'Operator')->exists())->toBeTrue()
        ->and($db->table('model_has_permissions')->where('model_type', Fleetbase\Models\CompanyUser::class)->where('model_uuid', $companyUser->uuid)->where('permission_id', 'permission-update-user')->exists())->toBeTrue()
        ->and($db->table('model_has_policies')->where('model_type', Fleetbase\Models\CompanyUser::class)->where('model_uuid', $companyUser->uuid)->where('policy_id', 'policy-update-user')->exists())->toBeTrue();
});

test('user controller rejects update edge cases before mutating scoped users', function () {
    $capsule = user_controller_database();
    $db      = $capsule->getConnection('mysql');
    $db->table('users')->where('uuid', 'member-1')->update([
        'email_verified_at' => '2026-07-18 10:00:00',
    ]);

    $missingUser = user_controller_without_request_validation()->updateRecord(user_controller_request('PATCH', [
        'user' => ['name' => 'Foreign Update'],
    ], user_controller_user('owner-1'), 'updateRecord'), 'user_foreign_1');
    $invalidDateIdentity = user_controller_without_request_validation()->updateRecord(user_controller_request('PATCH', [
        'user' => [
            'email_verified_at' => 'not-a-date',
            'name'              => 'Should Not Change',
        ],
    ], user_controller_user('owner-1'), 'updateRecord'), 'member-1');
    $invalidRole = user_controller_without_request_validation()->updateRecord(user_controller_request('PATCH', [
        'user' => [
            'name' => 'Should Not Change',
            'role' => 'missing-role',
        ],
    ], user_controller_user('owner-1'), 'updateRecord'), 'member-1');
    expect($missingUser->getStatusCode())->toBe(404)
        ->and($missingUser->getData(true))->toBe(['errors' => ['User not found.']])
        ->and($invalidDateIdentity->getStatusCode())->toBe(422)
        ->and($invalidDateIdentity->getData(true))->toBe(['errors' => ['Login identity fields cannot be updated from this endpoint.']])
        ->and($invalidRole->getStatusCode())->toBe(404)
        ->and($invalidRole->getData(true))->toBe(['errors' => ['The selected role is not available for this organisation.']])
        ->and($db->table('users')->where('uuid', 'member-1')->value('name'))->toBe('Member One');
});

test('user controller formats create and update exception responses by exception type', function () {
    user_controller_database();

    $queryException = new Illuminate\Database\QueryException(
        'mysql',
        'insert into users',
        [],
        new RuntimeException('database failure')
    );

    $createDatabaseFailure        = user_controller_without_request_validation();
    $createDatabaseFailure->model = new UserControllerThrowingModel($queryException);
    $createDatabaseResponse       = $createDatabaseFailure->createRecord(user_controller_request('POST', [
        'user' => [
            'email' => 'database-failure@example.test',
            'name'  => 'Database Failure',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    $createValidationFailure        = user_controller_without_request_validation();
    $createValidationFailure->model = new UserControllerThrowingModel(new FleetbaseRequestValidationException([
        'Email must be unique.',
    ]));
    $createValidationResponse       = $createValidationFailure->createRecord(user_controller_request('POST', [
        'user' => [
            'email' => 'validation-failure@example.test',
            'name'  => 'Validation Failure',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    $createGenericFailure        = user_controller_without_request_validation();
    $createGenericFailure->model = new UserControllerThrowingModel(new RuntimeException('generic create failure'));
    $createGenericResponse       = $createGenericFailure->createRecord(user_controller_request('POST', [
        'user' => [
            'email' => 'generic-failure@example.test',
            'name'  => 'Generic Failure',
        ],
    ], user_controller_user('owner-1'), 'createRecord'));

    $updateDatabaseFailure        = user_controller_without_request_validation();
    $updateDatabaseFailure->model = new UserControllerFillSessionFailureModel($queryException);
    $updateDatabaseResponse       = $updateDatabaseFailure->updateRecord(user_controller_request('PATCH', [
        'user' => [
            'name' => 'Database Failure',
        ],
    ], user_controller_user('owner-1'), 'updateRecord'), 'member-1');

    $updateValidationFailure        = user_controller_without_request_validation();
    $updateValidationFailure->model = new UserControllerFillSessionFailureModel(new FleetbaseRequestValidationException([
        'User payload is invalid.',
    ]));
    $updateValidationResponse       = $updateValidationFailure->updateRecord(user_controller_request('PATCH', [
        'user' => [
            'name' => 'Validation Failure',
        ],
    ], user_controller_user('owner-1'), 'updateRecord'), 'member-1');

    $invalidParam         = user_controller_without_request_validation();
    $invalidParam->model  = new UserControllerInvalidParamModel();
    $invalidParamResponse = $invalidParam->updateRecord(user_controller_request('PATCH', [
        'user' => [
            'created_at' => '2026-07-18 10:00:00',
        ],
    ], user_controller_user('owner-1'), 'updateRecord'), 'member-1');

    expect($createDatabaseResponse->getStatusCode())->toBe(400)
        ->and($createDatabaseResponse->getData(true)['errors'][0])->toContain('database failure')
        ->and($createValidationResponse->getStatusCode())->toBe(400)
        ->and($createValidationResponse->getData(true))->toBe(['errors' => ['Email must be unique.']])
        ->and($createGenericResponse->getStatusCode())->toBe(400)
        ->and($createGenericResponse->getData(true))->toBe(['errors' => ['generic create failure']])
        ->and($updateDatabaseResponse->getStatusCode())->toBe(400)
        ->and($updateDatabaseResponse->getData(true)['errors'][0])->toContain('database failure')
        ->and($updateValidationResponse->getStatusCode())->toBe(400)
        ->and($updateValidationResponse->getData(true))->toBe(['errors' => ['User payload is invalid.']])
        ->and($invalidParamResponse->getStatusCode())->toBe(400)
        ->and($invalidParamResponse->getData(true))->toBe(['errors' => ['Invalid param "created_at" in update request!']])
        ->and(User::where('uuid', 'member-1')->value('name'))->toBe('Member One');
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
    $capsule->getConnection('mysql')->table('model_has_roles')->insert([
        'role_id'    => 'Administrator',
        'model_type' => Fleetbase\Models\CompanyUser::class,
        'model_uuid' => 'pivot-member-1',
    ]);
    $administratorRoleDeactivate = user_controller()->deactivate('member-1');
    user_controller_request('POST', [], user_controller_user('owner-1'), 'activate');
    $foreignActivate = user_controller()->activate('foreign-1');
    user_controller_request('POST', [], user_controller_user('owner-1'), 'removeFromCompany');
    $singleRemoval = user_controller()->removeFromCompany('single-1');

    expect($selfDeactivate->getStatusCode())->toBe(403)
        ->and($selfDeactivate->getData(true))->toBe(['errors' => ['You cannot deactivate your own account.']])
        ->and($administratorRoleDeactivate->getStatusCode())->toBe(403)
        ->and($administratorRoleDeactivate->getData(true))->toBe(['errors' => ['Insufficient permissions to deactivate this user.']])
        ->and($foreignActivate->getStatusCode())->toBe(404)
        ->and($foreignActivate->getData(true))->toBe(['errors' => ['No user found']])
        ->and($singleRemoval->getStatusCode())->toBe(200)
        ->and($singleRemoval->getData(true))->toBe(['message' => 'User removed'])
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'single-1')->whereNotNull('deleted_at')->exists())->toBeTrue();
});

test('user controller reports activation and removal authorization edge cases without mutating records', function () {
    $capsule = user_controller_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('company_users')->insert([
        'uuid'         => 'pivot-admin-1',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'admin-1',
        'status'       => 'active',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);

    user_controller_request('POST', [], user_controller_user('owner-1'), 'deactivate');
    $missingDeactivateId = user_controller()->deactivate(null);
    $adminDeactivate     = user_controller()->deactivate('admin-1');
    $foreignDeactivate   = user_controller()->deactivate('foreign-1');

    user_controller_request('POST', [], user_controller_user('owner-1'), 'activate');
    $missingActivateId = user_controller()->activate(null);

    user_controller_request('POST', [], user_controller_user('owner-1'), 'verify');
    $missingVerifyId = user_controller()->verify(null);
    $foreignVerify   = user_controller()->verify('foreign-1');

    user_controller_request('POST', [], user_controller_user('owner-1'), 'removeFromCompany');
    $missingRemoveId = user_controller()->removeFromCompany(null);
    $foreignRemove   = user_controller()->removeFromCompany('foreign-1');

    session()->flush();
    session(['user' => 'admin-1']);
    user_controller_request('POST', [], user_controller_user('admin-1'), 'removeFromCompany');
    $missingCompanyRemove = user_controller()->removeFromCompany('member-1');

    expect($missingDeactivateId->getStatusCode())->toBe(401)
        ->and($missingDeactivateId->getData(true))->toBe(['errors' => ['No user to deactivate']])
        ->and($adminDeactivate->getStatusCode())->toBe(403)
        ->and($adminDeactivate->getData(true))->toBe(['errors' => ['Insufficient permissions to deactivate this user.']])
        ->and($foreignDeactivate->getStatusCode())->toBe(404)
        ->and($foreignDeactivate->getData(true))->toBe(['errors' => ['No user found']])
        ->and($missingActivateId->getStatusCode())->toBe(401)
        ->and($missingActivateId->getData(true))->toBe(['errors' => ['No user to activate']])
        ->and($missingVerifyId->getStatusCode())->toBe(401)
        ->and($missingVerifyId->getData(true))->toBe(['errors' => ['No user to activate']])
        ->and($foreignVerify->getStatusCode())->toBe(401)
        ->and($foreignVerify->getData(true))->toBe(['errors' => ['No user found']])
        ->and($missingRemoveId->getStatusCode())->toBe(401)
        ->and($missingRemoveId->getData(true))->toBe(['errors' => ['No user to remove']])
        ->and($foreignRemove->getStatusCode())->toBe(401)
        ->and($foreignRemove->getData(true))->toBe(['errors' => ['No user found']])
        ->and($missingCompanyRemove->getStatusCode())->toBe(401)
        ->and($missingCompanyRemove->getData(true))->toBe(['errors' => ['Unable to remove user from this company']])
        ->and($db->table('company_users')->where('uuid', 'pivot-admin-1')->value('status'))->toBe('active')
        ->and($db->table('company_users')->where('uuid', 'pivot-member-1')->whereNull('deleted_at')->exists())->toBeTrue()
        ->and($db->table('users')->where('uuid', 'member-1')->whereNull('deleted_at')->exists())->toBeTrue();
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

    Illuminate\Support\Facades\DB::connection('mysql')->table('permissions')->insert([
        'id'          => 'permission-change-user-email',
        'name'        => 'iam change-email-for user',
        'guard_name'  => 'sanctum',
        'description' => 'Change user email',
        'created_at'  => '2026-07-18 10:00:00',
        'updated_at'  => '2026-07-18 10:00:00',
    ]);

    session()->flush();
    $missingManagedActor = user_controller()->changeEmail(user_controller_request('POST', [
        'email' => 'managed-new@example.test',
    ], null, 'changeEmail', ChangeUserEmailRequest::class), 'member-1');

    session(['company' => 'company-1', 'user' => 'member-1']);
    $unauthorizedManagedActor = user_controller()->changeEmail(user_controller_request('POST', [
        'email' => 'managed-new@example.test',
    ], user_controller_user('member-1'), 'changeEmail', ChangeUserEmailRequest::class), 'member-1');

    expect($missingActor->getStatusCode())->toBe(401)
        ->and($missingActor->getData(true))->toBe(['errors' => ['No user session found']])
        ->and($sameCurrentEmail->getStatusCode())->toBe(400)
        ->and($sameCurrentEmail->getData(true))->toBe(['errors' => ['The new email address must be different from the current email address.']])
        ->and($sameManagedEmail->getStatusCode())->toBe(400)
        ->and($sameManagedEmail->getData(true))->toBe(['errors' => ['The new email address must be different from the current email address.']])
        ->and($missingTarget->getStatusCode())->toBe(404)
        ->and($missingTarget->getData(true))->toBe(['errors' => ['User not found to change email for.']])
        ->and($missingManagedActor->getStatusCode())->toBe(401)
        ->and($missingManagedActor->getData(true))->toBe(['errors' => ['Not authorized to change user email.']])
        ->and($unauthorizedManagedActor->getStatusCode())->toBe(401)
        ->and($unauthorizedManagedActor->getData(true))->toBe(['errors' => ['Not authorized to change user email.']]);
});

test('user controller creates fresh email change verification records for current and managed users', function () {
    $capsule = user_controller_database();
    $db      = $capsule->getConnection('mysql');
    EloquentModel::setEventDispatcher(new Dispatcher(app()));

    $db->table('verification_codes')->insert([
        'uuid'         => 'old-email-change-code',
        'subject_uuid' => 'owner-1',
        'subject_type' => Fleetbase\Support\Utils::getModelClassName(user_controller_user('owner-1')),
        'code'         => 'OLD123',
        'for'          => 'email_change',
        'expires_at'   => now()->addMinutes(15),
        'meta'         => json_encode(['new_email' => 'stale@example.test']),
        'status'       => 'active',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $currentChange = user_controller()->changeCurrentUserEmail(user_controller_request('POST', [
        'email' => 'owner-new@example.test',
    ], user_controller_user('owner-1'), 'changeCurrentUserEmail', ChangeCurrentUserEmailRequest::class));
    $managedChange = user_controller()->changeEmail(user_controller_request('POST', [
        'email' => 'member-new@example.test',
    ], user_controller_user('admin-1'), 'changeEmail', ChangeUserEmailRequest::class), 'member-1');

    $ownerCode  = $db->table('verification_codes')->where('subject_uuid', 'owner-1')->where('status', 'active')->whereNull('deleted_at')->first();
    $memberCode = $db->table('verification_codes')->where('subject_uuid', 'member-1')->where('status', 'active')->whereNull('deleted_at')->first();

    expect($currentChange->getStatusCode())->toBe(200)
        ->and($currentChange->getData(true))->toBe(['status' => 'pending'])
        ->and($managedChange->getStatusCode())->toBe(200)
        ->and($managedChange->getData(true))->toBe(['status' => 'pending'])
        ->and($db->table('verification_codes')->where('uuid', 'old-email-change-code')->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and($ownerCode)->not->toBeNull()
        ->and(json_decode($ownerCode->meta, true))->toMatchArray([
            'old_email'         => 'owner@example.test',
            'new_email'         => 'owner-new@example.test',
            'requested_by_uuid' => 'owner-1',
        ])
        ->and($memberCode)->not->toBeNull()
        ->and(json_decode($memberCode->meta, true))->toMatchArray([
            'old_email'         => 'member@example.test',
            'new_email'         => 'member-new@example.test',
            'requested_by_uuid' => 'admin-1',
        ]);
});

test('user controller current endpoint stores and reuses cached user response payloads', function () {
    user_controller_database();
    $user = user_controller_user('owner-1');

    $freshResponse  = user_controller()->current(user_controller_request('GET', [], $user, 'current'));
    $freshPayload   = $freshResponse->getData(true);
    $cachedResponse = user_controller()->current(user_controller_request('GET', [], $user, 'current'));
    $cachedPayload  = $cachedResponse->getData(true);

    expect($freshResponse->getStatusCode())->toBe(200)
        ->and($freshResponse->headers->get('X-Cache-Hit'))->toBe('false')
        ->and($freshPayload['user']['uuid'])->toBe('owner-1')
        ->and($freshPayload['user']['email'])->toBe('owner@example.test')
        ->and($freshPayload['user']['session_status'])->toBe('active')
        ->and($freshResponse->getEtag())->toBe($cachedResponse->getEtag())
        ->and($cachedResponse->headers->get('X-Cache-Hit'))->toBe('true')
        ->and($cachedPayload)->toBe($freshPayload);
});

test('user controller current endpoint bypasses server cache when user cache is disabled', function () {
    user_controller_database();
    config(['fleetbase.user_cache.enabled' => false]);

    $response = user_controller()->current(user_controller_request('GET', [], user_controller_user('owner-1'), 'current'));
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->has('X-Cache-Hit'))->toBeFalse()
        ->and($response->getEtag())->toBeNull()
        ->and($payload['user']['uuid'])->toBe('owner-1')
        ->and($payload['user']['email'])->toBe('owner@example.test')
        ->and($payload['user']['session_status'])->toBe('active');
});

test('user controller reads writes and returns current user two factor settings', function () {
    user_controller_database();
    $user = user_controller_user('owner-1');

    $missingGet = user_controller()->getTwoFactorSettings(user_controller_request('GET', [], null, 'getTwoFactorSettings'));
    $missingSet = user_controller()->saveTwoFactorSettings(user_controller_request('POST', [
        'twoFaSettings' => [
            'enabled' => true,
        ],
    ], null, 'saveTwoFactorSettings'));
    $initial = user_controller()->getTwoFactorSettings(user_controller_request('GET', [], $user, 'getTwoFactorSettings'));
    $saved   = user_controller()->saveTwoFactorSettings(user_controller_request('POST', [
        'twoFaSettings' => [
            'enabled' => true,
            'method'  => 'email',
        ],
    ], $user, 'saveTwoFactorSettings'));
    $updated = user_controller()->getTwoFactorSettings(user_controller_request('GET', [], $user, 'getTwoFactorSettings'));

    expect($missingGet->getStatusCode())->toBe(401)
        ->and($missingGet->getData(true))->toBe(['errors' => ['No user session found']])
        ->and($missingSet->getStatusCode())->toBe(401)
        ->and($missingSet->getData(true))->toBe(['errors' => ['No user session found']])
        ->and($initial->getStatusCode())->toBe(200)
        ->and($initial->getData(true))->toBe([
            'enabled' => false,
            'method'  => 'email',
        ])
        ->and($saved->getData(true))->toBe([
            'enabled' => true,
            'method'  => 'email',
        ])
        ->and($updated->getData(true))->toBe([
            'enabled' => true,
            'method'  => 'email',
        ]);
});

test('user controller current password and permission endpoints expose scoped response contracts', function () {
    $capsule = user_controller_database();
    $user    = user_controller_user('owner-1');

    $capsule->getConnection('mysql')->table('permissions')->insert([
        'id'          => 'permission-manage-users',
        'name'        => 'iam manage users',
        'guard_name'  => 'sanctum',
        'description' => 'Manage users',
        'created_at'  => '2026-07-18 10:00:00',
        'updated_at'  => '2026-07-18 10:00:00',
    ]);
    $capsule->getConnection('mysql')->table('model_has_permissions')->insert([
        'permission_id' => 'permission-manage-users',
        'model_type'    => Fleetbase\Models\CompanyUser::class,
        'model_uuid'    => 'pivot-owner-1',
    ]);

    $setPassword = user_controller()->setCurrentUserPassword(user_controller_request('POST', [
        'password' => 'current-new-password',
    ], $user, 'setCurrentUserPassword', UpdatePasswordRequest::class));
    $permissions = user_controller()->getUserPermissions(user_controller_request('GET', [], $user, 'getUserPermissions'));

    expect($setPassword->getStatusCode())->toBe(200)
        ->and($setPassword->getData(true))->toBe(['status' => 'ok'])
        ->and(password_verify('current-new-password', $capsule->getConnection('mysql')->table('users')->where('uuid', 'owner-1')->value('password')))->toBeTrue()
        ->and($permissions->getStatusCode())->toBe(200)
        ->and($permissions->getData(true)['permissions'])->toHaveCount(1)
        ->and($permissions->getData(true)['permissions'][0]['id'])->toBe('permission-manage-users')
        ->and($permissions->getData(true)['permissions'][0]['name'])->toBe('iam manage users');
});

test('user controller rejects resending invitations outside the active company contract', function () {
    user_controller_database();

    $missingTarget = user_controller()->resendInvitation(user_controller_request('POST', [
        'user' => 'missing-user',
    ], user_controller_user('owner-1'), 'resendInvitation', ResendUserInvite::class));
    $notInvitable = user_controller()->resendInvitation(user_controller_request('POST', [
        'user' => 'foreign-1',
    ], user_controller_user('owner-1'), 'resendInvitation', ResendUserInvite::class));

    expect($missingTarget->getStatusCode())->toBe(404)
        ->and($missingTarget->getData(true))->toBe(['errors' => ['Unable to resend invitation.']])
        ->and($notInvitable->getStatusCode())->toBe(404)
        ->and($notInvitable->getData(true))->toBe(['errors' => ['Unable to resend invitation.']]);
});

test('user controller resends invitations only for users related to the active company', function () {
    $capsule = user_controller_database();
    EloquentModel::setEventDispatcher(new Dispatcher(app()));

    $resent = user_controller()->resendInvitation(user_controller_request('POST', [
        'user' => 'member-1',
    ], user_controller_user('owner-1'), 'resendInvitation', ResendUserInvite::class));

    $invite = $capsule->getConnection('mysql')->table('invites')->where('reason', 'join_company')->first();

    expect($resent->getStatusCode())->toBe(200)
        ->and($resent->getData(true))->toBe(['status' => 'ok'])
        ->and($invite)->not->toBeNull()
        ->and($invite->company_uuid)->toBe('company-1')
        ->and($invite->created_by_uuid)->toBe('owner-1')
        ->and(json_decode($invite->recipients, true))->toBe(['member@example.test']);
});

test('user controller reports invite errors for missing company and unavailable roles', function () {
    user_controller_database();

    session()->flush();
    $missingCompany = user_controller()->inviteUser(user_controller_request('POST', [
        'user' => [
            'email' => 'new-person@example.test',
            'name'  => 'New Person',
        ],
    ], user_controller_user('owner-1'), 'inviteUser', InviteUserRequest::class));

    session(['company' => 'company-1', 'user' => 'owner-1']);
    $invalidNewRole = user_controller()->inviteUser(user_controller_request('POST', [
        'user' => [
            'email'     => 'new-person@example.test',
            'name'      => 'New Person',
            'role_uuid' => 'missing-role',
        ],
    ], user_controller_user('owner-1'), 'inviteUser', InviteUserRequest::class));
    $invalidExistingRole = user_controller()->inviteUser(user_controller_request('POST', [
        'user' => [
            'email'     => 'foreign@example.test',
            'role_uuid' => 'missing-role',
        ],
    ], user_controller_user('owner-1'), 'inviteUser', InviteUserRequest::class));

    expect($missingCompany->getStatusCode())->toBe(400)
        ->and($missingCompany->getData(true))->toBe(['errors' => ['Unable to determine the current organisation.']])
        ->and($invalidNewRole->getStatusCode())->toBe(404)
        ->and($invalidNewRole->getData(true))->toBe(['errors' => ['The selected role is not available for this organisation.']])
        ->and($invalidExistingRole->getStatusCode())->toBe(404)
        ->and($invalidExistingRole->getData(true))->toBe(['errors' => ['The selected role is not available for this organisation.']]);
});

test('user controller invites a brand new user and prevents duplicate organization invitations', function () {
    $capsule = user_controller_database();
    EloquentModel::setEventDispatcher(new Dispatcher(app()));

    $invite = user_controller()->inviteUser(user_controller_request('POST', [
        'user' => [
            'uuid'      => 'fresh-1',
            'email'     => 'fresh@example.test',
            'name'      => 'Fresh User',
            'role_uuid' => 'Administrator',
        ],
    ], user_controller_user('owner-1'), 'inviteUser', InviteUserRequest::class));
    $duplicate = user_controller()->inviteUser(user_controller_request('POST', [
        'user' => [
            'email' => 'fresh@example.test',
            'name'  => 'Fresh User',
        ],
    ], user_controller_user('owner-1'), 'inviteUser', InviteUserRequest::class));

    $freshUser = User::where('email', 'fresh@example.test')->first();
    $inviteRow = $capsule->getConnection('mysql')->table('invites')->where('company_uuid', 'company-1')->where('reason', 'join_company')->first();
    $notifier  = app(Illuminate\Contracts\Notifications\Dispatcher::class);

    expect($invite->getStatusCode())->toBe(200)
        ->and($invite->getData(true)['user']['email'])->toBe('fresh@example.test')
        ->and($invite->getData(true)['user']['status'])->toBe('pending')
        ->and($freshUser)->not->toBeNull()
        ->and($freshUser->company_uuid)->toBe('company-1')
        ->and($capsule->getConnection('mysql')->table('company_users')->where('company_uuid', 'company-1')->where('user_uuid', $freshUser->uuid)->exists())->toBeTrue()
        ->and($inviteRow)->not->toBeNull()
        ->and(json_decode($inviteRow->recipients, true))->toBe(['fresh@example.test'])
        ->and($inviteRow->subject_uuid)->toBe('company-1')
        ->and($notifier->sent)->toHaveCount(1)
        ->and($notifier->sent[0][0]->uuid)->toBe($freshUser->uuid)
        ->and($duplicate->getStatusCode())->toBe(400)
        ->and($duplicate->getData(true))->toBe(['errors' => ['This user is already a member of your organisation.']]);
});

test('user controller invites existing users from another organization without creating duplicates', function () {
    $capsule = user_controller_database();
    EloquentModel::setEventDispatcher(new Dispatcher(app()));

    $invite = user_controller()->inviteUser(user_controller_request('POST', [
        'user' => [
            'email'     => 'foreign@example.test',
            'role_uuid' => 'Administrator',
        ],
    ], user_controller_user('owner-1'), 'inviteUser', InviteUserRequest::class));
    $duplicateInvite = user_controller()->inviteUser(user_controller_request('POST', [
        'user' => [
            'email' => 'foreign@example.test',
        ],
    ], user_controller_user('owner-1'), 'inviteUser', InviteUserRequest::class));

    expect($invite->getStatusCode())->toBe(200)
        ->and($invite->getData(true)['invited'])->toBeTrue()
        ->and($invite->getData(true)['user']['uuid'])->toBe('foreign-1')
        ->and(User::where('email', 'foreign@example.test')->count())->toBe(1)
        ->and($capsule->getConnection('mysql')->table('company_users')->where('company_uuid', 'company-1')->where('user_uuid', 'foreign-1')->exists())->toBeFalse()
        ->and($capsule->getConnection('mysql')->table('invites')->where('company_uuid', 'company-1')->where('reason', 'join_company')->count())->toBe(1)
        ->and($duplicateInvite->getStatusCode())->toBe(400)
        ->and($duplicateInvite->getData(true))->toBe(['errors' => ['This user has already been invited to join your organisation.']]);
});

test('user controller removes multi organization users from only the active company and preserves the next company id', function () {
    $capsule = user_controller_database();

    user_controller_request('POST', [], user_controller_user('owner-1'), 'removeFromCompany');
    $removed = user_controller()->removeFromCompany('member-1');

    expect($removed->getStatusCode())->toBe(200)
        ->and($removed->getData(true))->toBe(['message' => 'User removed'])
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-1')->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('company_users')->where('uuid', 'pivot-member-2')->whereNull('deleted_at')->exists())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'member-1')->value('company_uuid'))->toBe('company-2')
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'member-1')->whereNull('deleted_at')->exists())->toBeTrue();
});

test('user controller deletes users when duplicate active company pivots leave no next company', function () {
    $capsule = user_controller_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('company_users')->insert([
        'uuid'         => 'pivot-single-duplicate',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'single-1',
        'status'       => 'active',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);

    user_controller_request('POST', [], user_controller_user('owner-1'), 'removeFromCompany');
    $removed = user_controller()->removeFromCompany('single-1');

    expect($removed->getStatusCode())->toBe(200)
        ->and($removed->getData(true))->toBe(['message' => 'User removed'])
        ->and($db->table('company_users')->where('user_uuid', 'single-1')->where('company_uuid', 'company-1')->whereNotNull('deleted_at')->count())->toBe(2)
        ->and($db->table('users')->where('uuid', 'single-1')->whereNotNull('deleted_at')->exists())->toBeTrue();
});

test('user controller accepts company invitations and activates pending users with a token', function () {
    $capsule = user_controller_database();
    EloquentModel::setEventDispatcher(new Dispatcher(app()));

    $pending = User::create([
        'uuid'         => 'pending-1',
        'public_id'    => 'user_pending_1',
        'email'        => 'pending@example.test',
        'name'         => 'Pending User',
        'company_uuid' => 'company-2',
        'status'       => 'pending',
        'type'         => 'user',
    ]);

    $capsule->getConnection('mysql')->table('invites')->insert([
        'uuid'            => 'invite-1',
        'public_id'       => 'invite_public_1',
        'code'            => 'JOIN123',
        'uri'             => 'join123',
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'owner-1',
        'subject_uuid'    => 'company-1',
        'subject_type'    => Fleetbase\Support\Utils::getMutationType(Fleetbase\Models\Company::where('uuid', 'company-1')->first()),
        'protocol'        => 'email',
        'recipients'      => json_encode(['pending@example.test']),
        'reason'          => 'join_company',
        'meta'            => json_encode(['role_uuid' => 'Administrator']),
        'expires_at'      => now()->addHours(48),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $accepted = user_controller()->acceptCompanyInvite(user_controller_request('POST', [
        'code' => 'JOIN123',
    ], $pending, 'acceptCompanyInvite', AcceptCompanyInvite::class));

    expect($accepted->getStatusCode())->toBe(200)
        ->and($accepted->getData(true)['status'])->toBe('ok')
        ->and($accepted->getData(true)['needs_password'])->toBeTrue()
        ->and($accepted->getData(true)['token'])->toContain('|')
        ->and($capsule->getConnection('mysql')->table('company_users')->where('company_uuid', 'company-1')->where('user_uuid', 'pending-1')->exists())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'pending-1')->value('company_uuid'))->toBe('company-1')
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'pending-1')->value('status'))->toBe('active')
        ->and($capsule->getConnection('mysql')->table('users')->where('uuid', 'pending-1')->value('email_verified_at'))->not->toBeNull()
        ->and($capsule->getConnection('mysql')->table('invites')->where('uuid', 'invite-1')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and($capsule->getConnection('mysql')->table('personal_access_tokens')->where('tokenable_id', 'pending-1')->count())->toBe(1);
});

test('user controller accepts invitations for existing members without duplicate memberships and reports missing inviting organizations', function () {
    $capsule = user_controller_database();
    $db      = $capsule->getConnection('mysql');
    EloquentModel::setEventDispatcher(new Dispatcher(app()));
    $db->table('roles')->insert([
        'id'           => 'InviteRole',
        'company_uuid' => 'company-1',
        'name'         => 'InviteRole',
        'guard_name'   => 'sanctum',
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);
    $db->table('invites')->insert([
        [
            'uuid'            => 'invite-existing-member',
            'public_id'       => 'invite_public_existing_member',
            'code'            => 'MEMBER1',
            'uri'             => 'member1',
            'company_uuid'    => 'company-1',
            'created_by_uuid' => 'owner-1',
            'subject_uuid'    => 'company-1',
            'subject_type'    => Fleetbase\Support\Utils::getMutationType(Fleetbase\Models\Company::where('uuid', 'company-1')->first()),
            'protocol'        => 'email',
            'recipients'      => json_encode(['member@example.test']),
            'reason'          => 'join_company',
            'meta'            => json_encode(['role_uuid' => 'InviteRole']),
            'expires_at'      => now()->addHours(48),
            'created_at'      => now(),
            'updated_at'      => now(),
        ],
        [
            'uuid'            => 'invite-missing-company',
            'public_id'       => 'invite_public_missing_company',
            'code'            => 'NOCOMP1',
            'uri'             => 'nocomp1',
            'company_uuid'    => 'missing-company',
            'created_by_uuid' => 'owner-1',
            'subject_uuid'    => 'missing-company',
            'subject_type'    => Fleetbase\Models\Company::class,
            'protocol'        => 'email',
            'recipients'      => json_encode(['member@example.test']),
            'reason'          => 'join_company',
            'meta'            => null,
            'expires_at'      => now()->addHours(48),
            'created_at'      => now(),
            'updated_at'      => now(),
        ],
    ]);

    $acceptedExisting = user_controller()->acceptCompanyInvite(user_controller_request('POST', [
        'code' => 'MEMBER1',
    ], user_controller_user('member-1'), 'acceptCompanyInvite', AcceptCompanyInvite::class));
    $missingCompany = user_controller()->acceptCompanyInvite(user_controller_request('POST', [
        'code' => 'NOCOMP1',
    ], user_controller_user('member-1'), 'acceptCompanyInvite', AcceptCompanyInvite::class));

    expect($acceptedExisting->getStatusCode())->toBe(200)
        ->and($acceptedExisting->getData(true)['status'])->toBe('ok')
        ->and($acceptedExisting->getData(true)['needs_password'])->toBeFalse()
        ->and($db->table('company_users')->where('company_uuid', 'company-1')->where('user_uuid', 'member-1')->count())->toBe(1)
        ->and($db->table('model_has_roles')->where('model_type', Fleetbase\Models\CompanyUser::class)->where('model_uuid', 'pivot-member-1')->where('role_id', 'InviteRole')->exists())->toBeTrue()
        ->and($db->table('invites')->where('uuid', 'invite-existing-member')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and($missingCompany->getStatusCode())->toBe(400)
        ->and($missingCompany->getData(true))->toBe(['errors' => ['The organization that invited you no longer exists.']]);
});

test('user controller rejects unavailable and malformed company invitations', function () {
    $capsule = user_controller_database();

    $missingInvite = user_controller()->acceptCompanyInvite(user_controller_request('POST', [
        'code' => 'missing-code',
    ], user_controller_user('owner-1'), 'acceptCompanyInvite', AcceptCompanyInvite::class));

    $capsule->getConnection('mysql')->table('invites')->insert([
        'uuid'            => 'invite-no-recipient',
        'public_id'       => 'invite_public_no_recipient',
        'code'            => 'EMPTY01',
        'uri'             => 'empty01',
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'owner-1',
        'subject_uuid'    => 'company-1',
        'subject_type'    => Fleetbase\Support\Utils::getMutationType(Fleetbase\Models\Company::where('uuid', 'company-1')->first()),
        'protocol'        => 'email',
        'recipients'      => json_encode([]),
        'reason'          => 'join_company',
        'meta'            => null,
        'expires_at'      => now()->addHours(48),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    $missingRecipient = user_controller()->acceptCompanyInvite(user_controller_request('POST', [
        'code' => 'EMPTY01',
    ], user_controller_user('owner-1'), 'acceptCompanyInvite', AcceptCompanyInvite::class));

    $capsule->getConnection('mysql')->table('invites')->insert([
        'uuid'            => 'invite-no-user',
        'public_id'       => 'invite_public_no_user',
        'code'            => 'ABSENT1',
        'uri'             => 'absent1',
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'owner-1',
        'subject_uuid'    => 'company-1',
        'subject_type'    => Fleetbase\Support\Utils::getMutationType(Fleetbase\Models\Company::where('uuid', 'company-1')->first()),
        'protocol'        => 'email',
        'recipients'      => json_encode(['absent@example.test']),
        'reason'          => 'join_company',
        'meta'            => null,
        'expires_at'      => now()->addHours(48),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
    $missingUser = user_controller()->acceptCompanyInvite(user_controller_request('POST', [
        'code' => 'ABSENT1',
    ], user_controller_user('owner-1'), 'acceptCompanyInvite', AcceptCompanyInvite::class));

    expect($missingInvite->getStatusCode())->toBe(400)
        ->and($missingInvite->getData(true))->toBe(['errors' => ['This invitation has already been accepted or is no longer available.']])
        ->and($missingRecipient->getStatusCode())->toBe(400)
        ->and($missingRecipient->getData(true))->toBe(['errors' => ['Unable to locate the user for this invitation.']])
        ->and($missingUser->getStatusCode())->toBe(400)
        ->and($missingUser->getData(true))->toBe(['errors' => ['Unable to locate the user for this invitation.']]);
});
