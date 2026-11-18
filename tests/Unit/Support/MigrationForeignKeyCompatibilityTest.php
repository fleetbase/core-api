<?php

use Fleetbase\Support\MigrationForeignKeyCompatibility;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Events\Dispatcher;

/**
 * A MySQL connection that answers the session variable query and records every statement.
 */
class MigrationFkCompatConnectionFake extends Connection
{
    public array $statements = [];

    public function __construct(string $name, public ?string $setting = 'ON', public string $driver = 'mysql', public bool $failOnRestore = false)
    {
        parent::__construct(fn () => null, '', '', ['name' => $name]);
    }

    public function getDriverName()
    {
        return $this->driver;
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $this->statements[] = $query;

        return $this->setting === null ? null : (object) ['Variable_name' => MigrationForeignKeyCompatibility::VARIABLE, 'Value' => $this->setting];
    }

    public function statement($query, $bindings = [])
    {
        if ($this->failOnRestore && str_ends_with($query, '= ON')) {
            throw new RuntimeException('connection closed');
        }

        $this->statements[] = $query;
        if (preg_match('/= (ON|OFF)$/', $query, $match)) {
            $this->setting = $match[1];
        }

        return true;
    }
}

class MigrationFkCompatResolverFake implements ConnectionResolverInterface
{
    public function __construct(public array $open, public Connection $default)
    {
    }

    public function connection($name = null)
    {
        return $name === null ? $this->default : $this->open[$name];
    }

    public function getDefaultConnection()
    {
        return $this->default->getName();
    }

    public function setDefaultConnection($name)
    {
    }

    public function getConnections(): array
    {
        return $this->open;
    }
}

function migration_fk_compat(array $open, Connection $default): MigrationForeignKeyCompatibility
{
    return new MigrationForeignKeyCompatibility(new MigrationFkCompatResolverFake($open, $default));
}

test('migration fk compatibility relaxes mysql sessions for the run and restores them after', function () {
    $mysql   = new MigrationFkCompatConnectionFake('mysql');
    $sandbox = new MigrationFkCompatConnectionFake('sandbox');
    $compat  = migration_fk_compat(['mysql' => $mysql], $sandbox);

    $compat->migrationsStarted(new MigrationsStarted('up'));

    expect($compat->isMigrating())->toBeTrue()
        ->and($compat->relaxedConnections())->toBe(['mysql', 'sandbox'])
        ->and($mysql->setting)->toBe('OFF')
        ->and($sandbox->setting)->toBe('OFF');

    $compat->migrationsEnded(new MigrationsEnded('up'));

    expect($compat->isMigrating())->toBeFalse()
        ->and($compat->relaxedConnections())->toBe([])
        ->and($mysql->setting)->toBe('ON')
        ->and($sandbox->setting)->toBe('ON');
});

test('migration fk compatibility relaxes a connection a migration opens, only while migrating', function () {
    $default    = new MigrationFkCompatConnectionFake('mysql');
    $storefront = new MigrationFkCompatConnectionFake('storefront');
    $later      = new MigrationFkCompatConnectionFake('later');
    $compat     = migration_fk_compat([], $default);

    $compat->connectionEstablished(new ConnectionEstablished($storefront));
    expect($storefront->setting)->toBe('ON');

    $compat->migrationsStarted(new MigrationsStarted('up'));
    $compat->connectionEstablished(new ConnectionEstablished($storefront));
    $compat->connectionEstablished(new ConnectionEstablished($storefront));

    expect($storefront->setting)->toBe('OFF')
        ->and(array_filter($storefront->statements, fn ($sql) => str_starts_with($sql, 'show')))->toHaveCount(1);

    $compat->migrationsEnded(new MigrationsEnded('up'));
    $compat->connectionEstablished(new ConnectionEstablished($later));

    expect($storefront->setting)->toBe('ON')
        ->and($later->setting)->toBe('ON')
        ->and($later->statements)->toBe([]);
});

