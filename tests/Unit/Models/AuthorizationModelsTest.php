<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationCompanyUserSpy extends CompanyUser
{
    public array $assignedRoles = [];
    public Collection $directPermissions;
    public Collection $rolePermissions;
    public Collection $policyPermissions;
    public Collection $rolePolicyPermissions;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->directPermissions     = collect();
        $this->rolePermissions       = collect();
        $this->policyPermissions     = collect();
        $this->rolePolicyPermissions = collect();
    }

    public function assignRole(...$roles): self
    {
        $this->assignedRoles[] = $roles;

        return $this;
    }

    public function getPermissionsAttribute(): Collection
    {
        return $this->directPermissions;
    }

    public function getPermissionsViaRoles(): Collection
    {
        return $this->rolePermissions;
    }

    public function getPermissionsViaPolicies(): Collection
    {
        return $this->policyPermissions;
    }

    public function getPermissionsViaRolePolicies(): Collection
    {
        return $this->rolePolicyPermissions;
    }
}

class AuthorizationModelsTaggedCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class AuthorizationModelsPermissionRegistrarFake
{
    public string $pivotPermission = 'permission_id';
    public bool $teams             = false;
    public string $teamsKey        = 'team_id';

    public function forgetWildcardPermissionIndex(mixed $record = null): void
    {
    }

    public function forgetCachedPermissions(): void
    {
    }
}

afterEach(function () {
    Illuminate\Support\Carbon::setTestNow();
});

