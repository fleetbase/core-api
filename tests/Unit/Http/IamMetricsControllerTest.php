<?php

use Fleetbase\Http\Controllers\Internal\v1\IamMetricsController;
use Fleetbase\Http\Controllers\Internal\v1\MetricController;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Group;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;

function iam_metrics_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'                            => false,
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'activitylog.table_name'                       => 'activity_log',
        'auth.defaults.guard'                          => 'sanctum',
        'auth.guards.sanctum.provider'                 => 'users',
        'auth.providers.users.model'                   => User::class,
        'cache.default'                                => 'array',
        'cache.stores.array.driver'                    => 'array',
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Role::class,
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'permission.column_names.role_pivot_key'       => 'role_id',
        'permission.column_names.permission_pivot_key' => 'permission_id',
        'permission.cache.key'                         => 'spatie.permission.cache',
        'permission.cache.expiration_time'             => DateInterval::createFromDateString('24 hours'),
    ]);

    $container->instance('cache', new CacheManager($container));
    $container->forgetInstance(PermissionRegistrar::class);
    $container->singleton(PermissionRegistrar::class, fn ($app) => new PermissionRegistrar($app['cache']));
    Facade::clearResolvedInstance('cache');

    session()->flush();
    session(['company' => 'company-1']);

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
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstance('db.schema');

    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('email')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('user_uuid')->nullable()->index();
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('guard_name')->default('sanctum');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('guard_name')->default('sanctum');
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('guard_name')->default('sanctum');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
    });

    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type')->nullable();
        $table->string('model_uuid');
    });

    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type')->nullable();
        $table->string('model_uuid');
    });

    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('model_type')->nullable();
        $table->string('model_uuid');
    });

    $schema->create('groups', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('group_users', function ($table) {
        $table->string('group_uuid')->index();
        $table->string('user_uuid')->index();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->index();
        $table->text('value')->nullable();
    });

    $schema->create('activity_log', function ($table) {
        $table->increments('id');
        $table->string('log_name')->nullable();
        $table->text('description')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->string('event')->nullable();
        $table->text('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    return $capsule;
}

function iam_metrics_seed(Capsule $capsule): void
{
    $db = $capsule->getConnection('mysql');

    $db->table('users')->insert([
        ['uuid' => 'user-active', 'public_id' => 'user_active', 'name' => 'Active Dispatcher', 'type' => 'dispatcher', 'email' => 'active@example.test', 'email_verified_at' => '2026-07-01 08:00:00', 'last_login' => '2026-07-17 08:00:00', 'created_at' => '2026-07-12 08:00:00', 'updated_at' => '2026-07-12 08:00:00', 'deleted_at' => null],
        ['uuid' => 'user-pending', 'public_id' => 'user_pending', 'name' => 'Pending Driver', 'type' => 'driver', 'email' => 'pending@example.test', 'email_verified_at' => null, 'last_login' => null, 'created_at' => '2026-07-13 08:00:00', 'updated_at' => '2026-07-13 08:00:00', 'deleted_at' => null],
        ['uuid' => 'user-inactive', 'public_id' => 'user_inactive', 'name' => 'Inactive Admin', 'type' => 'admin', 'email' => 'inactive@example.test', 'email_verified_at' => '2026-05-01 08:00:00', 'last_login' => '2026-03-01 08:00:00', 'created_at' => '2026-07-14 08:00:00', 'updated_at' => '2026-07-18 08:00:00', 'deleted_at' => null],
        ['uuid' => 'user-unassigned', 'public_id' => 'user_unassigned', 'name' => 'Unassigned User', 'type' => null, 'email' => 'unassigned@example.test', 'email_verified_at' => '2026-07-15 08:00:00', 'last_login' => '2026-07-16 08:00:00', 'created_at' => '2026-07-15 08:00:00', 'updated_at' => '2026-07-15 08:00:00', 'deleted_at' => null],
        ['uuid' => 'user-other', 'public_id' => 'user_other', 'name' => 'Other Tenant', 'type' => 'driver', 'email' => 'other@example.test', 'email_verified_at' => null, 'last_login' => null, 'created_at' => '2026-07-12 08:00:00', 'updated_at' => '2026-07-12 08:00:00', 'deleted_at' => null],
    ]);

    $db->table('company_users')->insert([
        ['uuid' => 'cu-active', 'company_uuid' => 'company-1', 'user_uuid' => 'user-active', 'status' => 'active', 'external' => 0, 'created_at' => '2026-07-12 08:00:00', 'updated_at' => '2026-07-12 08:00:00', 'deleted_at' => null],
        ['uuid' => 'cu-pending', 'company_uuid' => 'company-1', 'user_uuid' => 'user-pending', 'status' => 'pending', 'external' => 0, 'created_at' => '2026-07-13 08:00:00', 'updated_at' => '2026-07-13 08:00:00', 'deleted_at' => null],
        ['uuid' => 'cu-inactive', 'company_uuid' => 'company-1', 'user_uuid' => 'user-inactive', 'status' => 'inactive', 'external' => 0, 'created_at' => '2026-07-14 08:00:00', 'updated_at' => '2026-07-18 08:00:00', 'deleted_at' => null],
        ['uuid' => 'cu-unassigned', 'company_uuid' => 'company-1', 'user_uuid' => 'user-unassigned', 'status' => 'active', 'external' => 0, 'created_at' => '2026-07-15 08:00:00', 'updated_at' => '2026-07-15 08:00:00', 'deleted_at' => null],
        ['uuid' => 'cu-other', 'company_uuid' => 'company-2', 'user_uuid' => 'user-other', 'status' => 'active', 'external' => 0, 'created_at' => '2026-07-12 08:00:00', 'updated_at' => '2026-07-12 08:00:00', 'deleted_at' => null],
    ]);

    $db->table('roles')->insert([
        ['id' => 'role-admin', 'uuid' => 'role-admin', 'company_uuid' => 'company-1', 'name' => 'Admin Manager', 'guard_name' => 'sanctum', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['id' => 'role-dispatcher', 'uuid' => 'role-dispatcher', 'company_uuid' => 'company-1', 'name' => 'Dispatcher', 'guard_name' => 'sanctum', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['id' => 'role-full', 'uuid' => 'role-full', 'company_uuid' => null, 'name' => 'Full Access', 'guard_name' => 'sanctum', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['id' => 'role-other-admin', 'uuid' => 'role-other-admin', 'company_uuid' => 'company-2', 'name' => 'Admin Other', 'guard_name' => 'sanctum', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
    ]);

    $db->table('policies')->insert([
        ['id' => 'policy-fleetops', 'uuid' => 'policy-fleetops', 'company_uuid' => 'company-1', 'name' => 'Fleet-Ops Policy', 'guard_name' => 'sanctum', 'service' => 'fleetops', 'description' => null, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['id' => 'policy-wildcard', 'uuid' => 'policy-wildcard', 'company_uuid' => null, 'name' => 'Wildcard Policy', 'guard_name' => 'sanctum', 'service' => null, 'description' => null, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['id' => 'policy-other', 'uuid' => 'policy-other', 'company_uuid' => 'company-2', 'name' => 'Other Tenant Policy', 'guard_name' => 'sanctum', 'service' => 'storefront', 'description' => null, 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
    ]);

    $db->table('permissions')->insert([
        ['id' => 'perm-orders-all', 'uuid' => 'perm-orders-all', 'name' => 'orders.*', 'guard_name' => 'sanctum', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00'],
        ['id' => 'perm-admin-users', 'uuid' => 'perm-admin-users', 'name' => 'admin.users', 'guard_name' => 'sanctum', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00'],
        ['id' => 'perm-orders-read', 'uuid' => 'perm-orders-read', 'name' => 'orders.read', 'guard_name' => 'sanctum', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00'],
    ]);

    $db->table('model_has_roles')->insert([
        ['role_id' => 'role-admin', 'model_type' => CompanyUser::class, 'model_uuid' => 'cu-active'],
        ['role_id' => 'role-dispatcher', 'model_type' => CompanyUser::class, 'model_uuid' => 'cu-pending'],
        ['role_id' => 'role-other-admin', 'model_type' => CompanyUser::class, 'model_uuid' => 'cu-other'],
    ]);

    $db->table('model_has_policies')->insert([
        ['policy_id' => 'policy-fleetops', 'model_type' => CompanyUser::class, 'model_uuid' => 'cu-pending'],
    ]);

    $db->table('model_has_permissions')->insert([
        ['permission_id' => 'perm-orders-all', 'model_type' => Role::class, 'model_uuid' => 'role-admin'],
        ['permission_id' => 'perm-orders-read', 'model_type' => Role::class, 'model_uuid' => 'role-admin'],
        ['permission_id' => 'perm-orders-read', 'model_type' => Role::class, 'model_uuid' => 'role-full'],
        ['permission_id' => 'perm-orders-all', 'model_type' => Policy::class, 'model_uuid' => 'policy-wildcard'],
        ['permission_id' => 'perm-orders-read', 'model_type' => Policy::class, 'model_uuid' => 'policy-fleetops'],
        ['permission_id' => 'perm-admin-users', 'model_type' => CompanyUser::class, 'model_uuid' => 'cu-inactive'],
        ['permission_id' => 'perm-admin-users', 'model_type' => CompanyUser::class, 'model_uuid' => 'cu-other'],
    ]);

    $db->table('groups')->insert([
        ['uuid' => 'group-empty', 'public_id' => 'group_empty', 'company_uuid' => 'company-1', 'name' => 'Empty Group', 'slug' => 'empty-group', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['uuid' => 'group-ops', 'public_id' => 'group_ops', 'company_uuid' => 'company-1', 'name' => 'Ops Group', 'slug' => 'ops-group', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['uuid' => 'group-other', 'public_id' => 'group_other', 'company_uuid' => 'company-2', 'name' => 'Other Group', 'slug' => 'other-group', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
    ]);

    $db->table('group_users')->insert([
        ['group_uuid' => 'group-ops', 'user_uuid' => 'user-active', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['group_uuid' => 'group-ops', 'user_uuid' => 'user-unassigned', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
        ['group_uuid' => 'group-other', 'user_uuid' => 'user-other', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00', 'deleted_at' => null],
    ]);

    $db->table('settings')->insert([
        ['key' => 'system.2fa', 'value' => json_encode(['enabled' => true, 'enforced' => false])],
        ['key' => 'company.company-1.2fa', 'value' => json_encode(['enabled' => true, 'enforced' => true])],
        ['key' => 'user.user-active.2fa', 'value' => json_encode(['enabled' => true])],
        ['key' => 'user.user-other.2fa', 'value' => json_encode(['enabled' => true])],
    ]);

    $db->table('activity_log')->insert([
        ['log_name' => 'default', 'description' => 'group updated', 'subject_type' => Group::class, 'subject_id' => 'group-ops', 'causer_type' => null, 'causer_id' => null, 'event' => 'updated', 'properties' => '{}', 'batch_uuid' => null, 'created_at' => '2026-07-18 10:00:00', 'updated_at' => '2026-07-18 10:00:00'],
        ['log_name' => 'default', 'description' => 'role assigned', 'subject_type' => Role::class, 'subject_id' => 'role-admin', 'causer_type' => null, 'causer_id' => null, 'event' => 'assigned', 'properties' => '{}', 'batch_uuid' => null, 'created_at' => '2026-07-18 09:00:00', 'updated_at' => '2026-07-18 09:00:00'],
        ['log_name' => 'default', 'description' => 'ignored billing event', 'subject_type' => 'Fleetbase\\Models\\Invoice', 'subject_id' => 'invoice-1', 'causer_type' => null, 'causer_id' => null, 'event' => 'created', 'properties' => '{}', 'batch_uuid' => null, 'created_at' => '2026-07-18 11:00:00', 'updated_at' => '2026-07-18 11:00:00'],
    ]);
}

function iam_metrics_controller(): IamMetricsController
{
    $capsule = iam_metrics_database();
    iam_metrics_seed($capsule);

    return new IamMetricsController();
}

function iam_metrics_request(array $query = []): Request
{
    return Request::create('/int/v1/metrics/iam', 'GET', $query);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
    config([
        'activitylog.table_name'                       => 'activities',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.column_names.model_morph_key'      => 'model_id',
    ]);
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('iam metrics kpis and identity health summarize tenant scoped user state', function () {
    $controller = iam_metrics_controller();

    $kpis   = $controller->kpis(iam_metrics_request())->getData(true);
    $health = $controller->identityHealth(iam_metrics_request())->getData(true);

    expect($kpis)->toMatchArray([
        'active_users'    => ['label' => 'Active Users', 'value' => 2, 'format' => 'users', 'inverse' => false],
        'pending_invites' => ['label' => 'Pending Invites', 'value' => 1, 'format' => 'users', 'inverse' => false],
        'inactive_users'  => ['label' => 'Inactive Users', 'value' => 1, 'format' => 'users', 'inverse' => false],
        'dormant_users'   => ['label' => 'Dormant Users', 'value' => 2, 'format' => 'users', 'inverse' => true],
        'verified_users'  => ['label' => 'Verified Users', 'value' => 3, 'format' => 'users', 'inverse' => false],
        'mfa_coverage'    => ['label' => 'MFA Coverage', 'value' => 25, 'format' => 'percent', 'inverse' => false, 'available' => true],
        'roles'           => ['label' => 'Roles', 'value' => 2, 'format' => 'roles', 'inverse' => false],
        'policies'        => ['label' => 'Policies', 'value' => 1, 'format' => 'policies', 'inverse' => false],
    ])
        ->and($health)->toMatchArray([
            'total_users'  => 4,
            'status'       => ['active' => 2, 'pending' => 1, 'inactive' => 1],
            'verification' => ['verified' => 3, 'unverified' => 1],
            'mfa'          => [
                'available'        => true,
                'enabled_users'    => 1,
                'total_users'      => 4,
                'value'            => 25,
                'format'           => 'percent',
                'system_enabled'   => true,
                'system_enforced'  => false,
                'company_enabled'  => true,
                'company_enforced' => true,
            ],
            'dormant' => ['count' => 2, 'threshold_days' => 90],
        ]);
});

test('iam metrics access coverage counts roles policies direct permissions and groups', function () {
    $payload = iam_metrics_controller()->accessCoverage(iam_metrics_request())->getData(true);

    expect($payload)->toBe([
        'total_users'             => 4,
        'with_roles'              => 2,
        'with_groups'             => 2,
        'with_policies'           => 1,
        'with_direct_permissions' => 1,
        'without_assignments'     => 0,
        'coverage'                => 100,
    ]);
});

test('iam metrics privileged access and policy surface expose high risk grants', function () {
    $controller = iam_metrics_controller();

    $privileged = $controller->privilegedAccess(iam_metrics_request())->getData(true);
    $surface    = $controller->policySurface(iam_metrics_request())->getData(true);

    expect($privileged)->toMatchArray([
        'privileged_roles_count'   => 2,
        'wildcard_policies_count'  => 1,
        'direct_privileged_grants' => 1,
    ])
        ->and(collect($privileged['roles'])->firstWhere('id', 'role-admin'))->toMatchArray(['name' => 'Admin Manager', 'type' => 'Organization Managed', 'permissions_count' => 2])
        ->and(collect($privileged['roles'])->firstWhere('id', 'role-full'))->toMatchArray(['name' => 'Full Access', 'type' => 'Fleetbase Managed', 'permissions_count' => 1])
        ->and(collect($privileged['policies'])->firstWhere('id', 'policy-wildcard'))->toMatchArray([
            'id'                => 'policy-wildcard',
            'name'              => 'Wildcard Policy',
            'service'           => null,
            'type'              => 'Fleetbase Managed',
            'permissions_count' => 1,
        ])
        ->and($surface)->toMatchArray([
            'total'                => 2,
            'organization_managed' => 1,
            'fleetbase_managed'    => 1,
        ])
        ->and($surface['by_service'])->toContain(
            ['label' => 'core', 'value' => 1],
            ['label' => 'fleetops', 'value' => 1],
        );
});

test('iam metrics group coverage buckets memberships and largest groups', function () {
    $payload = iam_metrics_controller()->groupCoverage(iam_metrics_request())->getData(true);

    expect($payload)->toMatchArray([
        'total_groups'      => 2,
        'empty_groups'      => 1,
        'total_memberships' => 2,
        'buckets'           => [
            ['label' => 'Empty', 'value' => 1],
            ['label' => '1-5 members', 'value' => 1],
            ['label' => '6-20 members', 'value' => 0],
            ['label' => '20+ members', 'value' => 0],
        ],
    ])
        ->and($payload['largest_groups'])->toContain(
            ['name' => 'Ops Group', 'members' => 2],
            ['name' => 'Empty Group', 'members' => 0],
        );
});

test('iam metrics lifecycle and user type charts bucket tenant users by day', function () {
    $controller = iam_metrics_controller();

    $lifecycle = $controller->userLifecycle(iam_metrics_request(['period' => '7d']))->getData(true);
    $types     = $controller->usersByTypeCreated(iam_metrics_request(['period' => '7d']))->getData(true);

    expect($lifecycle['labels'])->toBe(['Jul 12', 'Jul 13', 'Jul 14', 'Jul 15', 'Jul 16', 'Jul 17', 'Jul 18'])
        ->and($lifecycle['datasets'])->toBe([
            ['label' => 'Created', 'data' => [1, 1, 1, 1, 0, 0, 0]],
            ['label' => 'Pending', 'data' => [0, 1, 0, 0, 0, 0, 0]],
            ['label' => 'Inactive', 'data' => [0, 0, 0, 0, 0, 0, 1]],
        ])
        ->and($types['labels'])->toBe(['Jul 12', 'Jul 13', 'Jul 14', 'Jul 15', 'Jul 16', 'Jul 17', 'Jul 18'])
        ->and($types['totals'])->toBe([
            'Admin'      => 1,
            'Dispatcher' => 1,
            'Driver'     => 1,
            'User'       => 1,
        ])
        ->and(collect($types['datasets'])->pluck('label')->all())->toBe(['Admin', 'Dispatcher', 'Driver', 'User']);
});

test('iam metrics handles empty tenant charts and assignment coverage without division errors', function () {
    $controller = iam_metrics_controller();
    session(['company' => 'company-empty']);

    $types      = $controller->usersByTypeCreated(iam_metrics_request(['period' => '7d']))->getData(true);
    $access     = $controller->accessCoverage(iam_metrics_request())->getData(true);
    $privileged = $controller->privilegedAccess(iam_metrics_request())->getData(true);

    expect($types['labels'])->toBe(['Jul 12', 'Jul 13', 'Jul 14', 'Jul 15', 'Jul 16', 'Jul 17', 'Jul 18'])
        ->and($types['totals'])->toBe(['User' => 0])
        ->and($types['datasets'])->toHaveCount(1)
        ->and($types['datasets'][0]['label'])->toBe('User')
        ->and($types['datasets'][0]['data'])->toBe([0, 0, 0, 0, 0, 0, 0])
        ->and($access)->toBe([
            'total_users'             => 0,
            'with_roles'              => 0,
            'with_groups'             => 0,
            'with_policies'           => 0,
            'with_direct_permissions' => 0,
            'without_assignments'     => 0,
            'coverage'                => 0,
        ])
        ->and($privileged)->toMatchArray([
            'privileged_roles_count'   => 1,
            'wildcard_policies_count'  => 1,
            'direct_privileged_grants' => 0,
        ]);
});

test('iam metrics period selector supports long range and default windows', function () {
    $controller = iam_metrics_controller();
    $reflection = new ReflectionMethod(IamMetricsController::class, 'period');
    $reflection->setAccessible(true);

    [$ninetyStart]    = $reflection->invoke($controller, iam_metrics_request(['period' => '90d']));
    [$oneEightyStart] = $reflection->invoke($controller, iam_metrics_request(['period' => '180d']));
    [$yearStart]      = $reflection->invoke($controller, iam_metrics_request(['period' => '365d']));
    [$defaultStart]   = $reflection->invoke($controller, iam_metrics_request(['period' => 'unexpected']));

    expect($ninetyStart->toJSON())->toBe('2026-04-20T00:00:00.000000Z')
        ->and($oneEightyStart->toJSON())->toBe('2026-01-20T00:00:00.000000Z')
        ->and($yearStart->toJSON())->toBe('2025-07-19T00:00:00.000000Z')
        ->and($defaultStart->toJSON())->toBe('2026-06-19T00:00:00.000000Z');
});

test('iam metrics activity limits allowed iam subject types', function () {
    $payload = iam_metrics_controller()->activity(iam_metrics_request(['limit' => 2]))->getData(true);

    expect($payload['items'])->toHaveCount(2)
        ->and($payload['items'][0])->toMatchArray([
            'id'           => 1,
            'description'  => 'group updated',
            'event'        => 'updated',
            'subject_type' => 'Group',
            'causer_name'  => null,
            'created_at'   => '2026-07-18T10:00:00.000000Z',
        ])
        ->and($payload['items'][1])->toMatchArray([
            'id'           => 2,
            'description'  => 'role assigned',
            'event'        => 'assigned',
            'subject_type' => 'Role',
            'causer_name'  => null,
        ]);
});

test('legacy iam metric endpoint counts tenant users groups roles and policies', function () {
    iam_metrics_seed(iam_metrics_database());

    $payload = (new MetricController())->iam()->getData(true);

    expect($payload)->toBe([
        'users_count'  => 4,
        'groups_count' => 2,
        'roles_count'  => 2,
        'policy_count' => 1,
    ]);
});
