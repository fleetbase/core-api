<?php

use Fleetbase\Services\UserDeletionService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class UserDeletionServiceFixture extends UserDeletionService
{
    public array $references = [];

    public array $tables = [];

    protected function databaseName(): string
    {
        return 'fleetbase_test';
    }

    protected function discoverUserReferences(string $database): array
    {
        return $this->references;
    }

    protected function tableExists(string $schema, string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    protected function qualifiedTable(string $schema, string $table): string
    {
        return $table;
    }
}

class UserDeletionMetadataConnection extends Connection
{
    public array $selectResults = [];

    public mixed $selectOneResult = null;

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return array_shift($this->selectResults) ?? [];
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        return $this->selectOneResult;
    }
}

class UserDeletionMetadataService extends UserDeletionService
{
    public function references(string $database): array
    {
        return $this->discoverUserReferences($database);
    }

    public function exists(string $schema, string $table): bool
    {
        return $this->tableExists($schema, $table);
    }

    public function qualify(string $schema, string $table): string
    {
        return $this->qualifiedTable($schema, $table);
    }

    public function currentDatabase(): string
    {
        return $this->databaseName();
    }
}

function user_deletion_fixture(): array
{
    $container  = bind_test_container();
    $connection = [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true,
    ];
    $capsule = new Capsule($container);
    $capsule->addConnection($connection);
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection()->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->string('email')->nullable();
        $table->string('name')->nullable();
    });
    foreach (['contacts', 'drivers'] as $tableName) {
        $schema->create($tableName, function ($table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('user_uuid')->nullable();
        });
    }
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('driver_assigned_uuid')->nullable();
    });
    $schema->create('company_users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->string('user_uuid')->nullable();
    });
    $schema->create('api_credentials', function ($table) {
        $table->increments('id');
        $table->string('user_uuid')->nullable();
    });
    foreach (['model_has_roles', 'model_has_permissions', 'model_has_policies'] as $tableName) {
        $schema->create($tableName, function ($table) {
            $table->increments('id');
            $table->string('model_uuid');
        });
    }
    foreach (['invites', 'companies', 'order_configs', 'networks'] as $tableName) {
        $schema->create($tableName, function ($table) use ($tableName) {
            $table->increments('id');
            $column = match ($tableName) {
                'companies'     => 'owner_uuid',
                'order_configs' => 'author_uuid',
                default         => 'created_by_uuid',
            };
            $table->string($column)->nullable();
        });
    }
    $schema->create('required_audits', function ($table) {
        $table->increments('id');
        $table->string('actor_uuid');
    });
    $schema->create('cascade_logs', function ($table) {
        $table->increments('id');
        $table->string('actor_uuid');
    });

    $db = $capsule->getConnection();
    $db->table('users')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'email' => 'shiv@fleetbase.io', 'name' => 'Shiv One'],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'email' => 'other@fleetbase.io', 'name' => 'Other'],
    ]);
    $db->table('contacts')->insert(['uuid' => 'contact-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('drivers')->insert(['uuid' => 'driver-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('orders')->insert(['uuid' => 'order-1', 'customer_uuid' => 'contact-1', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact', 'driver_assigned_uuid' => 'driver-1']);
    $db->table('company_users')->insert(['uuid' => 'company-user-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('api_credentials')->insert(['user_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('model_has_roles')->insert(['model_uuid' => 'company-user-1']);
    $db->table('model_has_permissions')->insert(['model_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('model_has_policies')->insert(['model_uuid' => 'unrelated-model']);
    $db->table('invites')->insert(['created_by_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('networks')->insert(['created_by_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('companies')->insert(['owner_uuid' => '11111111-1111-4111-8111-111111111111']);
    $db->table('order_configs')->insert(['author_uuid' => '11111111-1111-4111-8111-111111111111']);

    $service             = new UserDeletionServiceFixture($db);
    $service->tables     = ['company_users', 'contacts', 'drivers', 'orders', 'model_has_roles', 'model_has_permissions', 'model_has_policies'];
    $service->references = [
        ['schema' => 'fleetbase_test', 'table' => 'company_users', 'column' => 'user_uuid', 'nullable' => true, 'delete_rule' => 'NO ACTION'],
        ['schema' => 'fleetbase_test', 'table' => 'api_credentials', 'column' => 'user_uuid', 'nullable' => true, 'delete_rule' => 'CASCADE'],
        ['schema' => 'fleetbase_test', 'table' => 'contacts', 'column' => 'user_uuid', 'nullable' => true, 'delete_rule' => 'CASCADE'],
        ['schema' => 'fleetbase_test', 'table' => 'drivers', 'column' => 'user_uuid', 'nullable' => true, 'delete_rule' => 'CASCADE'],
        ['schema' => 'fleetbase_test', 'table' => 'invites', 'column' => 'created_by_uuid', 'nullable' => true, 'delete_rule' => 'NO ACTION'],
        ['schema' => 'fleetbase_test_storefront', 'table' => 'networks', 'column' => 'created_by_uuid', 'nullable' => true, 'delete_rule' => 'NO ACTION'],
        ['schema' => 'fleetbase_test', 'table' => 'companies', 'column' => 'owner_uuid', 'nullable' => true, 'delete_rule' => 'CASCADE'],
        ['schema' => 'fleetbase_test', 'table' => 'order_configs', 'column' => 'author_uuid', 'nullable' => true, 'delete_rule' => 'CASCADE'],
        ['schema' => 'fleetbase_test', 'table' => 'cascade_logs', 'column' => 'actor_uuid', 'nullable' => false, 'delete_rule' => 'CASCADE'],
    ];

    return [$service, $db];
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('finds users by email or UUID and returns an empty plan for no UUIDs', function () {
    [$service] = user_deletion_fixture();

    expect($service->findUsers('shiv@fleetbase.io')->pluck('uuid')->all())->toBe(['11111111-1111-4111-8111-111111111111'])
        ->and($service->findUsers(null, ['22222222-2222-4222-8222-222222222222'])->pluck('email')->all())->toBe(['other@fleetbase.io'])
        ->and($service->plan([]))->toBe([
            'userUuids' => [],
            'actions'   => [],
            'blockers'  => [],
        ]);
});

it('plans cross-schema deletion nulling and cascade impact without duplicates', function () {
    [$service, $db]        = user_deletion_fixture();
    $uuid                  = '11111111-1111-4111-8111-111111111111';
    $service->references[] = $service->references[0];

    $plan       = $service->plan([$uuid, $uuid, null]);
    $actionKeys = collect($plan['actions'])->map(fn ($action) => $action['table'] . '.' . $action['column'] . ':' . $action['action'])->all();

    expect($plan['contactUuids'])->toBe(['contact-1'])
        ->and($plan['companyUserUuids'])->toBe(['company-user-1'])
        ->and($plan['driverUuids'])->toBe(['driver-1'])
        ->and($plan['blockers'])->toBe([])
        ->and($actionKeys)->toContain('orders.customer_uuid:null')
        ->and($actionKeys)->toContain('orders.driver_assigned_uuid:null')
        ->and($actionKeys)->toContain('networks.created_by_uuid:null')
        ->and($actionKeys)->toContain('companies.owner_uuid:null')
        ->and($actionKeys)->toContain('order_configs.author_uuid:null')
        ->and($actionKeys)->toContain('api_credentials.user_uuid:delete')
        ->and($actionKeys)->toContain('model_has_roles.model_uuid:delete')
        ->and($actionKeys)->toContain('cascade_logs.actor_uuid:cascade')
        ->and(collect($actionKeys)->filter(fn ($key) => $key === 'company_users.user_uuid:delete')->count())->toBe(1)
        ->and($db->table('users')->count())->toBe(2);
});

it('blocks restrictive non-nullable references and rolls back execution', function () {
    [$service, $db]        = user_deletion_fixture();
    $uuid                  = '11111111-1111-4111-8111-111111111111';
    $service->references[] = ['schema' => 'fleetbase_test', 'table' => 'required_audits', 'column' => 'actor_uuid', 'nullable' => false, 'delete_rule' => 'RESTRICT'];
    $db->table('required_audits')->insert(['actor_uuid' => $uuid]);

    expect(fn () => $service->execute([$uuid]))
        ->toThrow(RuntimeException::class, 'fleetbase_test.required_audits.actor_uuid')
        ->and($db->table('users')->where('uuid', $uuid)->exists())->toBeTrue()
        ->and($db->table('contacts')->where('user_uuid', $uuid)->exists())->toBeTrue();
});

it('executes the complete cleanup while preserving business records', function () {
    [$service, $db] = user_deletion_fixture();
    $uuid           = '11111111-1111-4111-8111-111111111111';

    $result = $service->execute([$uuid]);
    $order  = $db->table('orders')->where('uuid', 'order-1')->first();

    expect($result['users_deleted'])->toBe(1)
        ->and(collect($result['actions'])->where('action', 'cascade')->first()['affected'])->toBe(0)
        ->and($db->table('users')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and($db->table('users')->where('email', 'other@fleetbase.io')->exists())->toBeTrue()
        ->and($db->table('contacts')->count())->toBe(0)
        ->and($db->table('drivers')->count())->toBe(0)
        ->and($db->table('company_users')->count())->toBe(0)
        ->and($db->table('api_credentials')->count())->toBe(0)
        ->and($db->table('model_has_roles')->count())->toBe(0)
        ->and($db->table('model_has_permissions')->count())->toBe(0)
        ->and($db->table('model_has_policies')->where('model_uuid', 'unrelated-model')->exists())->toBeTrue()
        ->and($order->customer_uuid)->toBeNull()
        ->and($order->customer_type)->toBeNull()
        ->and($order->driver_assigned_uuid)->toBeNull()
        ->and($db->table('invites')->value('created_by_uuid'))->toBeNull()
        ->and($db->table('networks')->value('created_by_uuid'))->toBeNull()
        ->and($db->table('companies')->value('owner_uuid'))->toBeNull()
        ->and($db->table('order_configs')->value('author_uuid'))->toBeNull();
});

it('handles installations without Fleet-Ops tables', function () {
    [$service]       = user_deletion_fixture();
    $service->tables = [];

    $plan = $service->plan(['11111111-1111-4111-8111-111111111111']);

    expect($plan['contactUuids'])->toBe([])
        ->and($plan['companyUserUuids'])->toBe([])
        ->and($plan['driverUuids'])->toBe([])
        ->and(collect($plan['actions'])->where('table', 'orders')->count())->toBe(0);
});

it('sorts database cascades after explicit deletion actions', function () {
    [$service]           = user_deletion_fixture();
    $service->tables     = [];
    $service->references = [
        ['schema' => 'fleetbase_test', 'table' => 'cascade_logs', 'column' => 'actor_uuid', 'nullable' => false, 'delete_rule' => 'CASCADE'],
        ['schema' => 'fleetbase_test', 'table' => 'company_users', 'column' => 'user_uuid', 'nullable' => true, 'delete_rule' => 'NO ACTION'],
    ];

    $plan = $service->plan(['11111111-1111-4111-8111-111111111111']);

    expect(array_column($plan['actions'], 'action'))->toBe(['delete', 'cascade']);
});

it('discovers MySQL cross-schema references and validates identifiers', function () {
    $pdo                       = new PDO('sqlite::memory:');
    $connection                = new UserDeletionMetadataConnection($pdo, 'fleetbase_production');
    $connection->selectResults = [
        [
            (object) ['table_schema' => 'fleetbase_production_storefront', 'table_name' => 'networks', 'column_name' => 'created_by_uuid', 'is_nullable' => 'YES', 'delete_rule' => 'NO ACTION'],
            (object) ['table_schema' => 'fleetbase_production', 'table_name' => 'company_users', 'column_name' => 'user_uuid', 'is_nullable' => 'YES', 'delete_rule' => 'CASCADE'],
        ],
        [
            (object) ['table_schema' => 'fleetbase_production', 'table_name' => 'company_users', 'column_name' => 'user_uuid', 'is_nullable' => 'YES'],
        ],
    ];
    $connection->selectOneResult = (object) ['exists' => 1];
    $service                     = new UserDeletionMetadataService($connection);

    expect($service->references('fleetbase_production'))->toBe([
        ['schema' => 'fleetbase_production_storefront', 'table' => 'networks', 'column' => 'created_by_uuid', 'nullable' => true, 'delete_rule' => 'NO ACTION'],
        ['schema' => 'fleetbase_production', 'table' => 'company_users', 'column' => 'user_uuid', 'nullable' => true, 'delete_rule' => 'NO ACTION'],
    ])->and($service->exists('fleetbase_production', 'users'))->toBeTrue()
        ->and($service->qualify('fleetbase_production', 'users'))->toBe('fleetbase_production.users')
        ->and($service->currentDatabase())->toBe('fleetbase_production')
        ->and(fn () => $service->qualify('fleetbase-production', 'users'))->toThrow(RuntimeException::class, 'Unsafe database identifier');
});
