<?php

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Observers\UserObserver;
use Fleetbase\Services\UserCacheService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class UserObserverCacheFake
{
    public array $forgotten = [];

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;

        return true;
    }
}

class UserObserverLogFake
{
    public array $entries = [];

    public function debug(string $message, array $context = []): void
    {
        $this->entries[] = ['debug', $message, $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['error', $message, $context];
    }
}

function user_observer_database(): Capsule
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

    $cache = new UserObserverCacheFake();
    $log   = new UserObserverLogFake();
    $container->instance('cache', $cache);
    $container->instance('log', $log);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $connection = $capsule->getConnection('mysql');
    $connection->table('users')->insert([
        'uuid'       => 'user-1',
        'updated_at' => '2026-07-17 10:00:00',
        'deleted_at' => null,
    ]);
    $connection->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Company One'],
        ['uuid' => 'company-2', 'name' => 'Company Two'],
        ['uuid' => 'company-session', 'name' => 'Session Company'],
    ]);
    $connection->table('company_users')->insert([
        ['uuid' => 'cache-company-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'deleted_at' => null],
        ['uuid' => 'cache-company-2', 'company_uuid' => 'company-2', 'user_uuid' => 'user-1', 'deleted_at' => null],
        ['uuid' => 'session-company-user', 'company_uuid' => 'company-session', 'user_uuid' => 'user-1', 'deleted_at' => null],
    ]);

    session()->flush();

    return $capsule;
}

function user_observer_subject(): array
{
    $capsule = user_observer_database();
    $user    = User::query()->where('uuid', 'user-1')->firstOrFail();

    return [$user, app('cache'), app('log'), $capsule, new UserObserver()];
}

afterEach(function () {
    session()->flush();
    Facade::clearResolvedInstances();
});

it('invalidates user current cache and organization cache when users update or restore', function (string $event) {
    [$user, $cache, $log, , $observer] = user_observer_subject();

    $observer->{$event}($user);

    expect($cache->forgotten)->toContain(
        UserCacheService::getCacheKey($user, 'company-1'),
        UserCacheService::getCacheKey($user, 'company-2'),
        UserCacheService::getCacheKey($user, 'company-session'),
        'user_organizations_user-1',
        'user_organizations_v2_user-1',
    )
        ->and(collect($log->entries)->where(1, 'User cache invalidated')->count())->toBe(3);
})->with(['updated', 'restored']);

it('deletes only the active session company membership when a user is deleted', function () {
    [$user, $cache, , $capsule, $observer] = user_observer_subject();
    session(['company' => 'company-session']);

    $observer->deleted($user);

    $remaining = CompanyUser::query()->pluck('uuid')->all();

    $deletedAt = $capsule->getConnection('mysql')
        ->table('company_users')
        ->where('uuid', 'session-company-user')
        ->value('deleted_at');

    expect($cache->forgotten)->toContain('user_organizations_user-1', 'user_organizations_v2_user-1')
        ->and($remaining)->toContain('cache-company-1', 'cache-company-2')
        ->and($remaining)->not->toContain('session-company-user')
        ->and($deletedAt)->not->toBeNull();
});