function authorization_models_database(): Capsule
{
    Illuminate\Database\Eloquent\Model::clearBootedModels();

    $container = bind_test_container([
        'database.default'                             => 'mysql',
        'permission.models.permission'                 => Permission::class,
        'permission.models.role'                       => Role::class,
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.column_names.model_morph_key'      => 'model_uuid',
    ]);
    $container->instance('responsecache', new class {
        public function clear(): bool
        {
            return true;
        }
    });
    $container->instance('cache', new AuthorizationModelsTaggedCacheFake());
    $container->instance(PermissionRegistrar::class, new AuthorizationModelsPermissionRegistrarFake());

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    config(['database.connections.mysql' => $connection]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

it('resets existing role pivots before assigning a single company user role', function () {
    $capsule = authorization_models_database();

    $companyUser = new AuthorizationCompanyUserSpy();
    $companyUser->setRawAttributes(['uuid' => 'company-user-1'], true);
    $companyUser->status = null;

    $capsule->getConnection('mysql')->table('model_has_roles')->insert([
        ['role_id' => 'role-1', 'model_type' => CompanyUser::class, 'model_uuid' => 'company-user-1'],
        ['role_id' => 'role-2', 'model_type' => CompanyUser::class, 'model_uuid' => 'company-user-1'],
        ['role_id' => 'role-3', 'model_type' => CompanyUser::class, 'model_uuid' => 'other-company-user'],
    ]);

    expect($companyUser->status)->toBe('active')
        ->and($companyUser->assignSingleRole('Dispatcher'))->toBe($companyUser)
        ->and($companyUser->assignedRoles)->toBe([['Dispatcher']])
        ->and($capsule->getConnection('mysql')->table('model_has_roles')->pluck('model_uuid')->all())->toBe(['other-company-user']);
});

it('merges direct role policy and role-policy permissions for company users', function () {
    bind_test_container();

    $companyUser                        = new AuthorizationCompanyUserSpy();
    $companyUser->directPermissions     = collect(['orders.view', 'orders.create']);
    $companyUser->rolePermissions       = collect(['orders.dispatch']);
    $companyUser->policyPermissions     = collect(['billing.view']);
    $companyUser->rolePolicyPermissions = collect(['reports.export']);

    expect($companyUser->getAllPermissions()->values()->all())->toBe([
        'billing.view',
        'orders.create',
        'orders.dispatch',
        'orders.view',
        'reports.export',
    ])
        ->and($companyUser->hasPermissions(collect(['orders.dispatch'])))->toBeTrue()
        ->and($companyUser->hasPermissions(['reports.export']))->toBeTrue()
        ->and($companyUser->doesntHavePermissions(['users.delete']))->toBeTrue();
});

it('exposes company user ownership relationships', function () {
    bind_test_container();

    $companyUser = new CompanyUser();

    expect($companyUser->user()->getRelated())->toBeInstanceOf(Fleetbase\Models\User::class)
        ->and($companyUser->user()->getForeignKeyName())->toBe('user_uuid')
        ->and($companyUser->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($companyUser->company()->getForeignKeyName())->toBe('company_uuid');
});

it('exposes role policy and permission mutator and response metadata contracts', function () {
    authorization_models_database();
    config([
        'auth.defaults.guard' => 'web',
    ]);

    $globalRole = new Role();
    $globalRole->setRawAttributes(['name' => 'Administrator', 'company_uuid' => null], true);
    $companyRole = new Role();
    $companyRole->setRawAttributes(['name' => 'Dispatcher', 'company_uuid' => 'company-1'], true);
    $companyRole->permissions = ['orders.view'];
    $companyRole->setAttribute('guard_name', 'api');

    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-07-18 09:00:00', 'UTC'));
    $persistedRole = Role::create([
        'id'           => 'role-update-hook',
        'company_uuid' => 'company-1',
        'name'         => 'Before update',
        'guard_name'   => 'sanctum',
    ]);

    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-07-18 10:15:00', 'UTC'));
    $persistedRole->name = 'After update';
    $persistedRole->save();

    $globalPolicy               = new Policy(['name' => 'Manage billing']);
    $companyPolicy              = new Policy(['name' => 'View reports', 'company_uuid' => 'company-1']);
    $companyPolicy->permissions = ['reports.view'];
    $companyPolicy->setAttribute('guard_name', 'api');

    $permission = new Permission();

    expect($globalRole->type)->toBe('FLB Managed')
        ->and($globalRole->is_mutable)->toBeFalse()
        ->and($globalRole->is_deletable)->toBeFalse()
        ->and($companyRole->type)->toBe('Organization Managed')
        ->and($companyRole->is_mutable)->toBeTrue()
        ->and($companyRole->is_deletable)->toBeTrue()
        ->and($companyRole->getAttributes())->not->toHaveKey('permissions')
        ->and($companyRole->getAttribute('guard_name'))->toBe('sanctum')
        ->and($persistedRole->refresh()->updated_at->toDateTimeString())->toBe('2026-07-18 10:15:00')
        ->and($globalPolicy->getAttribute('guard_name'))->toBe('sanctum')
        ->and($globalPolicy->type)->toBe('FLB Managed')
        ->and($companyPolicy->type)->toBe('Organization Managed')
        ->and($companyPolicy->is_mutable)->toBeTrue()
        ->and($companyPolicy->is_deletable)->toBeTrue()
        ->and($companyPolicy->getAttributes())->not->toHaveKey('permissions')
        ->and($companyPolicy->getAttribute('guard_name'))->toBe('sanctum')
        ->and($permission->scopeWithTrashed(Permission::query()))->toBeInstanceOf(Illuminate\Database\Eloquent\Builder::class);
});

it('finds and creates policies by name and guard contract', function () {
    authorization_models_database();
    $cache = new AuthorizationModelsTaggedCacheFake();
    app()->instance('cache', $cache);
    Cache::swap($cache);

    $existing = Policy::create(['name' => 'View reports', 'guard_name' => 'web', 'company_uuid' => 'company-1']);
    $found    = Policy::findByName('View reports', 'sanctum');
    $created  = Policy::findOrCreate('Manage reports', 'web');

    expect($found->is($existing))->toBeTrue()
        ->and(Policy::findByIdentifier($existing->id, 'sanctum')->is($existing))->toBeTrue()
        ->and($created)->toBeInstanceOf(Policy::class)
        ->and($created->name)->toBe('Manage reports')
        ->and($created->guard_name)->toBe('sanctum')
        ->and(Policy::where('name', 'Manage reports')->count())->toBe(1)
        ->and(Policy::findOrCreate('Manage reports', 'sanctum')->is($created))->toBeTrue();
});