test('migration fk compatibility leaves sessions without the setting, or with it already off, alone', function () {
    $mysql80    = new MigrationFkCompatConnectionFake('mysql', null);
    $alreadyOff = new MigrationFkCompatConnectionFake('relaxed', 'OFF');
    $sqlite     = new MigrationFkCompatConnectionFake('sqlite', 'ON', 'sqlite');
    $compat     = migration_fk_compat(['relaxed' => $alreadyOff, 'sqlite' => $sqlite, 'other' => 'not a connection'], $mysql80);

    $compat->migrationsStarted(new MigrationsStarted('up'));
    $compat->migrationsEnded(new MigrationsEnded('up'));

    expect($compat->relaxedConnections())->toBe([])
        ->and($mysql80->statements)->toHaveCount(1)
        ->and($alreadyOff->setting)->toBe('OFF')
        ->and($sqlite->setting)->toBe('ON')
        ->and($sqlite->statements)->toBe([]);
});

test('migration fk compatibility finishes the run when a session cannot be restored', function () {
    $mysql  = new MigrationFkCompatConnectionFake('mysql', 'ON', 'mysql', true);
    $compat = migration_fk_compat([], $mysql);

    $compat->migrationsStarted(new MigrationsStarted('up'));
    $compat->migrationsEnded(new MigrationsEnded('up'));

    expect($compat->isMigrating())->toBeFalse()
        ->and($compat->relaxedConnections())->toBe([]);
});

test('migration fk compatibility relaxes only the default connection when the resolver lists none', function () {
    $mysql    = new MigrationFkCompatConnectionFake('mysql');
    $resolver = new class($mysql) implements ConnectionResolverInterface {
        public function __construct(public Connection $default)
        {
        }

        public function connection($name = null)
        {
            return $this->default;
        }

        public function getDefaultConnection()
        {
            return 'mysql';
        }

        public function setDefaultConnection($name)
        {
        }
    };
    $compat = new MigrationForeignKeyCompatibility($resolver);

    $compat->migrationsStarted(new MigrationsStarted('up'));

    expect($compat->relaxedConnections())->toBe(['mysql']);
});

test('migration fk compatibility registers its listeners on the dispatcher', function () {
    $events = new Dispatcher();
    $mysql  = new MigrationFkCompatConnectionFake('mysql');
    $compat = MigrationForeignKeyCompatibility::register($events, new MigrationFkCompatResolverFake([], $mysql));

    $events->dispatch(new MigrationsStarted('up'));
    expect($compat->isMigrating())->toBeTrue()
        ->and($mysql->setting)->toBe('OFF');

    $storefront = new MigrationFkCompatConnectionFake('storefront');
    $events->dispatch(new ConnectionEstablished($storefront));
    expect($storefront->setting)->toBe('OFF');

    $events->dispatch(new MigrationsEnded('up'));
    expect($compat->isMigrating())->toBeFalse()
        ->and($mysql->setting)->toBe('ON')
        ->and($storefront->setting)->toBe('ON');
});

test('core service provider registers migration fk compatibility only when events and db are bound', function () {
    $unbound = new Illuminate\Container\Container();
    (new Fleetbase\Providers\CoreServiceProvider($unbound))->registerMigrationCompatibility();

    $events = new Dispatcher();
    $mysql  = new MigrationFkCompatConnectionFake('mysql');
    $bound  = new Illuminate\Container\Container();
    $bound->instance('events', $events);
    $bound->instance('db', new MigrationFkCompatResolverFake([], $mysql));
    (new Fleetbase\Providers\CoreServiceProvider($bound))->registerMigrationCompatibility();

    expect($events->hasListeners(MigrationsStarted::class))->toBeTrue()
        ->and($events->hasListeners(ConnectionEstablished::class))->toBeTrue()
        ->and($events->hasListeners(MigrationsEnded::class))->toBeTrue();

    $events->dispatch(new MigrationsStarted('up'));
    expect($mysql->setting)->toBe('OFF');
});
