<?php

use Fleetbase\Support\DataPurger;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class DataPurgerTestPurger extends DataPurger
{
    public array $foreignKeyToggles = [];

    public function __construct($db, ?Closure $logger = null, private array $tables = [], private array $foreignKeys = [])
    {
        parent::__construct($db, $logger);
    }

    protected function listTenantTables(): Collection
    {
        return collect($this->tables);
    }

    protected function listForeignKeysReferencing(array $parentTables): array
    {
        return array_values(array_filter(
            $this->foreignKeys,
            fn ($fk) => in_array($fk[2], $parentTables, true)
        ));
    }

    protected function toggleForeignKeys(bool $enable): void
    {
        $this->foreignKeyToggles[] = $enable;
    }
}

class DataPurgerFailingPurger extends DataPurgerTestPurger
{
    protected function deleteByCompanyColumn(string $table, string $column, string $companyUuid): int
    {
        $deleted = parent::deleteByCompanyColumn($table, $column, $companyUuid);

        if ($table === 'orders') {
            throw new RuntimeException('simulated purge failure');
        }

        return $deleted;
    }
}

class DataPurgerNoForeignKeyTogglePurger extends DataPurgerTestPurger
{
    public function __construct($db, ?Closure $logger = null, array $tables = [], array $foreignKeys = [])
    {
        parent::__construct($db, $logger, $tables, $foreignKeys);
        $this->disableForeignKeys = false;
    }
}

class DataPurgerProbe extends DataPurger
{
    public function tenantTables(): array
    {
        return $this->listTenantTables()->all();
    }

    public function keyFor(string $table): ?string
    {
        return $this->detectKey($table);
    }

    public function deleteMatchingRows(string $table, Closure $where, int $batch = 1000): int
    {
        return $this->deleteRows($table, $where, $batch);
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function setSkipPrefixes(array $prefixes): void
    {
        $this->skipPrefixes = $prefixes;
    }

    public function toggleForeignKeyChecks(bool $enable): void
    {
        $this->toggleForeignKeys($enable);
    }

    public function foreignKeysFor(array $parentTables): array
    {
        return $this->listForeignKeysReferencing($parentTables);
    }
}

class DataPurgerToggleConnection extends Illuminate\Database\Connection
{
    public array $statements = [];

    public function __construct(private string $driverName)
    {
    }

    public function getDriverName()
    {
        return $this->driverName;
    }

    public function statement($query, $bindings = [])
    {
        $this->statements[] = compact('query', 'bindings');

        return true;
    }
}

class DataPurgerMetadataConnection extends Illuminate\Database\Connection
{
    public array $queries = [];

    public function __construct(
        private string $driverName,
        private array $mysqlRows = [],
        private array $pgsqlRows = [],
        private string $databaseName = 'fleetbase_test',
    ) {
    }

    public function getDriverName()
    {
        return $this->driverName;
    }

    public function getDatabaseName()
    {
        return $this->databaseName;
    }

    public function table($table, $as = null)
    {
        $this->queries[] = ['table' => $table, 'as' => $as];

        return new DataPurgerMetadataQuery($table === 'pg_catalog.pg_tables' ? $this->pgsqlRows : $this->mysqlRows);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $this->queries[] = ['select' => $query, 'bindings' => $bindings, 'useReadPdo' => $useReadPdo];

        return $this->pgsqlRows;
    }
}

class DataPurgerMetadataQuery
{
    public array $calls = [];

    public function __construct(private array $rows)
    {
    }

    public function select(...$columns): self
    {
        $this->calls[] = ['select', $columns];

        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        $this->calls[] = ['where', $column, $value];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function get(): Collection
    {
        return collect($this->rows);
    }

    public function pluck(string $column): Collection
    {
        $this->calls[] = ['pluck', $column];

        return collect($this->rows)->map(fn ($row) => data_get($row, $column));
    }
}

class DataPurgerSchemaFallback
{
    public function __construct(private array $tables = [])
    {
    }

    public function getConnection(): object
    {
        return new class {
        };
    }

