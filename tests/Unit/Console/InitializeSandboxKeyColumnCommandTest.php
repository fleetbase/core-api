<?php

use Fleetbase\Console\Commands\InitializeSandboxKeyColumn;
use Illuminate\Support\Facades\Facade;

class InitializeSandboxKeyColumnCommandSpy extends InitializeSandboxKeyColumn
{
    public array $messages = [];

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = $string;
    }
}

class InitializeSandboxKeyColumnDbFake
{
    public function __construct(public array $connections)
    {
    }

    public function connection(string $name): InitializeSandboxKeyColumnConnectionFake
    {
        return $this->connections[$name];
    }
}

class InitializeSandboxKeyColumnConnectionFake
{
    public function __construct(
        public InitializeSandboxKeyColumnDoctrineSchemaManagerFake $doctrineSchemaManager,
        public InitializeSandboxKeyColumnSchemaBuilderFake $schemaBuilder,
    ) {
    }

    public function getDoctrineSchemaManager(): InitializeSandboxKeyColumnDoctrineSchemaManagerFake
    {
        return $this->doctrineSchemaManager;
    }

    public function getSchemaBuilder(): InitializeSandboxKeyColumnSchemaBuilderFake
    {
        return $this->schemaBuilder;
    }
}

class InitializeSandboxKeyColumnDoctrineSchemaManagerFake
{
    public function __construct(private array $tables)
    {
    }

    public function listTableNames(): array
    {
        return $this->tables;
    }
}

class InitializeSandboxKeyColumnSchemaBuilderFake
{
    public array $addedColumns = [];

    public function __construct(private array $columnsByTable)
    {
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columnsByTable[$table] ?? [], true);
    }

    public function getColumnListing(string $table): array
    {
        return $this->columnsByTable[$table] ?? [];
    }

    public function table(string $table, Closure $callback): void
    {
        $blueprint = new InitializeSandboxKeyColumnBlueprintFake();

        $callback($blueprint);

        $this->addedColumns[] = [
            'table'    => $table,
            'column'   => $blueprint->column,
            'nullable' => $blueprint->nullable,
            'after'    => $blueprint->after,
        ];
    }
}

class InitializeSandboxKeyColumnBlueprintFake
{
    public ?string $column = null;
    public bool $nullable  = false;
    public ?string $after  = null;

    public function string(string $column): self
    {
        $this->column = $column;

        return $this;
    }

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    public function after(string $column): self
    {
        $this->after = $column;

        return $this;
    }
}

function initialize_sandbox_key_column_database(): InitializeSandboxKeyColumnDbFake
{
    $sandboxSchema = new InitializeSandboxKeyColumnSchemaBuilderFake([
        'orders'            => ['id', 'uuid'],
        'permissions'       => ['id', 'name'],
        'telescope_entries' => ['sequence', 'uuid'],
        'files'             => ['id', 'uuid', '_key'],
    ]);
    $mysqlSchema = new InitializeSandboxKeyColumnSchemaBuilderFake([
        'orders'          => ['uuid', 'public_id'],
        'users'           => ['id', 'email'],
        'api_credentials' => ['id', 'key', '_key'],
    ]);

    return new InitializeSandboxKeyColumnDbFake([
        'sandbox' => new InitializeSandboxKeyColumnConnectionFake(
            new InitializeSandboxKeyColumnDoctrineSchemaManagerFake(['orders', 'permissions', 'telescope_entries', 'files']),
            $sandboxSchema
        ),
        'mysql' => new InitializeSandboxKeyColumnConnectionFake(
            new InitializeSandboxKeyColumnDoctrineSchemaManagerFake(['orders', 'users', 'api_credentials']),
            $mysqlSchema
        ),
    ]);
}

beforeEach(function () {
    bind_test_container();
});

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('adds sandbox and live api key columns while respecting skip tables and existing columns', function () {
    $db = initialize_sandbox_key_column_database();
    app()->instance('db', $db);
    app()->instance('db.schema', $db->connection('mysql')->schemaBuilder);

    $command = new InitializeSandboxKeyColumnCommandSpy();

    expect($command->handle())->toBeNull()
        ->and($db->connection('sandbox')->schemaBuilder->addedColumns)->toBe([
            [
                'table'    => 'orders',
                'column'   => '_key',
                'nullable' => true,
                'after'    => 'id',
            ],
        ])
        ->and($db->connection('mysql')->schemaBuilder->addedColumns)->toBe([
            [
                'table'    => 'orders',
                'column'   => '_key',
                'nullable' => true,
                'after'    => 'uuid',
            ],
            [
                'table'    => 'users',
                'column'   => '_key',
                'nullable' => true,
                'after'    => 'id',
            ],
        ])
        ->and($command->messages)->toBe(['`_key` column added to all tables']);
});
