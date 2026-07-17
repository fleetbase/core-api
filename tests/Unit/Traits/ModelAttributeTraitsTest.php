<?php

use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasOptionsAttributes;
use Fleetbase\Traits\HasSessionAttributes;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class ModelAttributeTraitsRecord extends Model
{
    use HasMetaAttributes;
    use HasOptionsAttributes;
    use HasSessionAttributes;

    protected $table = 'trait_records';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'meta' => 'array',
        'options' => 'array',
    ];
}

function model_attribute_traits_database(): Capsule
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default' => 'testing',
        'database.connections.testing' => $connectionConfig,
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('testing')->getSchemaBuilder()->create('trait_records', function ($table) {
        $table->string('uuid')->primary();
        $table->text('meta')->nullable();
        $table->text('options')->nullable();
    });

    return $capsule;
}

test('has meta attributes manages nested values booleans defaults and selected subsets', function () {
    $record = new ModelAttributeTraitsRecord();

    expect($record->getAllMeta())->toBe([])
        ->and($record->getMeta('missing', 'fallback'))->toBe('fallback')
        ->and($record->hasMeta('customer.id'))->toBeFalse()
        ->and($record->missingMeta('customer.id'))->toBeTrue()
        ->and($record->doesntHaveMeta('customer.id'))->toBeTrue();

    $record->setMeta('customer.id', 1846473);
    $record->setMeta([
        'flags.billable' => true,
        'flags.reviewed' => false,
    ]);

    expect($record->getMeta('customer.id'))->toBe(1846473)
        ->and($record->getMeta('flags.billable'))->toBeTrue()
        ->and($record->isMeta('flags.billable'))->toBeTrue()
        ->and($record->isMeta('flags.reviewed'))->toBeFalse()
        ->and($record->hasMeta(['customer.id', 'flags.billable']))->toBeTrue()
        ->and($record->hasMeta(['customer.id', 'missing']))->toBeFalse()
        ->and($record->getMetaAttributes(['customer.id', 'flags.billable']))->toBe([
            'customer' => ['id' => 1846473],
            'flags' => ['billable' => true],
        ]);
});

test('has meta attributes updates database meta properties without discarding existing keys', function () {
    model_attribute_traits_database();

    $record = new ModelAttributeTraitsRecord([
        'uuid' => 'record-1',
        'meta' => ['existing' => 'yes', 'count' => 1],
        'options' => [],
    ]);
    $record->save();

    expect($record->updateMetaProperties(['count' => 2, 'new' => 'value']))->toBeTrue();

    $rawMeta = Capsule::connection('testing')
        ->table('trait_records')
        ->where('uuid', 'record-1')
        ->value('meta');

    expect(json_decode($rawMeta, true))->toBe([
        'existing' => 'yes',
        'count' => 2,
        'new' => 'value',
    ]);
});

test('has options attributes manages nested options and boolean checks', function () {
    $record = new ModelAttributeTraitsRecord();

    expect($record->getAllOptions())->toBe([])
        ->and($record->getOption('missing', 'fallback'))->toBe('fallback')
        ->and($record->hasOption('enabled'))->toBeFalse()
        ->and($record->missingOption('enabled'))->toBeTrue();

    $record->setOption('enabled', true)
        ->setOption('customer.name', 'Acme')
        ->setOption([
            'dispatch.window' => 'morning',
            'dispatch.priority' => 'high',
        ], null);

    expect($record->getOption())->toBe([
        'enabled' => true,
        'customer' => ['name' => 'Acme'],
        'dispatch' => [
            'window' => 'morning',
            'priority' => 'high',
        ],
    ])
        ->and($record->getOption('dispatch.priority'))->toBe('high')
        ->and($record->hasOption('enabled'))->toBeTrue()
        ->and($record->hasOption('dispatch.priority'))->toBeFalse()
        ->and($record->isOption('enabled'))->toBeTrue()
        ->and($record->isOption('dispatch.priority'))->toBeFalse();
});

test('has session attributes tracks strict session agnostic columns', function () {
    $record = new ModelAttributeTraitsRecord();

    expect($record->getSessionAgnosticColumns())->toBe([])
        ->and($record->isSessionAgnosticColumn('company_uuid'))->toBeFalse()
        ->and($record->setSessionAgnosticColumns(['company_uuid', 'created_by_uuid']))->toBe($record)
        ->and($record->getSessionAgnosticColumns())->toBe(['company_uuid', 'created_by_uuid'])
        ->and($record->isSessionAgnosticColumn('company_uuid'))->toBeTrue()
        ->and($record->isSessionAgnosticColumn('Company_UUID'))->toBeFalse();
});