    public function getAllTables(): array
    {
        return $this->tables;
    }
}

class DataPurgerThrowingDoctrineSchemaFallback extends DataPurgerSchemaFallback
{
    public function getConnection(): object
    {
        return new class {
            public function getDoctrineSchemaManager(): never
            {
                throw new RuntimeException('Doctrine table discovery unavailable');
            }
        };
    }
}

function data_purger_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach (['companies', 'orders', 'api_events', 'order_notes', 'global_settings'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('name')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('status')->nullable();
    });
    $schema->create('api_events', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('event')->nullable();
    });
    $schema->create('order_notes', function ($table) {
        $table->increments('id');
        $table->string('order_uuid')->nullable();
        $table->string('body')->nullable();
    });
    $schema->create('global_settings', function ($table) {
        $table->increments('id');
        $table->string('key')->nullable();
    });

    $db = $capsule->getConnection('mysql');
    $db->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Fleetbase'],
        ['uuid' => 'company-2', 'name' => 'Other'],
    ]);
    $db->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1', 'status' => 'created'],
        ['uuid' => 'order-2', 'company_uuid' => 'company-1', 'status' => 'dispatched'],
        ['uuid' => 'order-3', 'company_uuid' => 'company-2', 'status' => 'created'],
    ]);
    $db->table('api_events')->insert([
        ['uuid' => 'event-1', 'company_uuid' => 'company-1', 'event' => 'order.created'],
        ['uuid' => 'event-2', 'company_uuid' => 'company-2', 'event' => 'order.created'],
    ]);
    $db->table('order_notes')->insert([
        ['order_uuid' => 'order-1', 'body' => 'tenant note'],
        ['order_uuid' => 'order-3', 'body' => 'other note'],
    ]);
    $db->table('global_settings')->insert([
        ['key' => 'fleetbase.version'],
    ]);

    return $capsule;
}

function data_purger_tables(): array
{
    return ['companies', 'orders', 'api_events', 'order_notes', 'global_settings'];
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('data purger deletes tenant rows and optionally the company row while preserving global tables', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');
    $logs    = [];

    $purger = new DataPurgerTestPurger($db, function ($message, $context, $level) use (&$logs) {
        $logs[] = compact('message', 'context', 'level');
    }, data_purger_tables());

    $result = $purger->purgeCompany('company-1', deleteCompanyRow: true, verbose: true);

    expect($result)->toBe([
        'tables' => [
            'orders'     => 2,
            'api_events' => 1,
            'companies'  => 1,
        ],
        'total' => 4,
    ])->and($db->table('orders')->pluck('uuid')->all())->toBe(['order-3'])
        ->and($db->table('api_events')->pluck('uuid')->all())->toBe(['event-2'])
        ->and($db->table('companies')->pluck('uuid')->all())->toBe(['company-2'])
        ->and($db->table('global_settings')->count())->toBe(1)
        ->and($purger->foreignKeyToggles)->toBe([false, true])
        ->and($logs[0]['message'])->toBe('Starting purge')
        ->and($logs[count($logs) - 1]['message'])->toBe('Purge complete');
});

test('data purger dry run rolls back destructive work and reports zero executed deletes', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');

    $result = (new DataPurgerTestPurger($db, null, data_purger_tables()))
        ->purgeCompany('company-1', deleteCompanyRow: true, dryRun: true);

    expect($result)->toBe([
        'tables' => [
            'orders'     => 0,
            'api_events' => 0,
            'companies'  => 0,
        ],
        'total' => 0,
    ])->and($db->table('orders')->count())->toBe(3)
        ->and($db->table('api_events')->count())->toBe(2)
        ->and($db->table('companies')->count())->toBe(2);
});

test('data purger deep reference pass deletes child rows before parent tenant rows disappear', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');

    $purger = new DataPurgerTestPurger(
        $db,
        null,
        data_purger_tables(),
        [['order_notes', 'order_uuid', 'orders', 'uuid']]
    );

    $result = $purger->purgeCompany('company-1', deleteCompanyRow: false, deepReferencePass: true);

    expect($result['tables'])->toMatchArray([
        'order_notes' => 1,
        'orders'      => 2,
        'api_events'  => 1,
    ])->and($result['total'])->toBe(4)
        ->and($db->table('order_notes')->pluck('body')->all())->toBe(['other note'])
        ->and($db->table('orders')->pluck('uuid')->all())->toBe(['order-3'])
        ->and($db->table('companies')->pluck('uuid')->all())->toBe(['company-1', 'company-2']);
});

