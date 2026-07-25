<?php

use Fleetbase\Support\SqlDumper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\Facade;

class SqlDumperTestDumper extends SqlDumper
{
    public function __construct($db, int $chunk, private array $tables)
    {
        parent::__construct($db, $chunk);
    }

    public function dumpTenant(string $companyUuid, string $filePath): void
    {
        $this->dumpByCompany($companyUuid, $filePath);
    }

    protected function listTables(string $dbName): array
    {
        return $this->tables;
    }
}

class SqlDumperStaticTestDumper extends SqlDumper
{
    public static array $tables = [];

    public function __construct()
    {
        parent::__construct(app('db')->connection('mysql'), 1);
    }

    protected function listTables(string $dbName): array
    {
        return static::$tables;
    }
}

class SqlDumperInspectableTestDumper extends SqlDumper
{
    public function chunkSize(): int
    {
        $reflection = new ReflectionProperty(SqlDumper::class, 'chunk');
        $reflection->setAccessible(true);

        return $reflection->getValue($this);
    }

    public function tables(string $dbName): array
    {
        return $this->listTables($dbName);
    }

    public function streamForeignSet(string $table, array $columns, string $fkColumn, array $parents, string $filePath): void
    {
        $this->streamTableByForeignSet($table, $columns, $fkColumn, $parents, $filePath);
    }
}

class SqlDumperStringTestDumper extends SqlDumper
{
    public static string $path = '';

    public static function createCompanyDump($company, ?string $fileName = null)
    {
        file_put_contents(static::$path, "dump for {$company->uuid}");

        return static::$path;
    }
}

function invokeSqlDumperHelper(string $method, array $arguments = [])
{
    $reflection = new ReflectionClass(SqlDumper::class);
    $methodRef  = $reflection->getMethod($method);
    $methodRef->setAccessible(true);

    if ($methodRef->isStatic()) {
        return $methodRef->invokeArgs(null, $arguments);
    }

    $instance = $reflection->newInstanceWithoutConstructor();

    return $methodRef->invokeArgs($instance, $arguments);
}

function sql_dumper_database(): Capsule
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
    foreach (['orders', 'api_events', 'order_notes', 'audit_logs', 'global_settings'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('status')->nullable();
        $table->integer('sequence')->nullable();
    });
    $schema->create('api_events', function ($table) {
        $table->increments('id');
        $table->string('company_uuid')->nullable();
        $table->string('event')->nullable();
    });
    $schema->create('order_notes', function ($table) {
        $table->increments('id');
        $table->string('order_uuid')->nullable();
        $table->string('body')->nullable();
    });
    $schema->create('audit_logs', function ($table) {
        $table->increments('id');
        $table->string('orders_uuid')->nullable();
        $table->string('message')->nullable();
    });
    $schema->create('global_settings', function ($table) {
        $table->increments('id');
        $table->string('key')->nullable();
    });

    $db = $capsule->getConnection('mysql');
    $db->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1', 'status' => 'created', 'sequence' => 1],
        ['uuid' => 'order-2', 'company_uuid' => 'company-1', 'status' => "driver's assigned", 'sequence' => 2],
        ['uuid' => 'order-3', 'company_uuid' => 'company-2', 'status' => 'created', 'sequence' => 3],
    ]);
    $db->table('api_events')->insert([
        ['company_uuid' => 'company-1', 'event' => 'order.created'],
        ['company_uuid' => 'company-2', 'event' => 'order.created'],
    ]);
    $db->table('order_notes')->insert([
        ['order_uuid' => 'order-1', 'body' => 'tenant note'],
        ['order_uuid' => 'order-3', 'body' => 'other note'],
    ]);
    $db->table('audit_logs')->insert([
        ['orders_uuid' => 'order-2', 'message' => 'tenant audit'],
        ['orders_uuid' => 'order-3', 'message' => 'other audit'],
    ]);
    $db->table('global_settings')->insert([
        ['key' => 'fleetbase.version'],
    ]);

    return $capsule;
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('sql dumper formats record values for insert statements', function () {
    $values = invokeSqlDumperHelper('formatRecordValues', [[
        'null_value'       => null,
        'true_value'       => true,
        'false_value'      => false,
        'integer_value'    => 42,
        'float_value'      => 12.5,
        'numeric_string'   => '12345',
        'leading_zero'     => '0123',
        'quoted_string'    => "Fleetbase's dump",
        'plain_string'     => 'dispatch',
    ]]);

    expect(array_values($values))->toBe([
        'NULL',
        '1',
        '0',
        '42',
        '12.5',
        '12345',
        "'0123'",
        "'Fleetbase''s dump'",
        "'dispatch'",
    ]);
});

