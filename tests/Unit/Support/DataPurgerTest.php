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

test('data purger discovers allowed tenant tables and detects safe key columns', function () {
    $capsule = data_purger_database();
    $db      = $capsule->getConnection('mysql');
    $schema  = $db->getSchemaBuilder();
    $schema->create('audit_rows', function ($table) {
        $table->string('event')->nullable();
    });

    $purger = new DataPurgerProbe($db);
    $purger->setSkipPrefixes(['global_']);

    expect($purger->tenantTables())->toContain('companies', 'orders', 'api_events', 'order_notes', 'audit_rows')
        ->and($purger->tenantTables())->not->toContain('global_settings')
        ->and($purger->keyFor('companies'))->toBe('uuid')
        ->and($purger->keyFor('orders'))->toBe('uuid')
        ->and($purger->keyFor('audit_rows'))->toBeNull();
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
