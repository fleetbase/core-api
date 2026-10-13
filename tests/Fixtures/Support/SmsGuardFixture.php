<?php

namespace Fleetbase\Tests\Fixtures\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

/**
 * Database fixtures shared by the SMS guard tests: an in-memory sqlite behind a test
 * container, the ledger table, and the verification code table with its tracing columns.
 */
final class SmsGuardFixture
{
    /**
     * A container with an array cache and a sqlite `mysql` connection; the ledger table is
     * created when `$withLedger` is true. Callers add their own tables.
     */
    public static function database(array $config = [], bool $withLedger = true): Capsule
    {
        EloquentModel::clearBootedModels();

        $connection = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = bind_test_container(array_merge([
            'database.default'           => 'mysql',
            'database.connections.mysql' => $connection,
            'fleetbase.connection.db'    => 'mysql',
        ], $config));
        $container->instance('cache', new CacheRepository(new ArrayStore()));
        Facade::clearResolvedInstances();

        $capsule = new Capsule($container);
        $capsule->addConnection($connection, 'mysql');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstance('db');

        if ($withLedger) {
            self::smsSendsSchema($capsule);
        }

        return $capsule;
    }

    public static function smsSendsSchema(Capsule $capsule, string $connection = 'mysql'): void
    {
        $capsule->getConnection($connection)->getSchemaBuilder()->create('sms_sends', function ($table) {
            $table->string('uuid')->primary();
            $table->string('company_uuid')->nullable();
            $table->string('tenant_type')->nullable();
            $table->string('tenant_uuid')->nullable();
            $table->string('purpose');
            $table->string('source');
            $table->string('destination')->nullable();
            $table->string('destination_hash')->nullable();
            $table->string('destination_country')->nullable();
            $table->string('destination_type')->nullable();
            $table->string('status');
            $table->string('block_reason')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_uuid')->nullable();
            $table->string('verification_code_uuid')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The verification_codes table as it looks after the October 2026 tracing migration.
     */
    public static function verificationCodesSchema(Capsule $capsule, string $connection = 'mysql'): void
    {
        $capsule->getConnection($connection)->getSchemaBuilder()->create('verification_codes', function ($table) {
            $table->string('uuid')->primary();
            $table->string('subject_uuid')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('identity')->nullable();
            $table->string('channel')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('sms_send_uuid')->nullable();
            $table->string('code')->nullable();
            $table->string('for')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->text('meta')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public static function companiesSchema(Capsule $capsule, array $rows = [], string $connection = 'mysql'): void
    {
        $capsule->getConnection($connection)->getSchemaBuilder()->create('companies', function ($table) {
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('options')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        if ($rows) {
            $capsule->getConnection($connection)->table('companies')->insert($rows);
        }
    }

    public static function usersSchema(Capsule $capsule, array $rows = [], string $connection = 'mysql'): void
    {
        $capsule->getConnection($connection)->getSchemaBuilder()->create('users', function ($table) {
            $table->string('uuid')->primary();
            $table->string('company_uuid')->nullable();
            $table->string('type')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        if ($rows) {
            $capsule->getConnection($connection)->table('users')->insert($rows);
        }
    }

    public static function settingsSchema(Capsule $capsule, string $connection = 'mysql'): void
    {
        $capsule->getConnection($connection)->getSchemaBuilder()->create('settings', function ($table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });
    }
}