test('sql dumper quotes identifiers and escapes embedded backticks', function () {
    expect(invokeSqlDumperHelper('quoteIdentifiers', [['id', 'company_uuid', 'bad`column']]))->toBe([
        '`id`',
        '`company_uuid`',
        '`bad``column`',
    ]);
});

test('sql dumper primary key detection prefers id then uuid and can fall back to null', function () {
    expect(invokeSqlDumperHelper('getPrimaryKey', [['uuid', 'id', 'company_uuid']]))->toBe('id')
        ->and(invokeSqlDumperHelper('getPrimaryKey', [['uuid', 'company_uuid']]))->toBe('uuid')
        ->and(invokeSqlDumperHelper('getPrimaryKey', [['public_id', 'company_uuid']]))->toBeNull();
});

test('sql dumper detects likely foreign key column variants', function () {
    expect(invokeSqlDumperHelper('isForeignKey', ['order_uuid', 'orders']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['orders_id', 'orders']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['order_item_uuid', 'order_items']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['orderitems_id', 'order_items']))->toBeTrue()
        ->and(invokeSqlDumperHelper('isForeignKey', ['company_uuid', 'orders']))->toBeFalse();
});

test('sql dumper guesses and merges foreign key parent sets', function () {
    $columns = ['id', 'order_uuid', 'customer_id', 'unrelated_uuid'];

    expect(invokeSqlDumperHelper('guessForeignKeyMatches', ['events', $columns, ['orders', 'customers']]))
        ->toBe(['order_uuid', 'customer_id']);

    $merged = invokeSqlDumperHelper('collectPrimaryKeysForFk', ['order_uuid', [
        'orders'    => ['order_1' => true, 'order_2' => true],
        'customers' => ['customer_1' => true],
    ]]);

    expect($merged)->toBe([
        'order_1' => true,
        'order_2' => true,
    ]);
});

test('sql dumper streams tenant rows and dependent records without dumping unrelated tables', function () {
    $capsule = sql_dumper_database();
    $path    = tempnam(sys_get_temp_dir(), 'fleetbase-sql-dump-');

    try {
        $dumper = new SqlDumperTestDumper($capsule->getConnection('mysql'), 1, [
            'missing_table',
            'orders',
            'api_events',
            'order_notes',
            'audit_logs',
            'global_settings',
        ]);

        $dumper->dumpTenant('company-1', $path);

        $sql = file_get_contents($path);

        expect($sql)->toContain('INSERT INTO `orders` (`uuid`, `company_uuid`, `status`, `sequence`)')
            ->and($sql)->toContain("'order-1', 'company-1', 'created', 1")
            ->and($sql)->toContain("'order-2', 'company-1', 'driver''s assigned', 2")
            ->and($sql)->toContain('INSERT INTO `api_events` (`id`, `company_uuid`, `event`)')
            ->and($sql)->toContain("'company-1', 'order.created'")
            ->and($sql)->toContain('INSERT INTO `order_notes` (`id`, `order_uuid`, `body`)')
            ->and($sql)->toContain("'order-1', 'tenant note'")
            ->and($sql)->toContain('INSERT INTO `audit_logs` (`id`, `orders_uuid`, `message`)')
            ->and($sql)->toContain("'order-2', 'tenant audit'")
            ->and($sql)->not->toContain('order-3')
            ->and($sql)->not->toContain('other note')
            ->and($sql)->not->toContain('other audit')
            ->and($sql)->not->toContain('global_settings');
    } finally {
        @unlink($path);
    }
});

