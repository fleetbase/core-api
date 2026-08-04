<?php

use Fleetbase\Console\Commands\ForceResetDatabase;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class ForceResetDatabaseTestCommand extends ForceResetDatabase
{
    public array $messages = [];

    public function __construct(private array $options = [])
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $key === null ? $this->options : ($this->options[$key] ?? null);
    }

    public function info($string, $verbosity = null): void
    {
        $this->messages[] = ['info', $string];
    }
}

class ForceResetArtisanKernelFake
{
    public array $calls = [];

    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->calls[] = [$command, $parameters];

        return 0;
    }

    public function output(): string
    {
        return '';
    }
}

function force_reset_database_fixture(): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstances();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach (['audit_logs', 'jobs'] as $tableName) {
        $schema->dropIfExists($tableName);
        $schema->create($tableName, function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
        });
    }

    return $capsule;
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('drops every table on the selected connection and reports each drop', function () {
    $capsule = force_reset_database_fixture();
    $command = new ForceResetDatabaseTestCommand([
        'connection' => 'mysql',
    ]);

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toContain(['info', 'Using connection: mysql'])
        ->and($command->messages)->toContain(['info', 'Dropped table: audit_logs'])
        ->and($command->messages)->toContain(['info', 'Dropped table: jobs'])
        ->and($command->messages)->toContain(['info', 'All tables have been dropped successfully.'])
        ->and($capsule->getConnection('mysql')->getSchemaBuilder()->hasTable('audit_logs'))->toBeFalse()
        ->and($capsule->getConnection('mysql')->getSchemaBuilder()->hasTable('jobs'))->toBeFalse();
});

it('dispatches force reset once for each configured connection when connection is all', function () {
    $kernel    = new ForceResetArtisanKernelFake();
    $container = bind_test_container([
        'database.connections' => [
            'mysql'   => ['driver' => 'mysql'],
            'sandbox' => ['driver' => 'mysql'],
        ],
    ]);
    $container->instance(ConsoleKernel::class, $kernel);
    Facade::clearResolvedInstances();

    $command = new ForceResetDatabaseTestCommand([
        'connection' => 'all',
    ]);

    expect($command->handle())->toBeNull()
        ->and($kernel->calls)->toBe([
            ['db:force-reset', ['--connection' => 'mysql']],
            ['db:force-reset', ['--connection' => 'sandbox']],
        ]);
});