test('data purger deep reference pass skips unsafe parents and honors dry run', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');
    $schema  = $db->getSchemaBuilder();

    $schema->create('audit_rows', function ($table) {
        $table->string('company_uuid')->nullable();
        $table->string('reference')->nullable();
    });
    $schema->create('audit_children', function ($table) {
        $table->string('audit_reference')->nullable();
    });

    $db->table('audit_rows')->insert([
        ['company_uuid' => 'company-1', 'reference' => 'audit-1'],
        ['company_uuid' => 'company-2', 'reference' => 'audit-2'],
    ]);
    $db->table('audit_children')->insert([
        ['audit_reference' => 'audit-1'],
        ['audit_reference' => 'audit-2'],
    ]);

    $unsafeParent = new DataPurgerTestPurger(
        $db,
        null,
        ['audit_rows', 'audit_children'],
        [['audit_children', 'audit_reference', 'audit_rows', 'reference']]
    );

    $unsafeResult = $unsafeParent->purgeCompany('company-1', deleteCompanyRow: false, deepReferencePass: true);

    expect($unsafeResult)->toBe([
        'tables' => ['audit_rows' => 1],
        'total'  => 1,
    ])->and($db->table('audit_children')->pluck('audit_reference')->all())->toBe(['audit-1', 'audit-2']);

    $dryRun = new DataPurgerTestPurger(
        $db,
        null,
        ['orders', 'order_notes'],
        [['order_notes', 'order_uuid', 'orders', 'uuid']]
    );

    $dryRunResult = $dryRun->purgeCompany('company-2', deleteCompanyRow: false, deepReferencePass: true, dryRun: true);

    expect($dryRunResult)->toBe([
        'tables' => ['orders' => 0],
        'total'  => 0,
    ])->and($db->table('order_notes')->pluck('body')->all())->toBe(['tenant note', 'other note'])
        ->and($db->table('orders')->pluck('uuid')->all())->toBe(['order-1', 'order-2', 'order-3']);
});

test('data purger discovers allowed tenant tables and detects safe key columns', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');
    $schema  = $db->getSchemaBuilder();
    $schema->create('audit_rows', function ($table) {
        $table->string('event')->nullable();
    });
    $schema->create('id_only_rows', function ($table) {
        $table->increments('id');
        $table->string('company_uuid')->nullable();
    });
    $schema->create('jobs', function ($table) {
        $table->increments('id');
        $table->string('queue')->nullable();
    });

    $purger = new DataPurgerProbe($db);
    $purger->setSkipPrefixes(['global_']);

    expect($purger->tenantTables())->toContain('companies', 'orders', 'api_events', 'order_notes', 'audit_rows', 'id_only_rows')
        ->and($purger->tenantTables())->not->toContain('global_settings')
        ->and($purger->tenantTables())->not->toContain('jobs')
        ->and($purger->keyFor('companies'))->toBe('uuid')
        ->and($purger->keyFor('orders'))->toBe('uuid')
        ->and($purger->keyFor('id_only_rows'))->toBe('id')
        ->and($purger->keyFor('audit_rows'))->toBeNull();
});

test('data purger falls back to driver metadata when doctrine table discovery is unavailable', function () {
    bind_test_container();
    app()->instance('db.schema', new DataPurgerThrowingDoctrineSchemaFallback());
    Facade::clearResolvedInstance('db.schema');

    $mysql = new DataPurgerMetadataConnection('mysql', [
        (object) ['table_name' => 'orders'],
        (object) ['table_name' => 'jobs'],
        (object) ['table_name' => 'global_settings'],
    ], [], 'fleetbase_core');
    $pgsql = new DataPurgerMetadataConnection('pgsql', [], [
        (object) ['tablename' => 'companies'],
        (object) ['tablename' => 'personal_access_tokens'],
        (object) ['tablename' => 'fleetbase_webhooks'],
    ]);

    $mysqlProbe = new DataPurgerProbe($mysql);
    $mysqlProbe->setSkipPrefixes(['global_']);
    $pgsqlProbe = new DataPurgerProbe($pgsql);
    $pgsqlProbe->setSkipPrefixes(['fleetbase_']);

    expect($mysqlProbe->tenantTables())->toBe(['orders'])
        ->and($pgsqlProbe->tenantTables())->toBe(['companies'])
        ->and($mysql->queries[0]['table'])->toBe('information_schema.tables')
        ->and($pgsql->queries[0]['table'])->toBe('pg_catalog.pg_tables');
});

