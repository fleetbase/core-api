<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Facade;

function fk_targets_migration(string $file = '2026_11_18_000001_make_core_foreign_key_targets_unique'): object
{
    return require dirname(__DIR__, 3) . '/migrations/' . $file . '.php';
}

function fk_targets_schema(string $name = 'mysql'): Builder
{
    $container = bind_test_container([
        'fleetbase.connection.sandbox' => 'sandbox',
        'permission.table_names'       => ['permissions' => 'permissions', 'roles' => 'roles'],
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], $name);
    $capsule->setAsGlobal();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection($name);
    $container['config']->set('database.default', $name);
    $schema = $capsule->getConnection($name)->getSchemaBuilder();
    $container->instance('db', $databaseManager);
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstances();

    return $schema;
}

/**
 * @return array<string, bool> single-column indexes on `$column`, name => unique
 */
function fk_targets_indexes(Builder $schema, string $table, string $column): array
{
    $indexes = [];
    foreach ($schema->getIndexes($table) as $index) {
        if ($index['columns'] === [$column]) {
            $indexes[$index['name']] = (bool) $index['unique'];
        }
    }
    ksort($indexes);

    return $indexes;
}

function fk_targets_plain_table(Builder $schema, string $table, string $column = 'uuid'): void
{
    $schema->create($table, function (Blueprint $blueprint) use ($column) {
        $blueprint->increments('row');
        $blueprint->char($column, 36)->nullable()->index();
    });
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('it targets every core column a foreign key points at', function () {
    fk_targets_schema();

    expect(fk_targets_migration()->targets())->toBe([
        ['chat_channels', 'uuid'],
        ['chat_messages', 'uuid'],
        ['chat_participants', 'uuid'],
        ['comments', 'uuid'],
        ['custom_fields', 'uuid'],
        ['dashboards', 'uuid'],
        ['reports', 'uuid'],
        ['permissions', 'id'],
        ['roles', 'id'],
    ]);
});

test('it swaps the plain index for a unique one and can be reversed', function () {
    $schema = fk_targets_schema();
    fk_targets_plain_table($schema, 'chat_channels');
    fk_targets_plain_table($schema, 'permissions', 'id');
    $schema->getConnection()->table('chat_channels')->insert([['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => null], ['uuid' => null]]);

    $migration = fk_targets_migration();
    $migration->up();

    expect(fk_targets_indexes($schema, 'chat_channels', 'uuid'))->toBe(['chat_channels_uuid_unique' => true])
        ->and(fk_targets_indexes($schema, 'permissions', 'id'))->toBe(['permissions_id_unique' => true])
        ->and($schema->getConnection()->table('chat_channels')->count())->toBe(4)
        ->and($migration->skipped)->toBe([]);

    $migration->up();
    expect(fk_targets_indexes($schema, 'chat_channels', 'uuid'))->toBe(['chat_channels_uuid_unique' => true]);

    $migration->down();
    expect(fk_targets_indexes($schema, 'chat_channels', 'uuid'))->toBe(['chat_channels_uuid_index' => false])
        ->and(fk_targets_indexes($schema, 'permissions', 'id'))->toBe(['permissions_id_index' => false]);

    $migration->down();
    expect(fk_targets_indexes($schema, 'chat_channels', 'uuid'))->toBe(['chat_channels_uuid_index' => false]);
});

test('it leaves a column that a unique or primary key already covers alone', function () {
    $schema = fk_targets_schema();
    $schema->create('comments', function (Blueprint $blueprint) {
        $blueprint->increments('row');
        $blueprint->char('uuid', 36)->index();
        $blueprint->unique(['uuid'], 'comments_uuid_key');
    });
    $schema->create('dashboards', function (Blueprint $blueprint) {
        $blueprint->char('uuid', 36)->primary();
    });

    $migration = fk_targets_migration();
    $migration->up();

    expect(fk_targets_indexes($schema, 'comments', 'uuid'))->toBe(['comments_uuid_index' => false, 'comments_uuid_key' => true])
        ->and(collect($schema->getIndexes('dashboards'))->where('primary', true)->pluck('columns')->all())->toBe([['uuid']])
        ->and(fk_targets_indexes($schema, 'dashboards', 'uuid'))->not->toHaveKey('dashboards_uuid_unique');

    // down() only removes the index this migration names, so the existing unique key stays.
    $migration->down();
    expect(fk_targets_indexes($schema, 'comments', 'uuid'))->toBe(['comments_uuid_index' => false, 'comments_uuid_key' => true]);
});

test('it skips a table with duplicate values and says why', function () {
    $schema = fk_targets_schema();
    fk_targets_plain_table($schema, 'reports');
    fk_targets_plain_table($schema, 'custom_fields');
    $schema->getConnection()->table('reports')->insert([['uuid' => 'dup'], ['uuid' => 'dup'], ['uuid' => 'other']]);

    $migration = fk_targets_migration();
    $migration->up();

    expect(fk_targets_indexes($schema, 'reports', 'uuid'))->toBe(['reports_uuid_index' => false])
        ->and(fk_targets_indexes($schema, 'custom_fields', 'uuid'))->toBe(['custom_fields_uuid_unique' => true])
        ->and($migration->skipped)->toHaveCount(1)
        ->and($migration->skipped[0])->toContain('reports.uuid')->toContain('duplicate');
});

test('it ignores tables and columns that do not exist', function () {
    $schema = fk_targets_schema();
    $schema->create('roles', function (Blueprint $blueprint) {
        $blueprint->increments('row');
    });

    $migration = fk_targets_migration();
    $migration->up();
    $migration->down();

    expect($schema->hasTable('chat_channels'))->toBeFalse()
        ->and($schema->hasColumn('roles', 'id'))->toBeFalse()
        ->and(collect($schema->getIndexes('roles'))->pluck('columns')->all())->toBe([['row']])
        ->and($migration->skipped)->toBe([]);
});

test('the sandbox foreign key migration does nothing on the main connection', function () {
    $schema = fk_targets_schema('mysql');
    $schema->create('api_events', function (Blueprint $blueprint) {
        $blueprint->increments('id');
        $blueprint->unsignedBigInteger('access_token_id')->nullable();
    });

    $migration = fk_targets_migration('2026_11_18_000002_drop_sandbox_access_token_foreign_keys');
    $migration->up();
    $migration->down();

    expect($schema->hasColumn('api_events', 'access_token_id'))->toBeTrue()
        ->and($schema->getForeignKeys('api_events'))->toBe([]);
});

test('the sandbox foreign key migration does nothing where personal access tokens exist', function () {
    $schema = fk_targets_schema('sandbox');
    $schema->create('personal_access_tokens', function (Blueprint $blueprint) {
        $blueprint->id();
    });
    $schema->create('api_events', function (Blueprint $blueprint) {
        $blueprint->increments('row');
        $blueprint->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens');
    });

    $migration = fk_targets_migration('2026_11_18_000002_drop_sandbox_access_token_foreign_keys');
    $migration->up();
    $migration->down();

    expect($schema->getForeignKeys('api_events'))->toHaveCount(1);
});

test('the sandbox foreign key migration keeps the columns and can put the keys back', function () {
    $schema = fk_targets_schema('sandbox');
    foreach (['api_events', 'api_request_logs', 'webhook_request_logs'] as $table) {
        $schema->create($table, function (Blueprint $blueprint) {
            $blueprint->increments('row');
            $blueprint->unsignedBigInteger('access_token_id')->nullable();
        });
    }

    $migration = fk_targets_migration('2026_11_18_000002_drop_sandbox_access_token_foreign_keys');
    $migration->up();

    expect($schema->hasColumn('api_events', 'access_token_id'))->toBeTrue()
        ->and($schema->getForeignKeys('api_events'))->toBe([]);

    $migration->down();

    expect(collect($schema->getForeignKeys('webhook_request_logs'))->pluck('foreign_table')->all())->toBe(['personal_access_tokens'])
        ->and($schema->hasTable('personal_access_tokens'))->toBeFalse();
});
