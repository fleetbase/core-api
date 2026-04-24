<?php

namespace Fleetbase\Tests;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Migrations\Migrator;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base TestCase for Fleetbase core-api Pest/PHPUnit feature tests.
 *
 * Uses Orchestra Testbench to boot a minimal Laravel application so that
 * Laravel facades (Schema, DB, Cache, etc.) are available in tests without
 * requiring the full Fleetbase stack to be running.
 *
 * The Fleetbase CoreServiceProvider is registered, which auto-loads every
 * migration under `core-api/migrations/` via its `boot()` method. When a
 * test uses `Illuminate\Foundation\Testing\RefreshDatabase`, those
 * migrations are applied to an in-memory SQLite database for each test.
 *
 * SQLite compatibility notes
 * --------------------------
 * A few production migrations contain MySQL-only syntax that SQLite cannot
 * execute. The Fleetbase codebase already accepts this as normal (see
 * `migrations/2025_08_28_045009_noramlize_uuid_foreign_key_columns.php`
 * which explicitly bails out on non-MySQL drivers). To let the remaining
 * migrations run end-to-end against an in-memory SQLite test database, this
 * TestCase:
 *
 *   1. Registers a `DATE_FORMAT(datetime, format)` user-defined function on
 *      the SQLite PDO handle so that the `UPDATE transactions SET period = ...`
 *      statement in `2024_01_01_000001_improve_transactions_table.php`
 *      resolves.
 *   2. Swaps the default `migrator` container binding for a filtering
 *      subclass that skips the two migrations whose raw
 *      `ALTER TABLE ... MODIFY COLUMN` statements SQLite cannot parse
 *      (`2024_01_01_000002_improve_transaction_items_table.php` and
 *      `2025_08_28_045009_noramlize_uuid_foreign_key_columns.php`, which
 *      already no-ops on non-MySQL anyway).
 *
 * These shims exist only in the test bootstrap. Production migration runs
 * against MySQL are entirely unaffected.
 *
 * Intended to be reused by feature tests across the Phase 1 multi-tenant
 * work (companies hierarchy, company_users pivot, rate_contracts.is_shared,
 * ScopedToCompanyContext, CompanyContextResolver middleware, etc.).
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Migration basenames (sans .php) that rely on MySQL-only syntax
     * (`ALTER TABLE ... MODIFY COLUMN`, etc.) and therefore cannot run
     * against SQLite. These are skipped entirely by the test migrator.
     *
     * @var array<int, string>
     */
    protected array $sqliteIncompatibleMigrations = [
        '2024_01_01_000002_improve_transaction_items_table',
        '2025_08_28_045009_noramlize_uuid_foreign_key_columns',
    ];

    /**
     * Register service providers required by Fleetbase core-api tests.
     *
     * Sanctum is registered first so that its `personal_access_tokens`
     * migration runs before the Fleetbase `fix_personal_access_tokens`
     * migration that alters that table.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \Spatie\Permission\PermissionServiceProvider::class,
            \Fleetbase\Providers\CoreServiceProvider::class,
        ];
    }

    /**
     * Define environment setup for the Testbench Laravel application.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        // Mark the environment as `testing` so CoreServiceProvider::scheduleCommands()
        // and ::pingTelemetry() short-circuit instead of trying to boot the
        // scheduler / phone home.
        $app['config']->set('app.env', 'testing');

        // Fleetbase config defaults that CoreServiceProvider or downstream code
        // may read. Keep these minimal and sensible for a test environment.
        $app['config']->set('fleetbase.api.version', 'test');
        $app['config']->set('fleetbase.console.host', 'http://localhost');
        $app['config']->set('fleetbase.instance_id', 'test-instance');
        $app['config']->set('fleetbase.connection.sandbox', 'sandbox');

        // Disable external services that might be hit incidentally.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.default', 'array');

        // Spatie laravel-permission: keep teams off for the core test
        // bootstrap; individual tests can override if needed. Fleetbase's
        // own permission config rebinds `model_morph_key` to `model_uuid`,
        // but because Spatie's PermissionServiceProvider is registered
        // first (so Sanctum's migration table is present before
        // Fleetbase's `fix_personal_access_tokens` migration runs), its
        // defaults win in `mergeConfigFrom`. Force the Fleetbase values
        // explicitly so the custom `create_permissions_table` migration
        // creates `model_uuid` columns as it does in production.
        $app['config']->set('permission.teams', false);
        $app['config']->set('permission.column_names.model_morph_key', 'model_uuid');
        $app['config']->set('permission.column_names.team_foreign_key', 'team_id');

        // Install a DATE_FORMAT() polyfill on every SQLite connection the
        // moment it is established, so migrations running during
        // RefreshDatabase see it before any `UPDATE ... DATE_FORMAT(...)`
        // statement fires.
        $app['events']->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $connection = $event->connection;
            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $pdo = $connection->getPdo();
            if (! method_exists($pdo, 'sqliteCreateFunction')) {
                return;
            }

            $pdo->sqliteCreateFunction('DATE_FORMAT', static function ($datetime, $format) {
                if ($datetime === null) {
                    return null;
                }
                $timestamp = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
                if ($timestamp === false) {
                    return null;
                }
                $map = [
                    '%Y' => 'Y', '%y' => 'y',
                    '%m' => 'm', '%c' => 'n',
                    '%d' => 'd', '%e' => 'j',
                    '%H' => 'H', '%h' => 'h', '%i' => 'i', '%s' => 's',
                    '%p' => 'A',
                ];

                return date(strtr((string) $format, $map), $timestamp);
            }, 2);
        });

        // Swap in a filtering migrator that skips known MySQL-only migrations
        // so migrate:fresh can complete against SQLite. We pull the resolver
        // out of the default migrator via reflection because Migrator exposes
        // getRepository() and getFilesystem() but not its connection resolver.
        $skip = $this->sqliteIncompatibleMigrations;
        $app->extend('migrator', function (Migrator $migrator) use ($skip) {
            $resolverRef = new \ReflectionProperty(Migrator::class, 'resolver');
            $resolverRef->setAccessible(true);
            $resolver = $resolverRef->getValue($migrator);

            $eventsRef = new \ReflectionProperty(Migrator::class, 'events');
            $eventsRef->setAccessible(true);
            $events = $eventsRef->getValue($migrator);

            $filtered = new class(
                $migrator->getRepository(),
                $resolver,
                $migrator->getFilesystem(),
                $events,
                $skip,
            ) extends Migrator {
                /** @var array<int, string> */
                protected array $skipNames;

                public function __construct($repository, $resolver, $files, $events, array $skipNames)
                {
                    parent::__construct($repository, $resolver, $files, $events);
                    $this->skipNames = $skipNames;
                }

                /**
                 * {@inheritdoc}
                 */
                public function getMigrationFiles($paths)
                {
                    return array_filter(
                        parent::getMigrationFiles($paths),
                        fn (string $_file, string $name) => ! in_array($name, $this->skipNames, true),
                        ARRAY_FILTER_USE_BOTH,
                    );
                }
            };

            // Carry over any paths already registered (e.g. by CoreServiceProvider::boot).
            foreach ($migrator->paths() as $path) {
                $filtered->path($path);
            }

            return $filtered;
        });
    }

    /**
     * Boot the Testbench application and install two test-only shims that
     * keep Fleetbase's production models runnable against an in-memory
     * SQLite database without pulling in the full Fleetbase stack.
     *
     * Shim 1 — `responsecache` noop binding:
     *   Fleetbase's base Eloquent Model mixes in the ClearsHttpCache trait,
     *   which registers HttpCacheObserver on every save event. That
     *   observer resolves the `responsecache` container alias from Spatie's
     *   ResponseCacheServiceProvider, which is intentionally NOT registered
     *   in this Testbench bootstrap (we don't want response-cache plumbing
     *   in tests). Without this binding the first Model::create() throws
     *   `BindingResolutionException: Target class [responsecache] does not
     *   exist`. The noop stand-in satisfies the observer without side
     *   effects.
     *
     * Shim 2 — alias the `mysql` connection to sqlite:
     *   `Fleetbase\Models\User` extends Authenticatable (not Fleetbase's
     *   base Model), so it never receives the constructor override that
     *   rewrites `$connection` to the test env's sqlite. Any query that
     *   resolves `$user->getConnectionName() === 'mysql'` would otherwise
     *   die with `PDOException: Connection refused` trying to reach
     *   `127.0.0.1:3306`. Point the `mysql` connection name at the already
     *   booted sqlite connection in the DB manager so relation traversal
     *   and CompanyUser::create(['user_uuid' => ...]) work.
     *
     * Must run from setUp() (not defineEnvironment) so that `$this->app`
     * is fully booted and the DB manager has an active sqlite connection
     * to alias against.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Shim 1: noop `responsecache` binding for ClearsHttpCache observer.
        $this->app->singleton('responsecache', function () {
            return new class {
                public function clear(array $tags = []): self
                {
                    return $this;
                }

                public function __call($name, $arguments)
                {
                    return $this;
                }
            };
        });

        // Shim 2: alias the `mysql` connection to the booted sqlite
        // connection so User and other Authenticatable-extending models
        // with hardcoded `$connection = 'mysql'` resolve against sqlite.
        $default = \Illuminate\Support\Facades\DB::connection();
        $manager = $this->app->make('db');
        $ref     = new \ReflectionProperty($manager, 'connections');
        $ref->setAccessible(true);
        $connections          = $ref->getValue($manager);
        $connections['mysql'] = $default;
        $ref->setValue($manager, $connections);
    }
}
