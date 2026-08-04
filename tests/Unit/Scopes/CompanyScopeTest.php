<?php

use Fleetbase\Scopes\CompanyScope;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class CompanyScopeTestContainer extends FleetbaseTestContainer
{
    public function __construct(private bool $console = false)
    {
    }

    public function runningInConsole(): bool
    {
        return $this->console;
    }
}

class CompanyScopeSessionFake
{
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}

class CompanyScopeRecord extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'company_scope_records';
    protected $guarded    = [];
    public $timestamps    = false;
}

class CompanyScopePlainRecord extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'company_scope_plain_records';
    protected $guarded    = [];
    public $timestamps    = false;
}

function company_scope_database(bool $console = false, ?string $company = 'company-1'): Capsule
{
    EloquentModel::clearBootedModels();
    CompanyScope::flushColumnCache();

    Container::setInstance(new CompanyScopeTestContainer($console));
    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
        'fleetbase.connection.db' => 'mysql',
    ]);
    Facade::setFacadeApplication($container);

    $connection = $container->make('config')->get('database.connections.mysql');
    $capsule    = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    $container->instance('session', new CompanyScopeSessionFake(['company' => $company]));
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');
    Facade::clearResolvedInstance('session');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('company_scope_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
    });
    $schema->create('company_scope_plain_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
    });

    $capsule->getConnection('mysql')->table('company_scope_records')->insert([
        ['uuid' => 'record-1', 'company_uuid' => 'company-1', 'name' => 'Visible'],
        ['uuid' => 'record-2', 'company_uuid' => 'company-2', 'name' => 'Hidden'],
    ]);
    $capsule->getConnection('mysql')->table('company_scope_plain_records')->insert([
        ['uuid' => 'plain-1', 'name' => 'Plain'],
    ]);

    return $capsule;
}

afterEach(function () {
    CompanyScope::flushColumnCache();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
    Container::setInstance(new FleetbaseTestContainer());
});

test('company scope constrains models with company uuid only during request context with company session', function () {
    company_scope_database();

    $builder = CompanyScopeRecord::query();
    $scope   = new CompanyScope();
    $scope->apply($builder, new CompanyScopeRecord());

    $plainBuilder = CompanyScopePlainRecord::query();
    $scope->apply($plainBuilder, new CompanyScopePlainRecord());

    expect($builder->orderBy('uuid')->pluck('uuid')->all())->toBe(['record-1'])
        ->and($plainBuilder->pluck('uuid')->all())->toBe(['plain-1']);
});

test('company scope skips console and missing session contexts and exposes removal macro', function () {
    company_scope_database(console: true);

    $consoleBuilder = CompanyScopeRecord::query();
    $scope          = new CompanyScope();
    $scope->apply($consoleBuilder, new CompanyScopeRecord());

    expect($consoleBuilder->orderBy('uuid')->pluck('uuid')->all())->toBe(['record-1', 'record-2']);

    company_scope_database(console: false, company: null);
    $missingSessionBuilder = CompanyScopeRecord::query();
    $scope->apply($missingSessionBuilder, new CompanyScopeRecord());

    expect($missingSessionBuilder->orderBy('uuid')->pluck('uuid')->all())->toBe(['record-1', 'record-2']);

    company_scope_database();
    CompanyScopeRecord::addGlobalScope(new CompanyScope());

    $scoped   = CompanyScopeRecord::query()->orderBy('uuid')->pluck('uuid')->all();
    $unscoped = CompanyScopeRecord::query()->withoutCompanyScope()->orderBy('uuid')->pluck('uuid')->all();

    expect($scoped)->toBe(['record-1'])
        ->and($unscoped)->toBe(['record-1', 'record-2'])
        ->and(CompanyScopeRecord::query())->toBeInstanceOf(Builder::class);
});
