<?php

namespace Fleetbase\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;

/**
 * Lets migrations run on MySQL 8.4 and later, where `restrict_fk_on_non_standard_key` is ON by
 * default.
 *
 * With it ON, MySQL refuses to create a foreign key that points at a non-unique key, and refuses
 * any ALTER of a table that such a key points at, with "1553 Cannot drop index '<unknown key
 * name>'". Many Fleetbase foreign keys point at `uuid` columns that have an ordinary index, so on
 * 8.4 a fresh install fails at the first of them and an upgrade fails at the first migration that
 * alters one of those tables. That is what stopped the v0.8.0 production deploy.
 *
 * While migrations run, each MySQL connection they use gets the setting turned off for its session,
 * and gets its previous value back when they finish. Nothing changes outside a migration run, or
 * where the variable doesn't exist (MySQL before 8.4, MariaDB, SQLite, Postgres).
 *
 * The lasting fix is to make those referenced columns unique. MySQL has deprecated this setting.
 */
class MigrationForeignKeyCompatibility
{
    public const VARIABLE = 'restrict_fk_on_non_standard_key';

    protected bool $migrating = false;

    /**
     * Connections whose session setting was turned off, keyed by name.
     *
     * @var array<string, Connection>
     */
    protected array $relaxed = [];

    public function __construct(protected ConnectionResolverInterface $connections)
    {
    }

    public static function register(Dispatcher $events, ConnectionResolverInterface $connections): static
    {
        $instance = new static($connections);

        $events->listen(MigrationsStarted::class, [$instance, 'migrationsStarted']);
        $events->listen(ConnectionEstablished::class, [$instance, 'connectionEstablished']);
        $events->listen(MigrationsEnded::class, [$instance, 'migrationsEnded']);

        return $instance;
    }

    /**
     * Relax every connection already open, and the default one, which the migrator points at the
     * connection it migrates (`--database`, as `sandbox:migrate` passes).
     */
    public function migrationsStarted(MigrationsStarted $event): void
    {
        $this->migrating = true;

        foreach ($this->openConnections() as $connection) {
            $this->relax($connection);
        }

        $this->relax($this->connections->connection());
    }

    /**
     * A migration that opens another connection, as the storefront migrations do, gets it relaxed too.
     */
    public function connectionEstablished(ConnectionEstablished $event): void
    {
        if ($this->migrating) {
            $this->relax($event->connection);
        }
    }

    public function migrationsEnded(MigrationsEnded $event): void
    {
        foreach ($this->relaxed as $connection) {
            try {
                $connection->statement('set session ' . static::VARIABLE . ' = ON');
            } catch (\Throwable $e) {
                // The connection may have been closed or replaced; a new session starts with the default anyway.
            }
        }

        $this->relaxed   = [];
        $this->migrating = false;
    }

    public function isMigrating(): bool
    {
        return $this->migrating;
    }

    /**
     * @return array<int, string>
     */
    public function relaxedConnections(): array
    {
        return array_keys($this->relaxed);
    }

    protected function relax(mixed $connection): void
    {
        if (!$connection instanceof Connection || $connection->getDriverName() !== 'mysql') {
            return;
        }

        $name = (string) $connection->getName();
        if (isset($this->relaxed[$name])) {
            return;
        }

        $setting = $connection->selectOne("show session variables like '" . static::VARIABLE . "'");
        if (!$setting || strtoupper((string) ($setting->Value ?? $setting->value ?? '')) !== 'ON') {
            return;
        }

        $connection->statement('set session ' . static::VARIABLE . ' = OFF');
        $this->relaxed[$name] = $connection;
    }

    /**
     * @return array<int, mixed>
     */
    protected function openConnections(): array
    {
        return method_exists($this->connections, 'getConnections') ? array_values($this->connections->getConnections()) : [];
    }
}