test('data purger maps generic schema table rows as a last resort', function () {
    bind_test_container();
    app()->instance('db.schema', new DataPurgerSchemaFallback([
        (object) ['name' => 'orders'],
        ['name' => 'api_events'],
        'jobs',
        'global_settings',
    ]));
    Facade::clearResolvedInstance('db.schema');

    $connection = new DataPurgerMetadataConnection('sqlite');
    $purger     = new DataPurgerProbe($connection);
    $purger->setSkipPrefixes(['global_']);

    expect($purger->tenantTables())->toBe(['orders', 'api_events'])
        ->and($connection->queries)->toBe([]);
});

test('data purger delete helper chunks filtered rows and honors dry run', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');
    $purger  = new DataPurgerProbe($db);

    $deleted = $purger->deleteMatchingRows('orders', fn ($query) => $query->where('company_uuid', 'company-1'), 1);

    expect($deleted)->toBe(2)
        ->and($db->table('orders')->pluck('uuid')->all())->toBe(['order-3']);

    $purger->setDryRun(true);
    $dryRunDeleted = $purger->deleteMatchingRows('api_events', fn ($query) => $query->where('company_uuid', 'company-2'));

    expect($dryRunDeleted)->toBe(0)
        ->and($db->table('api_events')->pluck('uuid')->all())->toBe(['event-1', 'event-2']);
});

test('data purger rolls back failed purges logs errors and restores foreign key checks', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');
    $logs    = [];

    $purger = new DataPurgerFailingPurger($db, function ($message, $context, $level) use (&$logs) {
        $logs[] = compact('message', 'context', 'level');
    }, ['orders']);

    expect(fn () => $purger->purgeCompany('company-1'))->toThrow(RuntimeException::class, 'simulated purge failure')
        ->and($db->table('orders')->pluck('uuid')->all())->toBe(['order-1', 'order-2', 'order-3'])
        ->and($purger->foreignKeyToggles)->toBe([false, true])
        ->and($logs[count($logs) - 1])->toMatchArray([
            'message' => 'Purge failed',
            'context' => ['error' => 'simulated purge failure'],
            'level'   => 'error',
        ]);
});

test('data purger uses the default log facade when no logger closure is provided', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');
    $logger  = app('log');

    $result = (new DataPurgerTestPurger($db, null, ['orders']))
        ->purgeCompany('company-2', deleteCompanyRow: false, verbose: true);

    expect($result)->toBe([
        'tables' => ['orders' => 1],
        'total'  => 1,
    ])->and($logger->entries[0])->toMatchArray([
        'info',
        '[DataPurger] Starting purge',
        ['company' => 'company-2', 'deep' => false, 'dry_run' => false],
    ])->and($logger->entries[count($logger->entries) - 1])->toMatchArray([
        'info',
        '[DataPurger] Purge complete',
        ['total_deleted' => 1],
    ]);
});

test('data purger toggles foreign key checks only for mysql compatible connections', function () {
    $mysql = new DataPurgerToggleConnection('mysql');
    $pgsql = new DataPurgerToggleConnection('pgsql');

    $mysqlPurger = new DataPurgerProbe($mysql);
    $pgsqlPurger = new DataPurgerProbe($pgsql);

    $mysqlPurger->toggleForeignKeyChecks(false);
    $mysqlPurger->toggleForeignKeyChecks(true);
    $pgsqlPurger->toggleForeignKeyChecks(false);

    expect($mysql->statements)->toBe([
        ['query' => 'SET FOREIGN_KEY_CHECKS = 0', 'bindings' => []],
        ['query' => 'SET FOREIGN_KEY_CHECKS = 1', 'bindings' => []],
    ])->and($pgsql->statements)->toBe([]);
});

