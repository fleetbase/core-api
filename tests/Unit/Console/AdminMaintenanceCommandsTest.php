<?php

use Fleetbase\Console\Commands\AssignAdminRoles;
use Fleetbase\Console\Commands\FixUserCompanies;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Role;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Tester\CommandTester;

class AdminMaintenanceCommandContainer extends FleetbaseTestContainer
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}

function admin_maintenance_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    Illuminate\Container\Container::setInstance(new AdminMaintenanceCommandContainer());

    $container = bind_test_container([
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'cache.default'                                => 'array',
        'cache.stores.array.driver'                    => 'array',
        'auth.defaults.guard'                          => 'sanctum',
        'permission.models.permission'                 => Permission::class,
        'permission.models.role'                       => Role::class,
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'permission.column_names.team_foreign_key'     => 'team_id',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.cache.key'                         => 'spatie.permission.cache',
        'permission.cache.expiration_time'             => DateInterval::createFromDateString('24 hours'),
        'permission.teams'                             => false,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('cache', new CacheManager($container));
    $container->instance('responsecache', new class {
        public function clear(array $tags = []): void
        {
        }
    });
    $container->singleton(PermissionRegistrar::class, fn ($app) => new PermissionRegistrar($app['cache']));
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('owner_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name');
        $table->string('service')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id')->nullable();
        $table->string('role_id')->nullable();
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });

    $capsule->getConnection('mysql')->table('roles')->insert([
        'id'         => 'role-admin',
        'name'       => 'Administrator',
        'guard_name' => 'sanctum',
        'service'    => 'iam',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);

    return $capsule;
}

afterEach(function () {
    EloquentModel::unsetEventDispatcher();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('assigns administrator role to company owners with existing company user pivots', function () {
    $capsule = admin_maintenance_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('users')->insert([
        'uuid'       => 'user-owner',
        'name'       => 'Owner User',
        'email'      => 'owner@example.test',
        'type'       => 'admin',
        'status'     => 'active',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
    $db->table('companies')->insert([
        'uuid'       => 'company-1',
        'owner_id'   => 'user-owner',
        'owner_uuid' => 'user-owner',
        'name'       => 'Acme Logistics',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
    $db->table('company_users')->insert([
        'uuid'         => 'company-user-1',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'user-owner',
        'status'       => 'active',
        'created_at'   => '2026-07-18 00:00:00',
        'updated_at'   => '2026-07-18 00:00:00',
    ]);

    $command = new AssignAdminRoles();
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Acme Logistics - Owner: owner@example.test has been made Administrator.')
        ->and($db->table('model_has_roles')->where([
            'role_id'    => 'role-admin',
            'model_type' => Fleetbase\Models\CompanyUser::class,
            'model_uuid' => 'company-user-1',
        ])->exists())->toBeTrue();
});

it('reports administrator role assignment failures without aborting the command', function () {
    $capsule = admin_maintenance_database();
    $db      = $capsule->getConnection('mysql');

    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('model_has_roles');
    $db->table('users')->insert([
        'uuid'       => 'user-owner',
        'name'       => 'Owner User',
        'email'      => 'owner@example.test',
        'type'       => 'admin',
        'status'     => 'active',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
    $db->table('companies')->insert([
        'uuid'       => 'company-1',
        'owner_id'   => 'user-owner',
        'owner_uuid' => 'user-owner',
        'name'       => 'Acme Logistics',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
    $db->table('company_users')->insert([
        'uuid'         => 'company-user-1',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'user-owner',
        'status'       => 'active',
        'created_at'   => '2026-07-18 00:00:00',
        'updated_at'   => '2026-07-18 00:00:00',
    ]);

    $command = new AssignAdminRoles();
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('no such table: model_has_roles');
});

it('fixes users with a company uuid but no company user membership', function () {
    $capsule = admin_maintenance_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('users')->insert([
        'uuid'         => 'user-missing-company',
        'company_uuid' => 'company-1',
        'name'         => 'Missing Member',
        'email'        => 'missing@example.test',
        'type'         => 'admin',
        'status'       => 'active',
        'created_at'   => '2026-07-18 00:00:00',
        'updated_at'   => '2026-07-18 00:00:00',
    ]);
    $db->table('companies')->insert([
        'uuid'       => 'company-1',
        'owner_id'   => 'user-missing-company',
        'owner_uuid' => 'user-missing-company',
        'name'       => 'Acme Logistics',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);

    $command = new FixUserCompanies();
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Found user Missing Member (missing@example.test) which doesnt have correct company assignment.')
        ->and($tester->getDisplay())->toContain('User missing@example.test was assigned to company: Acme Logistics')
        ->and($db->table('company_users')->where([
            'company_uuid' => 'company-1',
            'user_uuid'    => 'user-missing-company',
        ])->exists())->toBeTrue()
        ->and($db->table('model_has_roles')->where('role_id', 'role-admin')->exists())->toBeTrue();
});
