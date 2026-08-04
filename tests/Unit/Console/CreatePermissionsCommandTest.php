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

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $path = ltrim($path, DIRECTORY_SEPARATOR);

        if (str_starts_with($path, 'vendor/fleetbase/core-api/')) {
            $path = substr($path, strlen('vendor/fleetbase/core-api/'));
        }

        return $path === '' ? getcwd() : getcwd() . DIRECTORY_SEPARATOR . $path;
    }
}

class CreatePermissionsCommandSpy extends CreatePermissions
{
    public array $errors   = [];
    public array $messages = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

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
    create_permissions_auth_schema_fixtures();

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
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstance('schema');

    $schema->create('sequence_bootstrap', function ($table) {
        $table->increments('id');
    });
    $capsule->getConnection('mysql')->table('sequence_bootstrap')->insert([]);

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
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
    });
    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id')->nullable();
        $table->string('model_type')->nullable();
        $table->string('model_uuid')->nullable();
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

function create_permissions_auth_schema_fixtures(): void
{
    $sourceDirectory  = getcwd() . '/src/Auth/Schemas';
    $basePaths        = [base_path()];

    if (function_exists('Fleetbase\\Support\\base_path')) {
        $basePaths[] = Fleetbase\Support\base_path();
    }

    foreach (array_unique($basePaths) as $basePath) {
        $composerLock = $basePath . '/composer.lock';
        if (!file_exists($composerLock)) {
            if (!is_dir(dirname($composerLock))) {
                mkdir(dirname($composerLock), 0777, true);
            }

            file_put_contents($composerLock, json_encode(['packages' => []]));
        }

        $targetDirectory = $basePath . '/vendor/fleetbase/core-api/src/Auth/Schemas';
        if (realpath($sourceDirectory) === realpath($targetDirectory)) {
            continue;
        }

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0777, true);
        }

        foreach (['Developers.php', 'IAM.php'] as $schema) {
            copy($sourceDirectory . '/' . $schema, $targetDirectory . '/' . $schema);
        }
    }
}

