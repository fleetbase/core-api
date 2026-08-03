<?php

use Fleetbase\Console\Commands\SyncSandbox;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

class SyncSandboxCommandContainer extends FleetbaseTestContainer
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}

function sync_sandbox_database(): Capsule
{
    EloquentModel::clearBootedModels();
    Container::setInstance(new SyncSandboxCommandContainer());

    $mysql = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];
    $sandbox = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'mysql',
        'database.connections.mysql'   => $mysql,
        'database.connections.sandbox' => $sandbox,
        'fleetbase.connection.db'      => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($mysql, 'mysql');
    $capsule->addConnection($sandbox, 'sandbox');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    EloquentModel::unsetEventDispatcher();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstances();

    foreach (['mysql', 'sandbox'] as $connection) {
        $schema = $capsule->getConnection($connection)->getSchemaBuilder();
        $schema->create('users', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('companies', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('company_users', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('user_uuid')->nullable();
            $table->string('status')->nullable();
            $table->boolean('external')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('api_credentials', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('_key')->nullable();
            $table->string('user_uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('key')->nullable();
            $table->string('secret')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->string('api')->nullable();
            $table->text('browser_origins')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    return $capsule;
}

afterEach(function () {
    EloquentModel::unsetEventDispatcher();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('syncs production records into sandbox and filters api credentials to test mode', function () {
    $capsule = sync_sandbox_database();
    $mysql   = $capsule->getConnection('mysql');
    $sandbox = $capsule->getConnection('sandbox');

    $mysql->table('users')->insert([
        [
            'uuid'         => 'user-1',
            'public_id'    => 'user_public',
            'company_uuid' => 'company-1',
            'name'         => 'Sandbox User',
            'email'        => 'sandbox@example.test',
            'created_at'   => '2026-07-17 08:00:00',
            'updated_at'   => '2026-07-18 08:00:00',
        ],
        [
            'uuid'         => null,
            'public_id'    => 'user_without_uuid',
            'company_uuid' => 'company-1',
            'name'         => 'Missing UUID User',
            'email'        => 'missing-uuid@example.test',
            'created_at'   => '2026-07-17 08:00:00',
            'updated_at'   => '2026-07-18 08:00:00',
        ],
    ]);
    $mysql->table('companies')->insert([
        'uuid'       => 'company-1',
        'public_id'  => 'company_public',
        'name'       => 'Acme Logistics',
        'owner_uuid' => 'user-1',
        'created_at' => '2026-07-17 08:00:00',
        'updated_at' => '2026-07-18 08:00:00',
    ]);
    $mysql->table('company_users')->insert([
        'uuid'         => 'company-user-1',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'user-1',
        'status'       => 'active',
        'external'     => false,
        'created_at'   => '2026-07-17 08:00:00',
        'updated_at'   => '2026-07-18 08:00:00',
    ]);
    $mysql->table('api_credentials')->insert([
        [
            'uuid'            => 'credential-test',
            'public_id'       => 'cred_test',
            '_key'            => 'flb_test_key',
            'user_uuid'       => 'user-1',
            'company_uuid'    => 'company-1',
            'name'            => 'Test Credential',
            'key'             => 'test-key',
            'secret'          => 'secret',
            'test_mode'       => true,
            'api'             => 'v1',
            'browser_origins' => json_encode(['https://fleetbase.test']),
            'last_used_at'    => '2026-07-18 09:00:00',
            'expires_at'      => '2026-08-18 09:00:00', // date-drift-ok: SyncSandbox reads withoutGlobalScopes(), so ExpiryScope never applies
            'created_at'      => '2026-07-17 08:00:00',
            'updated_at'      => '2026-07-18 08:00:00',
        ],
        [
            'uuid'            => 'credential-live',
            'public_id'       => 'cred_live',
            '_key'            => 'flb_live_key',
            'user_uuid'       => 'user-1',
            'company_uuid'    => 'company-1',
            'name'            => 'Live Credential',
            'key'             => 'live-key',
            'secret'          => 'secret',
            'test_mode'       => false,
            'api'             => 'v1',
            'browser_origins' => json_encode(['https://live.test']),
            'last_used_at'    => '2026-07-18 09:00:00',
            'expires_at'      => '2026-08-18 09:00:00', // date-drift-ok: SyncSandbox reads withoutGlobalScopes(), so ExpiryScope never applies
            'created_at'      => '2026-07-17 08:00:00',
            'updated_at'      => '2026-07-18 08:00:00',
        ],
    ]);
    $sandbox->table('users')->insert([
        'uuid' => 'stale-user',
        'name' => 'Stale User',
    ]);

    $command = new SyncSandbox();
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute(['--truncate' => true]))->toBe(0)
        ->and($sandbox->table('users')->pluck('uuid')->all())->toBe(['user-1'])
        ->and($sandbox->table('companies')->pluck('uuid')->all())->toBe(['company-1'])
        ->and($sandbox->table('company_users')->pluck('uuid')->all())->toBe(['company-user-1'])
        ->and($sandbox->table('api_credentials')->pluck('uuid')->all())->toBe(['credential-test'])
        ->and($sandbox->table('api_credentials')->value('browser_origins'))->toBe(json_encode(['https://fleetbase.test']))
        ->and($tester->getDisplay())->toContain('User: Sandbox User (sandbox@example.test) cloned and synced to sandbox')
        ->and($tester->getDisplay())->toContain('Sync completed.');
});
