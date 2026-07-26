<?php

namespace Fleetbase\Tests\Fixtures\Models {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\SoftDeletes;

    class OrphanUuidRecord extends Model
    {
        use SoftDeletes;

        protected $table      = 'orphan_uuid_records';
        protected $primaryKey = 'uuid';
        public $incrementing  = false;
        protected $keyType    = 'string';
        protected $guarded    = [];
    }

    class OrphanIdRecord extends Model
    {
        protected $table   = 'orphan_id_records';
        protected $guarded = [];
    }

    class OrphanMissingTableRecord extends Model
    {
        protected $table = 'missing_orphan_records';
    }

    class OrphanNoPrimaryRecord extends Model
    {
        protected $table = 'orphan_no_primary_records';
    }

    class OrphanThrowingRecord extends Model
    {
        public function __construct(array $attributes = [])
        {
            throw new \RuntimeException('Cannot inspect this model.');
        }
    }
}

namespace {
    use Fleetbase\Console\Commands\PurgeOrphanedModelRecords;
    use Fleetbase\Tests\Fixtures\Models\OrphanIdRecord;
    use Fleetbase\Tests\Fixtures\Models\OrphanMissingTableRecord;
    use Fleetbase\Tests\Fixtures\Models\OrphanNoPrimaryRecord;
    use Fleetbase\Tests\Fixtures\Models\OrphanThrowingRecord;
    use Fleetbase\Tests\Fixtures\Models\OrphanUuidRecord;
    use Illuminate\Console\OutputStyle;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Eloquent\Model as EloquentModel;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\Facade;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Output\BufferedOutput;

    class PurgeOrphanedModelRecordsTestCommand extends PurgeOrphanedModelRecords
    {
        private BufferedOutput $bufferedOutput;

        public function __construct(array $tables = [
            'model_has_roles',
            'model_has_policies',
            'model_has_permissions',
        ])
        {
            parent::__construct();
            $this->tables         = $tables;
            $this->bufferedOutput = new BufferedOutput();
            $this->setOutput(new OutputStyle(new ArrayInput([]), $this->bufferedOutput));
        }

        public function purgeTableForTest(string $table): void
        {
            $this->purgeTable($table);
        }

        public function getModelPrimaryKeyForTest(string $modelClass): ?string
        {
            return $this->getModelPrimaryKey($modelClass);
        }

        public function usesSoftDeletesForTest(string $modelClass): bool
        {
            return $this->usesSoftDeletes($modelClass);
        }

        public function outputText(): string
        {
            return $this->bufferedOutput->fetch();
        }
    }

