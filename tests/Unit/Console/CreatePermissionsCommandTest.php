<?php

use Fleetbase\Console\Commands\CreatePermissions;
use Fleetbase\Models\Directive;
use Fleetbase\Models\Permission;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        foreach ($replace as $search => $value) {
            $key = str_replace(':' . $search, $value, $key);
        }

        return $key;
    }
}

class CreatePermissionsCommandSpy extends CreatePermissions
{
    public array $errors   = [];
    public array $messages = [];

    public function error($string, $verbosity = null): int
    {
        $this->errors[] = $string;

        return 1;
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = $string;
    }
}

class CreatePermissionsSubjectFake extends EloquentModel
{
    protected $table      = 'roles';
    protected $primaryKey = 'id';
    public $incrementing  = false;
    public $timestamps    = false;
    protected $guarded    = [];

    public array $assignedPermissions = [];
    public array $assignedPolicies    = [];

    public function givePermissionTo($permission): self
    {
        $this->assignedPermissions[] = $permission->name;

        return $this;
    }

    public function assignPolicy($policy): self
    {
        $this->assignedPolicies[] = $policy->name;

        return $this;
    }
}

function create_permissions_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'                             => 'mysql',
        'database.connections.mysql'                   => $connection,
        'cache.default'                                => 'array',
        'cache.stores.array.driver'                    => 'array',
        'auth.defaults.guard'                          => 'sanctum',
        'permission.models.permission'                 => Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.column_names.model_morph_key'      => 'model_uuid',
        'permission.column_names.team_foreign_key'     => 'team_id',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.model_has_policies'    => 'model_has_policies',
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
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name');
        $table->string('service')->nullable();
        $table->timestamps();
    });
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name');
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
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
    $schema->create('policies', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name');
        $table->string('service')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('directives', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('permission_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('key')->nullable();
        $table->text('rules')->nullable();
        $table->timestamps();
    });

    $capsule->getConnection('mysql')->table('permissions')->insert([
        [
            'id'         => 'permission-orders-view',
            'name'       => 'fleetops view orders',
            'guard_name' => 'sanctum',
            'service'    => 'fleetops',
            'created_at' => '2026-07-18 10:00:00',
            'updated_at' => '2026-07-18 10:00:00',
        ],
        [
            'id'         => 'permission-orders-list',
            'name'       => 'fleetops list orders',
            'guard_name' => 'sanctum',
            'service'    => 'fleetops',
            'created_at' => '2026-07-18 10:00:00',
            'updated_at' => '2026-07-18 10:00:00',
        ],
    ]);
    $capsule->getConnection('mysql')->table('policies')->insert([
        [
            'id'          => 'policy-read-only',
            'name'        => 'FleetOpsReadOnly',
            'guard_name'  => 'sanctum',
            'service'     => 'fleetops',
            'description' => 'Read only',
            'created_at'  => '2026-07-18 10:00:00',
            'updated_at'  => '2026-07-18 10:00:00',
        ],
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $capsule;
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('normalizes permission names and reports invalid permission helper entries', function () {
    create_permissions_database();
    $command = new CreatePermissionsCommandSpy();
    $subject = new CreatePermissionsSubjectFake([
        'id'   => 'role-dispatcher',
        'name' => 'Dispatcher',
    ]);
    $subject->exists = true;

    $command->assignPermissions($subject, 'fleetops', 'sanctum', [
        'view orders',
        'fleetops list orders',
        'delete',
        'fleetops missing orders',
    ]);

    expect($subject->assignedPermissions)->toBe([
        'fleetops view orders',
        'fleetops list orders',
    ])
        ->and($command->errors[0])->toContain('Invalid permission provided by role (Dispatcher)')
        ->and($command->errors[0])->toContain('fleetops delete')
        ->and($command->errors[1])->toContain('There is no permission named `fleetops missing orders`');
});

it('assigns existing policies by name through the helper', function () {
    create_permissions_database();
    $command = new CreatePermissionsCommandSpy();
    $subject = new CreatePermissionsSubjectFake([
        'id'   => 'role-dispatcher',
        'name' => 'Dispatcher',
    ]);
    $subject->exists = true;

    $command->assignPolicies($subject, 'sanctum', ['FleetOpsReadOnly']);

    expect($subject->assignedPolicies)->toBe(['FleetOpsReadOnly'])
        ->and($command->errors)->toBe([]);
});

it('creates directives for valid permissions and skips invalid directive definitions', function () {
    $capsule = create_permissions_database();
    $command = new CreatePermissionsCommandSpy();
    $subject = new CreatePermissionsSubjectFake([
        'id'   => 'role-dispatcher',
        'name' => 'Dispatcher',
    ]);
    $subject->exists = true;

    $directives = $command->createDirectives($subject, 'fleetops', 'sanctum', [
        'view orders' => ['company_uuid', '=', '{session.company}'],
        'delete'      => ['company_uuid', '=', '{session.company}'],
        'edit orders' => ['company_uuid', '=', '{session.company}'],
    ]);

    $row = $capsule->getConnection('mysql')->table('directives')->first();

    expect($directives)->toHaveCount(1)
        ->and($row->permission_uuid)->toBe('permission-orders-view')
        ->and($row->subject_type)->toBe(CreatePermissionsSubjectFake::class)
        ->and($row->subject_uuid)->toBe('role-dispatcher')
        ->and($row->key)->toBe(Directive::createKey(['company_uuid', '=', '{session.company}']))
        ->and(json_decode($row->rules, true))->toBe(['company_uuid', '=', '{session.company}'])
        ->and($command->messages)->toContain('Created directive for role (Dispatcher) as ' . Directive::createKey(['company_uuid', '=', '{session.company}']))
        ->and($command->errors[0])->toContain('Invalid directive provided by role (Dispatcher)')
        ->and($command->errors[1])->toContain('There is no permission named `fleetops edit orders`');
});
