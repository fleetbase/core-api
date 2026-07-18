<?php

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

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

function authorization_models_database(): Capsule
{
    $container = bind_test_container([
        'database.default'                        => 'mysql',
        'permission.column_names.model_morph_key' => 'model_uuid',
    ]);

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

it('exposes role policy and permission mutator and response metadata contracts', function () {
    bind_test_container([
        'auth.defaults.guard' => 'web',
    ]);

    $globalRole = new Role();
    $globalRole->setRawAttributes(['name' => 'Administrator', 'company_uuid' => null], true);
    $companyRole = new Role();
    $companyRole->setRawAttributes(['name' => 'Dispatcher', 'company_uuid' => 'company-1'], true);
    $companyRole->permissions = ['orders.view'];
    $companyRole->setAttribute('guard_name', 'api');

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
        ->and($globalPolicy->getAttribute('guard_name'))->toBe('sanctum')
        ->and($globalPolicy->type)->toBe('FLB Managed')
        ->and($companyPolicy->type)->toBe('Organization Managed')
        ->and($companyPolicy->is_mutable)->toBeTrue()
        ->and($companyPolicy->is_deletable)->toBeTrue()
        ->and($companyPolicy->getAttributes())->not->toHaveKey('permissions')
        ->and($companyPolicy->getAttribute('guard_name'))->toBe('sanctum')
        ->and($permission->scopeWithTrashed(Permission::query()))->toBeInstanceOf(Illuminate\Database\Eloquent\Builder::class);
});