    function purge_orphaned_records_database(): Capsule
    {
        EloquentModel::clearBootedModels();

        $connection = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = bind_test_container([
            'database.default'           => 'mysql',
            'database.connections.mysql' => $connection,
            'fleetbase.connection.db'    => 'mysql',
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
        foreach (['model_has_roles', 'model_has_policies', 'model_has_permissions', 'orphan_uuid_records', 'orphan_id_records', 'orphan_no_primary_records'] as $table) {
            $schema->dropIfExists($table);
        }

        foreach (['model_has_roles', 'model_has_policies', 'model_has_permissions'] as $table) {
            $schema->create($table, function ($table) {
                $table->increments('id');
                $table->string('model_type');
                $table->string('model_uuid');
            });
        }

        $schema->create('orphan_uuid_records', function ($table) {
            $table->string('uuid')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('orphan_id_records', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $schema->create('orphan_no_primary_records', function ($table) {
            $table->string('external_key')->nullable();
            $table->string('name')->nullable();
        });

        $db = $capsule->getConnection('mysql');
        $db->table('orphan_uuid_records')->insert([
            ['uuid' => 'uuid-active', 'name' => 'Active UUID', 'created_at' => '2026-07-17 10:00:00', 'updated_at' => '2026-07-17 10:00:00', 'deleted_at' => null],
            ['uuid' => 'uuid-deleted', 'name' => 'Deleted UUID', 'created_at' => '2026-07-17 10:00:00', 'updated_at' => '2026-07-17 10:00:00', 'deleted_at' => '2026-07-17 11:00:00'],
        ]);
        $db->table('orphan_id_records')->insert([
            ['id' => 1, 'name' => 'Active ID', 'created_at' => '2026-07-17 10:00:00', 'updated_at' => '2026-07-17 10:00:00'],
        ]);
        $db->table('model_has_roles')->insert([
            ['model_type' => OrphanUuidRecord::class, 'model_uuid' => 'uuid-active'],
            ['model_type' => OrphanUuidRecord::class, 'model_uuid' => 'uuid-deleted'],
            ['model_type' => OrphanUuidRecord::class, 'model_uuid' => 'uuid-missing'],
            ['model_type' => OrphanIdRecord::class, 'model_uuid' => '1'],
            ['model_type' => OrphanIdRecord::class, 'model_uuid' => '999'],
            ['model_type' => 'Fleetbase\\Tests\\Fixtures\\Models\\ClassDoesNotExist', 'model_uuid' => 'ghost'],
        ]);
        $db->table('model_has_policies')->insert([
            ['model_type' => OrphanNoPrimaryRecord::class, 'model_uuid' => 'external-1'],
        ]);

        return $capsule;
    }

    afterEach(function () {
        EloquentModel::clearBootedModels();
        Facade::clearResolvedInstances();
    });

    it('detects model primary keys and soft delete support safely', function () {
        purge_orphaned_records_database();

        $command = new PurgeOrphanedModelRecordsTestCommand();

        expect($command->getModelPrimaryKeyForTest(OrphanUuidRecord::class))->toBe('uuid')
            ->and($command->getModelPrimaryKeyForTest(OrphanIdRecord::class))->toBe('id')
            ->and($command->getModelPrimaryKeyForTest(OrphanNoPrimaryRecord::class))->toBeNull()
            ->and($command->getModelPrimaryKeyForTest(OrphanMissingTableRecord::class))->toBeNull()
            ->and($command->getModelPrimaryKeyForTest(OrphanThrowingRecord::class))->toBeNull()
            ->and($command->usesSoftDeletesForTest(OrphanUuidRecord::class))->toBeTrue()
            ->and($command->usesSoftDeletesForTest(OrphanIdRecord::class))->toBeFalse()
            ->and($command->usesSoftDeletesForTest('Fleetbase\\Tests\\Fixtures\\Models\\ClassDoesNotExist'))->toBeFalse();
    });

    it('deletes missing and soft-deleted model references while preserving valid and unknown class records', function () {
        $capsule = purge_orphaned_records_database();
        $db      = $capsule->getConnection('mysql');
        $command = new PurgeOrphanedModelRecordsTestCommand();

        $command->purgeTableForTest('model_has_roles');

        $remaining = $db->table('model_has_roles')
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => [$record->model_type, $record->model_uuid])
            ->all();
        $output = $command->outputText();

        expect($remaining)->toBe([
            [OrphanUuidRecord::class, 'uuid-active'],
            [OrphanIdRecord::class, '1'],
            ['Fleetbase\\Tests\\Fixtures\\Models\\ClassDoesNotExist', 'ghost'],
        ])
            ->and($output)->toContain('Deleted orphaned record from model_has_roles where model_type = ' . OrphanUuidRecord::class . ' and model_uuid = uuid-deleted')
            ->and($output)->toContain('Deleted orphaned record from model_has_roles where model_type = ' . OrphanUuidRecord::class . ' and model_uuid = uuid-missing')
            ->and($output)->toContain('Deleted orphaned record from model_has_roles where model_type = ' . OrphanIdRecord::class . ' and model_uuid = 999')
            ->and($output)->toContain('Model class Fleetbase\\Tests\\Fixtures\\Models\\ClassDoesNotExist does not exist, skipping...')
            ->and($output)->toContain('Finished checking model_has_roles. 3 orphaned records deleted.');
    });

    it('handles missing pivot tables and completes the purge command', function () {
        $capsule = purge_orphaned_records_database();
        $db      = $capsule->getConnection('mysql');
        $command = new PurgeOrphanedModelRecordsTestCommand(['model_has_roles', 'model_has_policies', 'missing_model_pivot']);

        $result = $command->handle();
        $output = $command->outputText();

        expect($result)->toBeNull()
            ->and($db->table('model_has_roles')->count())->toBe(3)
            ->and($db->table('model_has_policies')->count())->toBe(1)
            ->and($output)->toContain('Starting orphaned model record purge...')
            ->and($output)->toContain('Could not determine primary key for ' . OrphanNoPrimaryRecord::class . ', skipping...')
            ->and($output)->toContain('Table missing_model_pivot does not exist, skipping...')
            ->and($output)->toContain('Purge process completed.');
    });
}