test('data purger deep reference pass stops cleanly when parent tables have no tenant ids', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');

    $result = (new DataPurgerTestPurger(
        $db,
        null,
        ['orders', 'order_notes'],
        [['order_notes', 'order_uuid', 'orders', 'uuid']]
    ))->purgeCompany('company-missing', deleteCompanyRow: false, deepReferencePass: true);

    expect($result)->toBe([
        'tables' => ['orders' => 0],
        'total'  => 0,
    ])->and($db->table('order_notes')->pluck('body')->all())->toBe(['tenant note', 'other note'])
        ->and($db->table('orders')->pluck('uuid')->all())->toBe(['order-1', 'order-2', 'order-3']);
});

test('data purger discovers foreign key metadata by driver and filters unrelated parents', function () {
    $mysql = new DataPurgerMetadataConnection('mysql', [
        (object) ['child_table' => 'order_notes', 'child_column' => 'order_uuid', 'parent_table' => 'orders', 'parent_column' => 'uuid'],
        (object) ['child_table' => 'audit_rows', 'child_column' => 'company_uuid', 'parent_table' => 'companies', 'parent_column' => 'uuid'],
        (object) ['child_table' => 'global_links', 'child_column' => 'owner_uuid', 'parent_table' => 'users', 'parent_column' => 'uuid'],
    ]);
    $pgsql = new DataPurgerMetadataConnection('pgsql', [], [
        (object) ['child_table' => 'order_notes', 'child_column' => 'order_uuid', 'parent_table' => 'orders', 'parent_column' => 'uuid'],
        (object) ['child_table' => 'global_links', 'child_column' => 'owner_uuid', 'parent_table' => 'users', 'parent_column' => 'uuid'],
    ]);
    $sqlite = new DataPurgerMetadataConnection('sqlite', [
        (object) ['child_table' => 'ignored', 'child_column' => 'ignored_uuid', 'parent_table' => 'orders', 'parent_column' => 'uuid'],
    ]);

    $mysqlKeys  = (new DataPurgerProbe($mysql))->foreignKeysFor(['orders', 'companies']);
    $pgsqlKeys  = (new DataPurgerProbe($pgsql))->foreignKeysFor(['orders']);
    $sqliteKeys = (new DataPurgerProbe($sqlite))->foreignKeysFor(['orders']);

    expect($mysqlKeys)->toBe([
        ['order_notes', 'order_uuid', 'orders', 'uuid'],
        ['audit_rows', 'company_uuid', 'companies', 'uuid'],
    ])->and($pgsqlKeys)->toBe([
        ['order_notes', 'order_uuid', 'orders', 'uuid'],
    ])->and($sqliteKeys)->toBe([])
        ->and($mysql->queries[0]['table'])->toBe('information_schema.KEY_COLUMN_USAGE')
        ->and($pgsql->queries[0]['select'])->toContain('FOREIGN KEY')
        ->and($sqlite->queries)->toBe([]);
});

test('data purger skips foreign key toggling when disableForeignKeys is false', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');

    $purger = new DataPurgerNoForeignKeyTogglePurger($db, null, data_purger_tables());
    $result = $purger->purgeCompany('company-1', deleteCompanyRow: true);

    // Deletion still happens; the FK toggle is simply never invoked.
    expect($purger->foreignKeyToggles)->toBe([])
        ->and($result['total'])->toBe(4)
        ->and($db->table('orders')->pluck('uuid')->all())->toBe(['order-3'])
        ->and($db->table('companies')->pluck('uuid')->all())->toBe(['company-2']);
});

test('data purger rolls back deep-reference child deletions when a later parent purge fails', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');

    // Deep pass deletes order_notes (child of orders) first; the primary pass then throws on
    // 'orders'. The whole transaction must roll back — including the deep-pass child deletes.
    $purger = new DataPurgerFailingPurger(
        $db,
        null,
        data_purger_tables(),
        [['order_notes', 'order_uuid', 'orders', 'uuid']]
    );

    expect(fn () => $purger->purgeCompany('company-1', deleteCompanyRow: false, deepReferencePass: true))
        ->toThrow(RuntimeException::class, 'simulated purge failure');

    // Nothing persisted: the child rows deleted by the deep pass are restored, and orders is intact.
    expect($db->table('order_notes')->pluck('body')->all())->toBe(['tenant note', 'other note'])
        ->and($db->table('orders')->count())->toBe(3)
        ->and($db->table('api_events')->count())->toBe(2)
        // Foreign key checks are restored on the failure path.
        ->and($purger->foreignKeyToggles)->toBe([false, true]);
});
