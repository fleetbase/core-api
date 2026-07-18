<?php

use Fleetbase\Models\Company;
use Fleetbase\Traits\HasPublicId;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class HasPublicIdTraitRecord extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection   = 'mysql';
    protected $table        = 'has_public_id_trait_records';
    protected $guarded      = [];
    protected $publicIdType = 'pub';
    public $timestamps      = false;

    public static array $suffixes = [];

    public static function getPublicId()
    {
        return array_shift(static::$suffixes) ?? 'fallbackid';
    }
}

class HasPublicIdTraitUntypedRecord extends HasPublicIdTraitRecord
{
    protected $publicIdType;

    public static array $suffixes = [];
}

function has_public_id_database(): Capsule
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
    ]);
    Facade::setFacadeApplication($container);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('mysql')->getSchemaBuilder()->create('has_public_id_trait_records', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('name')->nullable();
        $table->softDeletes();
    });

    HasPublicIdTraitRecord::$suffixes        = [];
    HasPublicIdTraitUntypedRecord::$suffixes = [];

    return $capsule;
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('public id suffix is ten lowercase alphanumeric characters', function () {
    $publicId = Company::getPublicId();

    expect($publicId)->toMatch('/^[a-z0-9]{10}$/');
});

test('public id suffix generation remains unique in tight bulk loops', function () {
    $publicIds = [];

    for ($i = 0; $i < 5000; $i++) {
        $publicIds[] = Company::getPublicId();
    }

    expect(array_unique($publicIds))->toHaveCount(5000);
});

test('public id trait assigns generated ids on create and preserves explicit ids', function () {
    has_public_id_database();
    HasPublicIdTraitRecord::$suffixes = ['created001'];

    $generated = HasPublicIdTraitRecord::query()->create([
        'uuid' => 'record-generated',
        'name' => 'Generated',
    ]);
    $explicit = HasPublicIdTraitRecord::query()->create([
        'uuid'      => 'record-explicit',
        'public_id' => 'custom_public_id',
        'name'      => 'Explicit',
    ]);

    expect($generated->public_id)->toBe('pub_created001')
        ->and($explicit->public_id)->toBe('custom_public_id');
});

test('public id trait retries collisions including soft deleted records and falls back to class type', function () {
    $capsule = has_public_id_database();
    $capsule->getConnection('mysql')->table('has_public_id_trait_records')->insert([
        'uuid'       => 'record-deleted',
        'public_id'  => 'pub_collision0',
        'name'       => 'Deleted collision',
        'deleted_at' => '2026-07-19 12:00:00',
    ]);

    HasPublicIdTraitRecord::$suffixes        = ['collision0', 'freshvalue'];
    HasPublicIdTraitUntypedRecord::$suffixes = ['classvalue'];

    expect(HasPublicIdTraitRecord::generatePublicId('pub'))->toBe('pub_freshvalue')
        ->and(HasPublicIdTraitUntypedRecord::generatePublicId())->toBe('haspublicidtraituntypedrecord_classvalue')
        ->and(HasPublicIdTraitRecord::getPublicIdType())->toBe('pub');
});

test('public id trait fails fast after repeated collision attempts', function () {
    has_public_id_database();

    expect(fn () => HasPublicIdTraitRecord::generatePublicId('pub', 11))
        ->toThrow(RuntimeException::class, 'Failed to generate unique public_id after 10 attempts.');
});
