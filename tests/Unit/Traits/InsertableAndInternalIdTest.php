<?php

use Fleetbase\Support\Utils;
use Fleetbase\Traits\HasInternalId;
use Fleetbase\Traits\HasSessionAttributes;
use Fleetbase\Traits\Insertable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class InternalIdTraitRecord extends Model
{
    use HasInternalId;
    use SoftDeletes;

    protected $connection = 'mysql';
    protected $table      = 'internal_id_trait_records';
    protected $guarded    = [];
    public $timestamps    = false;
}

class InsertableTraitRecord extends Model
{
    use HasSessionAttributes;
    use Insertable;
    use SoftDeletes;

    protected $connection                = 'mysql';
    protected $table                     = 'insertable_trait_records';
    protected $fillable                  = ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name', 'created_at'];
    public $timestamps                   = false;
    public static int $flushes           = 0;
    public static int $uuidCounter       = 0;
    public static int $publicIdCounter   = 0;
    public static int $internalIdCounter = 0;

    public static function generateUuid($column = 'uuid'): string
    {
        return 'uuid-' . ++static::$uuidCounter;
    }

    public static function generatePublicId(?string $type = null, int $attempt = 0): string
    {
        return 'record_' . ++static::$publicIdCounter;
    }

    public static function generateInternalId($initialInternalId = null, $append = null): string
    {
        return 'INT-' . ++static::$internalIdCounter;
    }

    public static function onRowInsert(array $row): array
    {
        $row['name'] = strtoupper($row['name']);

        return $row;
    }

    public function fillSessionAttributes(?array $target = [], array $except = [], array $only = []): array
    {
        $target['company_uuid'] = session('company');

        return $target;
    }

    public function flushCache(): void
    {
        static::$flushes++;
    }
}

function insertable_internal_id_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connectionConfig,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->softDeletes();
    });
    $schema->create('internal_id_trait_records', function ($table) {
        $table->increments('id');
        $table->string('internal_id')->nullable();
        $table->softDeletes();
    });
    $schema->create('insertable_trait_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->softDeletes();
    });

    session()->flush();
    InsertableTraitRecord::$flushes           = 0;
    InsertableTraitRecord::$uuidCounter       = 0;
    InsertableTraitRecord::$publicIdCounter   = 0;
    InsertableTraitRecord::$internalIdCounter = 0;

    return $capsule;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('has internal id generates ids from explicit prefixes and session company names', function () {
    $capsule = insertable_internal_id_database();
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Acme Logistics', 'deleted_at' => null],
        ['uuid' => 'company-2', 'name' => 'Acme', 'deleted_at' => null],
    ]);

    $fallbackId = InternalIdTraitRecord::generateInternalId();

    session(['company' => 'company-1']);

    $arrayId    = InternalIdTraitRecord::generateInternalId(['prepend' => 'PRE-', 'append' => '-A']);
    $companyId  = InternalIdTraitRecord::generateInternalId();
    $explicitId = InternalIdTraitRecord::generateInternalId('INV-', '-B');

    session(['company' => 'company-2']);
    $singleWordCompanyId = InternalIdTraitRecord::generateInternalId();

    expect($fallbackId)->toBeString()->toHaveLength(6)
        ->and($arrayId)->toStartWith('PRE-')->toEndWith('-A')
        ->and($companyId)->toStartWith('AL')
        ->and($singleWordCompanyId)->toStartWith('AC')
        ->and($explicitId)->toStartWith('INV-')->toEndWith('-B');

    $record = new InternalIdTraitRecord(['internal_id' => ['prepend' => 'JOB-', 'append' => '-Z']]);
    $record->save();
    $manualRecord = new InternalIdTraitRecord(['internal_id' => 'MANUAL-001']);
    $manualRecord->save();

    mt_srand(24680);
    $collidingId = 'JOB-' . Utils::randomNumber(6) . '-Z';
    InternalIdTraitRecord::query()->create(['internal_id' => $collidingId]);
    mt_srand(24680);
    $retryId = InternalIdTraitRecord::makeInternalId('JOB-', '-Z');
    mt_srand();

    expect($record->internal_id)->toStartWith('JOB-')->toEndWith('-Z')
        ->and($retryId)->toStartWith('JOB-')->toEndWith('-Z')
        ->and($retryId)->not->toBe($collidingId)
        ->and($manualRecord->internal_id)->toBe('MANUAL-001')
        ->and($capsule->getConnection('mysql')->table('internal_id_trait_records')->where('internal_id', $record->internal_id)->exists())->toBeTrue()
        ->and($capsule->getConnection('mysql')->table('internal_id_trait_records')->where('internal_id', 'MANUAL-001')->exists())->toBeTrue();
});

test('insertable bulk insert enriches rows removes unsafe attributes applies hooks and flushes cache', function () {
    insertable_internal_id_database();
    Carbon::setTestNow(Carbon::parse('2026-07-18 12:34:56'));
    session(['company' => 'company-1']);

    $result = InsertableTraitRecord::bulkInsert([
        [
            'name'          => 'alpha',
            'unsafe_column' => 'drop-me',
        ],
    ]);

    $stored = (array) Capsule::connection('mysql')->table('insertable_trait_records')->first();

    expect($result)->toBeTrue()
        ->and($stored['uuid'])->toBe('uuid-1')
        ->and($stored['public_id'])->toBe('record_1')
        ->and($stored['internal_id'])->toBe('INT-1')
        ->and($stored['company_uuid'])->toBe('company-1')
        ->and($stored['name'])->toBe('ALPHA')
        ->and($stored['created_at'])->toBe('2026-07-18 12:34:56')
        ->and($stored)->not->toHaveKey('unsafe_column')
        ->and(InsertableTraitRecord::$flushes)->toBe(1);
});
