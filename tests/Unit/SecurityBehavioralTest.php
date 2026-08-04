<?php

/**
 * Behavioral cross-tenant (IDOR) security tests.
 *
 * These complement / replace the source-text assertions in SecurityFindingsTest:
 * instead of grepping controller source for a `where('company_uuid', ...)` string,
 * they seed two companies, act as company A, attempt to mutate company B's records,
 * and assert the request is actually rejected and B's data is untouched.
 *
 * The pattern mirrors tests/Unit/Http/UserControllerTest.php (real in-memory SQLite
 * via Eloquent Capsule).
 */

use Fleetbase\Http\Controllers\Internal\v1\ApiCredentialController;
use Fleetbase\Http\Controllers\Internal\v1\CompanyController;
use Fleetbase\Http\Controllers\Internal\v1\FileController;
use Fleetbase\Http\Controllers\Internal\v1\NotificationController;
use Fleetbase\Http\Controllers\Internal\v1\PolicyController;
use Fleetbase\Http\Requests\Internal\DownloadFileRequest;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\File;
use Fleetbase\Models\Notification;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Services\ImageService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function security_behavioral_database(): Capsule
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
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'activitylog.enabled'                          => false,
        'api.cache.enabled'                            => false,
        'permission.models.permission'                 => Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.column_names.model_morph_key'      => 'model_uuid',
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

    // Cache/response-cache fakes: model delete/observer paths flush tagged caches.
    $container->instance('cache', new class {
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
    });
    $container->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });

    // Spatie permission registrar: permission-bearing models (Policy) resolve this on hydration.
    $container->instance(Spatie\Permission\PermissionRegistrar::class, new class {
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
            return Permission::class;
        }

        public function setPermissionClass(string $permissionClass): self
        {
            return $this;
        }

        public function getPermissions(array $params = [], bool $onlyOne = false): Illuminate\Database\Eloquent\Collection
        {
            return new Illuminate\Database\Eloquent\Collection();
        }

        public function forgetWildcardPermissionIndex(mixed $record = null): void
        {
        }

        public function forgetCachedPermissions(): void
        {
        }
    });

    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');

    session()->flush();
    session(['company' => 'company-1', 'user' => 'owner-1']);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
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

    // Spatie permission tables (empty) — permission-bearing models touch these on delete.
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
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
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
    });

    $schema->create('notifications', function ($table) {
        $table->string('id')->primary();
        $table->string('type');
        $table->string('notifiable_type');
        $table->string('notifiable_id');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('disk')->nullable();
        $table->string('path')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('slug')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->string('secret')->nullable();
        $table->boolean('test_mode')->default(false);
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $now = '2026-07-18 10:00:00';
    $capsule->getConnection('mysql')->table('policies')->insert([
        ['id' => 'policy-mine', 'company_uuid' => 'company-1', 'name' => 'My Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'policy-theirs', 'company_uuid' => 'company-2', 'name' => 'Their Policy', 'guard_name' => 'sanctum', 'service' => 'iam', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('notifications')->insert([
        ['id' => 'notif-mine', 'type' => 'App\\Notifications\\Test', 'notifiable_type' => Fleetbase\Models\User::class, 'notifiable_id' => 'owner-1', 'data' => '{}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'notif-theirs', 'type' => 'App\\Notifications\\Test', 'notifiable_type' => Fleetbase\Models\User::class, 'notifiable_id' => 'other-user-2', 'data' => '{}', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('files')->insert([
        ['uuid' => 'file-mine', 'public_id' => 'file_mine', 'company_uuid' => 'company-1', 'disk' => 'local', 'path' => 'uploads/mine.pdf', 'original_filename' => 'mine.pdf', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'file-theirs', 'public_id' => 'file_theirs', 'company_uuid' => 'company-2', 'disk' => 'local', 'path' => 'uploads/theirs.pdf', 'original_filename' => 'theirs.pdf', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('api_credentials')->insert([
        ['uuid' => 'cred-mine', 'public_id' => 'key_mine', 'company_uuid' => 'company-1', 'user_uuid' => 'owner-1', 'name' => 'Mine', 'key' => 'flb_test_mine', 'secret' => 'secret_mine', 'test_mode' => true, 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'cred-theirs', 'public_id' => 'key_theirs', 'company_uuid' => 'company-2', 'user_uuid' => 'foreign-1', 'name' => 'Theirs', 'key' => 'flb_test_theirs', 'secret' => 'secret_theirs', 'test_mode' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function security_behavioral_request(string $method, string $path): Request
{
    $request = Request::create($path, $method);
    app()->instance('request', $request);

    return $request;
}

function security_behavioral_request_with_user(string $method, string $path, array $input = [], string $userUuid = 'owner-1'): Request
{
    $request = Request::create($path, $method, $input);
    $request->setUserResolver(fn () => (object) ['uuid' => $userUuid, 'email' => $userUuid . '@example.test']);
    app()->instance('request', $request);

    return $request;
}

/**
 * Minimal stand-in for the resolved 'auth' guard so Auth::validate() succeeds without a
 * real auth manager (used by the ApiCredential roll flow).
 */
class SecurityBehavioralAuthGuardFake
{
    public bool $validateResult = true;

    public function validate(array $credentials = []): bool
    {
        return $this->validateResult;
    }

    public function login($user, bool $remember = false): void
    {
    }
}

function security_behavioral_bind_auth_guard(bool $validateResult = true): SecurityBehavioralAuthGuardFake
{
    $guard                 = new SecurityBehavioralAuthGuardFake();
    $guard->validateResult = $validateResult;
    app()->instance('auth', $guard);
    Facade::clearResolvedInstance('auth');

    return $guard;
}

afterEach(function () {
    session()->flush();
    Carbon::setTestNow();
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('policy deleteRecord refuses to delete another company policy (IDOR)', function () {
    security_behavioral_database();

    // Acting as company-1, attempt to delete company-2's policy.
    $request  = security_behavioral_request('DELETE', '/int/v1/policies/policy-theirs');
    $response = (new PolicyController())->deleteRecord('policy-theirs', $request);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['Unable to find policy for deletion.']])
        // The foreign company's policy must remain untouched (not soft-deleted).
        ->and(Policy::where('id', 'policy-theirs')->exists())->toBeTrue()
        ->and(Policy::where('id', 'policy-theirs')->whereNull('deleted_at')->exists())->toBeTrue();
});

test('policy deleteRecord deletes a policy owned by the active company', function () {
    security_behavioral_database();

    $request  = security_behavioral_request('DELETE', '/int/v1/policies/policy-mine');
    $response = (new PolicyController())->deleteRecord('policy-mine', $request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'OK', 'message' => 'Policy deleted.'])
        // Soft-deleted: no longer visible to the default scope, foreign policy still present.
        ->and(Policy::where('id', 'policy-mine')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(Policy::where('id', 'policy-theirs')->whereNull('deleted_at')->exists())->toBeTrue();
});

test('notification deleteRecord refuses to delete another user notification (IDOR)', function () {
    security_behavioral_database();

    // Acting as owner-1, attempt to delete a notification belonging to other-user-2.
    $request  = security_behavioral_request('DELETE', '/int/v1/notifications/notif-theirs');
    $response = (new NotificationController())->deleteRecord('notif-theirs', $request);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Notification not found'])
        ->and(Notification::where('id', 'notif-theirs')->exists())->toBeTrue();
});

test('notification deleteRecord deletes a notification owned by the authenticated user', function () {
    security_behavioral_database();

    $request  = security_behavioral_request('DELETE', '/int/v1/notifications/notif-mine');
    $response = (new NotificationController())->deleteRecord('notif-mine', $request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['message' => 'Notification deleted successfully'])
        ->and(Notification::where('id', 'notif-mine')->exists())->toBeFalse()
        ->and(Notification::where('id', 'notif-theirs')->exists())->toBeTrue();
});

test('file download refuses to resolve another company file (IDOR)', function () {
    security_behavioral_database();

    // Acting as company-1, requesting company-2's file must not resolve.
    $request = security_behavioral_request('GET', '/int/v1/files/file-theirs/download');

    expect(fn () => (new FileController(new ImageService()))->download(
        DownloadFileRequest::createFrom($request),
        'file-theirs'
    ))->toThrow(ModelNotFoundException::class);

    // The foreign file is still present and untouched.
    expect(File::where('uuid', 'file-theirs')->exists())->toBeTrue();
});

test('company transfer ownership rejects a company id that is not the active session company', function () {
    security_behavioral_database();

    // Session company is company-1; attempting to transfer company-2 must be refused at the guard.
    $request  = security_behavioral_request_with_user('POST', '/int/v1/companies/transfer', [
        'company'  => 'company-2',
        'newOwner' => 'someone',
    ]);
    $response = (new CompanyController())->transferOwnership($request);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['No organization found to transfer ownership for.']]);
});

test('company leave organization rejects a company id that is not the active session company', function () {
    security_behavioral_database();

    $request  = security_behavioral_request_with_user('POST', '/int/v1/companies/leave', [
        'company' => 'company-2',
    ]);
    $response = (new CompanyController())->leaveOrganization($request);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['Unable to leave organization.']]);
});

test('api credential roll requires an authenticated request', function () {
    security_behavioral_database();

    // No user resolver on the request -> the auth guard short-circuits before any lookup.
    $request  = security_behavioral_request('POST', '/int/v1/api-credentials/cred-mine/roll');
    $response = ApiCredentialController::roll('cred-mine', $request);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe(['errors' => ['Authentication required to roll key failed.']]);
});

test('api credential roll refuses to roll another company credential (IDOR)', function () {
    security_behavioral_database();
    security_behavioral_bind_auth_guard(true); // authentication passes

    // Acting as company-1 (session), attempt to roll company-2's credential.
    $request  = security_behavioral_request_with_user('POST', '/int/v1/api-credentials/cred-theirs/roll', [
        'password' => 'whatever',
    ]);
    $response = ApiCredentialController::roll('cred-theirs', $request);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe(['errors' => ['API credential attempted to roll could not be found.']])
        // The foreign credential's key is unchanged.
        ->and(ApiCredential::where('uuid', 'cred-theirs')->value('key'))->toBe('flb_test_theirs');
});
