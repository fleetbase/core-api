<?php

use Fleetbase\Console\Commands\CreateDatabase;
use Illuminate\Support\Facades\Facade;

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

class CreateDatabaseTestCommand extends CreateDatabase
{
    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }
}

class CreateDatabaseDbFake
{
    public array $statements = [];

    public function statement(string $query): bool
    {
        $this->statements[] = $query;

        return true;
    }
}

function create_database_command_container(): CreateDatabaseDbFake
{
    $db = new CreateDatabaseDbFake();

    $container = bind_test_container([
        'fleetbase.connection.db'                  => 'mysql',
        'database.connections.mysql.database'      => 'fleetbase',
        'database.connections.mysql.charset'       => 'utf8mb4',
        'database.connections.mysql.collation'     => 'utf8mb4_unicode_ci',
        'database.connections.sandbox.database'    => 'fleetbase_sandbox',
        'database.connections.sandbox.charset'     => 'utf8',
        'database.connections.sandbox.collation'   => 'utf8_unicode_ci',
    ]);
    $container->instance('db', $db);
    Facade::clearResolvedInstances();

    return $db;
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('creates configured mysql and sandbox schemas with their configured charset and collation', function () {
    $db = create_database_command_container();

    $command = new CreateDatabaseTestCommand([
        'schemaName' => null,
    ]);

    expect($command->handle())->toBeNull()
        ->and($db->statements)->toContain('CREATE DATABASE IF NOT EXISTS fleetbase CHARACTER SET "utf8mb4" COLLATE "utf8mb4_unicode_ci";')
        ->and($db->statements)->toContain('CREATE DATABASE IF NOT EXISTS fleetbase_sandbox CHARACTER SET "utf8" COLLATE "utf8_unicode_ci";');
});

it('uses explicit schema names and suffixes non-primary Fleetbase connections', function () {
    $db = create_database_command_container();

    $command = new CreateDatabaseTestCommand([
        'schemaName' => 'customer_portal',
    ]);

    $command->handle();

    expect($db->statements)->toContain('CREATE DATABASE IF NOT EXISTS customer_portal CHARACTER SET "utf8mb4" COLLATE "utf8mb4_unicode_ci";')
        ->and($db->statements)->toContain('CREATE DATABASE IF NOT EXISTS customer_portal_sandbox CHARACTER SET "utf8" COLLATE "utf8_unicode_ci";')
        ->and(config('database.connections.mysql.database'))->toBe('customer_portal_sandbox');
});