test('sql dumper public dump entrypoint writes header and scoped tenant sql', function () {
    sql_dumper_database();

    SqlDumperStaticTestDumper::$tables = [
        'orders',
        'api_events',
        'order_notes',
    ];

    $dir  = sys_get_temp_dir() . '/fleetbase-public-sql-dump-' . uniqid('', true);
    $path = $dir . '/dump.sql';

    try {
        $created = SqlDumperStaticTestDumper::createCompanyDump((object) ['uuid' => 'company-1'], $path);
        $sql     = file_get_contents($created);

        expect($created)->toBe($path)
            ->and($sql)->toContain('-- Fleetbase SQL Dump for company company-1')
            ->and($sql)->toContain('-- Generated at ')
            ->and($sql)->toContain('INSERT INTO `orders` (`uuid`, `company_uuid`, `status`, `sequence`)')
            ->and($sql)->toContain("'order-1', 'company-1', 'created', 1")
            ->and($sql)->toContain("'order-2', 'company-1', 'driver''s assigned', 2")
            ->and($sql)->toContain('INSERT INTO `order_notes` (`id`, `order_uuid`, `body`)')
            ->and($sql)->not->toContain('order-3');
    } finally {
        @unlink($path);
        @rmdir($dir);
        SqlDumperStaticTestDumper::$tables = [];
    }
});

test('sql dumper string entrypoint returns generated dump contents', function () {
    SqlDumperStringTestDumper::$path = tempnam(sys_get_temp_dir(), 'fleetbase-string-sql-dump-');

    try {
        $sql = SqlDumperStringTestDumper::getCompanyDumpSql((object) ['uuid' => 'company-1']);

        expect($sql)->toBe('dump for company-1');
    } finally {
        @unlink(SqlDumperStringTestDumper::$path);
        SqlDumperStringTestDumper::$path = '';
    }
});

test('sql dumper foreign set streaming ignores unavailable foreign key columns', function () {
    $path = tempnam(sys_get_temp_dir(), 'fleetbase-fk-sql-dump-');

    try {
        invokeSqlDumperHelper('streamTableByForeignSet', [
            'order_notes',
            ['id', 'body'],
            'order_uuid',
            ['order-1' => true],
            $path,
        ]);

        expect(file_get_contents($path))->toBe('');
    } finally {
        @unlink($path);
    }
});

test('sql dumper constructor normalizes tiny chunk sizes and lists sqlite tables through schema fallback', function () {
    $capsule = sql_dumper_database();
    $dumper  = new SqlDumperInspectableTestDumper($capsule->getConnection('mysql'), 1);

    expect($dumper->chunkSize())->toBe(100)
        ->and($dumper->tables('ignored'))->toContain('orders')
        ->and($dumper->tables('ignored'))->toContain('order_notes')
        ->and($dumper->tables('ignored'))->toContain('global_settings');
});

test('sql dumper foreign set streaming writes matching dependent rows and skips empty batches', function () {
    $capsule = sql_dumper_database();
    $dumper  = new SqlDumperInspectableTestDumper($capsule->getConnection('mysql'), 100);
    $path    = tempnam(sys_get_temp_dir(), 'fleetbase-fk-sql-dump-');

    try {
        $dumper->streamForeignSet('order_notes', ['id', 'order_uuid', 'body'], 'order_uuid', [
            'order-1' => true,
            'missing' => true,
        ], $path);

        $dumper->streamForeignSet('order_notes', ['id', 'order_uuid', 'body'], 'order_uuid', [
            'missing' => true,
        ], $path);

        $sql = file_get_contents($path);

        expect($sql)->toContain('INSERT INTO `order_notes` (`id`, `order_uuid`, `body`)')
            ->and($sql)->toContain("'order-1', 'tenant note'")
            ->and($sql)->not->toContain('other note')
            ->and(substr_count($sql, 'INSERT INTO `order_notes`'))->toBe(1);
    } finally {
        @unlink($path);
    }
});