function create_permissions_command_counts(Capsule $capsule): array
{
    $db = $capsule->getConnection('mysql');

    return [
        'permissions'           => $db->table('permissions')->count(),
        'policies'              => $db->table('policies')->count(),
        'roles'                 => $db->table('roles')->count(),
        'model_has_permissions' => $db->table('model_has_permissions')->count(),
        'model_has_policies'    => $db->table('model_has_policies')->count(),
        'directives'            => $db->table('directives')->count(),
    ];
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('creates core schema permissions policies roles and policy bindings through handle', function () {
    $capsule = create_permissions_database();
    $command = new CreatePermissionsCommandSpy(['reset' => false]);

    $result = $command->handle();

    $db = $capsule->getConnection('mysql');

    expect($result)->toBeNull()
        ->and($command->errors)->toBe([])
        ->and($db->table('permissions')->where('name', 'developers see extension')->where('service', 'developers')->exists())->toBeTrue()
        ->and($db->table('permissions')->where('name', 'developers * api-key')->where('service', 'developers')->exists())->toBeTrue()
        ->and($db->table('permissions')->where('name', 'iam change-password')->where('service', 'iam')->exists())->toBeTrue()
        ->and($db->table('permissions')->where('name', 'iam execute report')->where('service', 'iam')->exists())->toBeTrue()
        ->and($db->table('permissions')->where('name', 'developers create socket')->exists())->toBeFalse()
        ->and($db->table('permissions')->where('name', 'developers see socket')->exists())->toBeTrue()
        ->and($db->table('policies')->where('name', 'AdministratorAccess')->exists())->toBeTrue()
        ->and($db->table('policies')->where('name', 'DevelopersFullAccess')->exists())->toBeTrue()
        ->and($db->table('policies')->where('name', 'DevelopersReadOnly')->exists())->toBeTrue()
        ->and($db->table('policies')->where('name', 'FLBDeveloper')->where('description', 'Policy for developers to create api credentials, webhooks and view logs.')->exists())->toBeTrue()
        ->and($db->table('roles')->where('name', 'Administrator')->where('description', 'Role for full administrator access to an organization')->exists())->toBeTrue()
        ->and($db->table('roles')->where('name', 'Fleetbase Developer')->where('service', 'developers')->exists())->toBeTrue()
        ->and($db->table('model_has_permissions')->where('permission_id', 'permission-orders-view')->exists())->toBeFalse()
        ->and($db->table('model_has_permissions')->where('permission_id', 'permission-orders-list')->exists())->toBeFalse()
        ->and($db->table('model_has_permissions')->where('model_type', Fleetbase\Models\Policy::class)->count())->toBeGreaterThan(0)
        ->and($db->table('model_has_policies')->where('model_type', Fleetbase\Models\Role::class)->count())->toBeGreaterThan(0)
        ->and($command->messages)->toContain('Created permission: developers see extension')
        ->and($command->messages)->toContain('New Policy for service developers created as FLBDeveloper')
        ->and($command->messages)->toContain('New Role for service developers created as Fleetbase Developer');
});

it('reset option clears stale permissions policies role assignments and directives before rebuilding schemas', function () {
    $capsule = create_permissions_database();
    $db      = $capsule->getConnection('mysql');

    $db->table('permissions')->insert([
        'id'         => 'permission-stale',
        'name'       => 'stale permission',
        'guard_name' => 'sanctum',
        'service'    => 'legacy',
        'created_at' => '2026-07-18 10:00:00',
        'updated_at' => '2026-07-18 10:00:00',
    ]);
    $db->table('policies')->insert([
        'id'          => 'policy-stale',
        'name'        => 'StalePolicy',
        'guard_name'  => 'sanctum',
        'service'     => 'legacy',
        'description' => 'Should be removed',
        'created_at'  => '2026-07-18 10:00:00',
        'updated_at'  => '2026-07-18 10:00:00',
    ]);
    $db->table('model_has_permissions')->insert(['permission_id' => 'permission-stale', 'model_type' => 'Legacy', 'model_uuid' => 'legacy-model']);
    $db->table('model_has_roles')->insert(['role_id' => 'role-stale', 'model_type' => 'Legacy', 'model_uuid' => 'legacy-model']);
    $db->table('model_has_policies')->insert(['policy_id' => 'policy-stale', 'model_type' => 'Legacy', 'model_uuid' => 'legacy-model']);
    $db->table('directives')->insert([
        'uuid'            => 'directive-stale',
        'permission_uuid' => 'permission-stale',
        'subject_type'    => 'Legacy',
        'subject_uuid'    => 'legacy-model',
        'key'             => 'legacy-key',
        'rules'           => json_encode(['legacy']),
        'created_at'      => '2026-07-18 10:00:00',
        'updated_at'      => '2026-07-18 10:00:00',
    ]);

    $before = create_permissions_command_counts($capsule);

    $command = new CreatePermissionsCommandSpy(['reset' => true]);
    $result  = $command->handle();

    expect($before['permissions'])->toBe(3)
        ->and($before['policies'])->toBe(2)
        ->and($before['model_has_permissions'])->toBe(1)
        ->and($before['model_has_policies'])->toBe(1)
        ->and($before['directives'])->toBe(1)
        ->and($result)->toBeNull()
        ->and($command->errors)->toBe([])
        ->and($db->table('permissions')->where('name', 'stale permission')->exists())->toBeFalse()
        ->and($db->table('policies')->where('name', 'StalePolicy')->exists())->toBeFalse()
        ->and($db->table('model_has_permissions')->where('model_type', 'Legacy')->exists())->toBeFalse()
        ->and($db->table('model_has_roles')->where('model_type', 'Legacy')->exists())->toBeFalse()
        ->and($db->table('model_has_policies')->where('model_type', 'Legacy')->exists())->toBeFalse()
        ->and($db->table('directives')->where('key', 'legacy-key')->exists())->toBeFalse()
        ->and($db->table('permissions')->where('name', 'iam see extension')->exists())->toBeTrue()
        ->and($db->table('roles')->where('name', 'IAM Administrator')->exists())->toBeTrue()
        ->and($db->table('model_has_policies')->where('model_type', Fleetbase\Models\Role::class)->count())->toBeGreaterThan(0);
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
        'fleetops view orders',
    ]);

    expect($subject->assignedPermissions)->toBe([
        'fleetops view orders',
        'fleetops list orders',
        'fleetops view orders',
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

    $command->assignPolicies($subject, 'sanctum', ['FleetOpsReadOnly', 'MissingPolicy']);

    expect($subject->assignedPolicies)->toBe(['FleetOpsReadOnly'])
        ->and($command->errors)->toHaveCount(1)
        ->and($command->errors[0])->toContain('There is no policy named `MissingPolicy`');
});

it('reports policy lookup exceptions without aborting assignment', function () {
    create_permissions_database();
    $command = new CreatePermissionsCommandSpy();
    $subject = new CreatePermissionsSubjectFake([
        'id'   => 'role-dispatcher',
        'name' => 'Dispatcher',
    ]);
    $subject->exists = true;

    app('db')->connection('mysql')->getSchemaBuilder()->drop('policies');
    $command->assignPolicies($subject, 'sanctum', ['FleetOpsReadOnly']);

    expect($subject->assignedPolicies)->toBe([])
        ->and($command->errors)->toHaveCount(1)
        ->and($command->errors[0])->toContain('no such table: policies');
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
        'view orders'          => ['company_uuid', '=', '{session.company}'],
        'fleetops list orders' => ['status', '=', 'active'],
        'delete'               => ['company_uuid', '=', '{session.company}'],
        'edit orders'          => ['company_uuid', '=', '{session.company}'],
    ]);

    $rows = $capsule->getConnection('mysql')->table('directives')->orderBy('permission_uuid')->get();

    expect($directives)->toHaveCount(2)
        ->and($rows[0]->permission_uuid)->toBe('permission-orders-list')
        ->and($rows[0]->subject_type)->toBe(CreatePermissionsSubjectFake::class)
        ->and($rows[0]->subject_uuid)->toBe('role-dispatcher')
        ->and($rows[0]->key)->toBe(Directive::createKey(['status', '=', 'active']))
        ->and(json_decode($rows[0]->rules, true))->toBe(['status', '=', 'active'])
        ->and($rows[1]->permission_uuid)->toBe('permission-orders-view')
        ->and($rows[1]->subject_type)->toBe(CreatePermissionsSubjectFake::class)
        ->and($rows[1]->subject_uuid)->toBe('role-dispatcher')
        ->and($rows[1]->key)->toBe(Directive::createKey(['company_uuid', '=', '{session.company}']))
        ->and(json_decode($rows[1]->rules, true))->toBe(['company_uuid', '=', '{session.company}'])
        ->and($command->messages)->toContain('Created directive for role (Dispatcher) as ' . Directive::createKey(['company_uuid', '=', '{session.company}']))
        ->and($command->messages)->toContain('Created directive for role (Dispatcher) as ' . Directive::createKey(['status', '=', 'active']))
        ->and($command->errors[0])->toContain('Invalid directive provided by role (Dispatcher)')
        ->and($command->errors[1])->toContain('There is no permission named `fleetops edit orders`');
});
