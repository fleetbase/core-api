<?php

use Fleetbase\Http\Controllers\Internal\v1\IamSearchController;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;

function iam_search_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'auth.defaults.guard'                          => 'sanctum',
        'cache.default'                                => 'array',
        'cache.stores.array.driver'                    => 'array',
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'fleetbase.connection.db'                      => 'mysql',
        'permission.cache.expiration_time'             => DateInterval::createFromDateString('24 hours'),
        'permission.cache.key'                         => 'spatie.permission.cache',
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'permission.column_names.permission_pivot_key' => 'permission_id',
        'permission.column_names.role_pivot_key'       => 'role_id',
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.table_names.roles'                 => 'roles',
    ]);

    $container->instance('cache', new CacheManager($container));
    $container->forgetInstance(PermissionRegistrar::class);
    $container->singleton(PermissionRegistrar::class, fn ($app) => new PermissionRegistrar($app['cache']));
    Facade::clearResolvedInstance('cache');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'admin-1',
    ]);

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
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->index();
        $table->string('user_uuid')->index();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('groups', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('slug')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('group_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('group_uuid')->index();
        $table->string('user_uuid')->index();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('service')->nullable();
        $table->string('guard_name')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('service')->nullable();
        $table->string('guard_name')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->timestamps();
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
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
    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'admin-1', 'public_id' => 'user_admin_1', 'company_uuid' => 'company-1', 'email' => 'admin@example.test', 'phone' => '15550000001', 'name' => 'Admin User', 'type' => 'admin', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'dispatcher-1', 'public_id' => 'user_dispatcher_1', 'company_uuid' => 'company-1', 'email' => 'dispatcher@example.test', 'phone' => '15550000002', 'name' => 'Dispatcher Jane', 'type' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'foreign-1', 'public_id' => 'user_foreign_1', 'company_uuid' => 'company-2', 'email' => 'dispatcher.foreign@example.test', 'phone' => '15550000003', 'name' => 'Foreign Dispatcher', 'type' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['uuid' => 'company-user-admin', 'company_uuid' => 'company-1', 'user_uuid' => 'admin-1', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-user-dispatcher', 'company_uuid' => 'company-1', 'user_uuid' => 'dispatcher-1', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-user-foreign', 'company_uuid' => 'company-2', 'user_uuid' => 'foreign-1', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('groups')->insert([
        ['uuid' => 'group-1', 'public_id' => 'group_dispatchers_1', 'company_uuid' => 'company-1', 'name' => 'Dispatchers', 'description' => 'Operations team', 'slug' => 'dispatchers', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'group-2', 'public_id' => 'group_foreign_1', 'company_uuid' => 'company-2', 'name' => 'Foreign Dispatchers', 'description' => 'Other company', 'slug' => 'foreign-dispatchers', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('roles')->insert([
        ['id' => 'role-1', 'company_uuid' => 'company-1', 'name' => 'Dispatch Manager', 'description' => 'Dispatch access', 'service' => 'iam', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'role-global', 'company_uuid' => null, 'name' => 'Global Dispatch Auditor', 'description' => null, 'service' => 'fleetbase', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'role-foreign', 'company_uuid' => 'company-2', 'name' => 'Foreign Dispatch Role', 'description' => 'Hidden role', 'service' => 'iam', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('policies')->insert([
        ['id' => 'policy-1', 'company_uuid' => 'company-1', 'name' => 'Dispatch Policy', 'description' => 'Dispatch policy access', 'service' => 'iam', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'policy-global', 'company_uuid' => null, 'name' => 'Global Dispatch Policy', 'description' => null, 'service' => 'fleetbase', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 'policy-foreign', 'company_uuid' => 'company-2', 'name' => 'Foreign Dispatch Policy', 'description' => 'Hidden policy', 'service' => 'iam', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function iam_search_request(array $input): Request
{
    return Request::create('/int/v1/iam/search', 'GET', $input);
}

afterEach(function () {
    session()->flush();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('returns an empty result contract for blank iam search queries', function () {
    iam_search_database();

    $response = (new IamSearchController())->search(iam_search_request(['query' => '   ']));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['results' => []]);
});

it('searches iam users and groups within the active company only', function () {
    iam_search_database();

    $response = (new IamSearchController())->search(iam_search_request([
        'query' => 'Dispatcher',
        'types' => 'users,groups',
        'limit' => 8,
    ]));

    $results = $response->getData(true)['results'];

    expect($results)->toHaveCount(2)
        ->and(array_column($results, 'type'))->toBe(['User', 'Group'])
        ->and($results[0]['label'])->toBe('Dispatcher Jane')
        ->and($results[0]['queryParams'])->toBe(['query' => 'Dispatcher', 'view_user' => 'dispatcher-1'])
        ->and($results[1]['label'])->toBe('Dispatchers')
        ->and($results[1]['queryParams'])->toBe(['query' => 'Dispatcher', 'view_group' => 'group-1']);
});

it('searches organization and global iam roles and policies without leaking other tenant records', function () {
    iam_search_database();

    $response = (new IamSearchController())->search(iam_search_request([
        'q'     => 'Dispatch',
        'types' => ['roles', 'policies'],
        'limit' => 3,
    ]));

    $results = $response->getData(true)['results'];

    expect($results)->toHaveCount(3)
        ->and(array_column($results, 'label'))->toBe([
            'Dispatch Manager',
            'Global Dispatch Auditor',
            'Dispatch Policy',
        ])
        ->and(array_column($results, 'type'))->toBe(['Role', 'Role', 'Policy'])
        ->and(collect($results)->pluck('label')->contains('Foreign Dispatch Role'))->toBeFalse()
        ->and(collect($results)->pluck('label')->contains('Foreign Dispatch Policy'))->toBeFalse();
});

it('returns empty iam user results when the active company has no memberships', function () {
    $capsule = iam_search_database();
    $capsule->getConnection('mysql')->table('company_users')->delete();

    $response = (new IamSearchController())->search(iam_search_request([
        'query' => 'Dispatcher',
        'types' => ['users'],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['results' => []]);
});

it('skips iam result types when the current user lacks search permissions', function () {
    iam_search_database();
    session(['user' => 'dispatcher-1']);

    $response = (new IamSearchController())->search(iam_search_request([
        'query' => 'Dispatch',
        'types' => ['roles'],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['results' => []]);
});

it('falls back to all iam result types for malformed types input', function () {
    iam_search_database();

    $response = (new IamSearchController())->search(iam_search_request([
        'query' => 'Dispatch',
        'types' => 123,
        'limit' => 8,
    ]));

    $types = array_column($response->getData(true)['results'], 'type');

    expect($response->getStatusCode())->toBe(200)
        ->and($types)->toContain('User', 'Group', 'Role', 'Policy');
});
