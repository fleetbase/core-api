<?php

use Fleetbase\Casts\CustomValue;
use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;

class CastsTestModel extends Model
{
}

function custom_value_cast_file_database(): Capsule
{
    $container = bind_test_container([
        'app.env'                    => 'testing',
        'database.default'           => 'mysql',
        'database.connections.mysql' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
        'filesystems.default'       => 'testing',
        'filesystems.disks.testing' => [
            'driver' => 'local',
            'root'   => sys_get_temp_dir() . '/fleetbase-custom-value-cast-files',
            'url'    => 'https://files.example.test/storage',
        ],
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ], 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $container->instance('filesystem', new FilesystemManager($container));
    Facade::clearResolvedInstance('filesystem');

    $capsule->getConnection('mysql')->getSchemaBuilder()->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('disk')->nullable();
        $table->string('path')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('content_type')->nullable();
        $table->unsignedBigInteger('file_size')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

test('json cast decodes valid json and leaves non json values unchanged', function () {
    $cast  = new Json();
    $model = new CastsTestModel();

    expect($cast->get($model, 'meta', '{"enabled":true,"count":2}', []))->toBe([
        'enabled' => true,
        'count'   => 2,
    ])
        ->and($cast->get($model, 'meta', 'not-json', []))->toBe('not-json')
        ->and($cast->get($model, 'meta', ['already' => 'array'], []))->toBe(['already' => 'array'])
        ->and($cast->set($model, 'meta', ['nested' => ['value' => 'ok']], []))->toBe('{"nested":{"value":"ok"}}')
        ->and(Json::decode('[1,2,3]'))->toBe([1, 2, 3]);
});

test('money cast stores values as integer minor-unit-like digits', function () {
    $cast  = new Money();
    $model = new CastsTestModel();

    expect($cast->get($model, 'amount', 1299, []))->toBe(1299)
        ->and($cast->set($model, 'amount', null, []))->toBe(0)
        ->and($cast->set($model, 'amount', '$1,234.56', []))->toBe(123456)
        ->and($cast->set($model, 'amount', 'MNT ₮9,900', []))->toBe(9900)
        ->and(Money::apply(null))->toBe(0)
        ->and(Money::apply('€7.05'))->toBe(705)
        ->and(Money::removeCurrencySymbols('$€£¥₹¢฿₽₪₩₮100'))->toBe('100')
        ->and(Money::removeSpecialCharactersExceptDotAndComma('USD 1,234.56!!'))->toBe('1,234.56');
});

test('polymorphic type cast normalizes objects package aliases and class strings', function () {
    bind_test_container();

    $cast  = new PolymorphicType();
    $model = new CastsTestModel();

    expect($cast->get($model, 'subject_type', User::class, []))->toBe(User::class)
        ->and($cast->set($model, 'subject_type', null, []))->toBeNull()
        ->and($cast->set($model, 'subject_type', new User(), []))->toBe(User::class)
        ->and($cast->set($model, 'subject_type', User::class, []))->toBe(User::class)
        ->and($cast->set($model, 'subject_type', 'user', []))->toBe('\\Fleetbase\\Models\\User')
        ->and($cast->set($model, 'subject_type', 'fleet-ops:order', []))->toBe('Fleetbase\\FleetOps\\Models\\Order');
});

test('custom value cast serializes structured values and preserves scalar file references', function () {
    $cast  = new CustomValue();
    $model = new CastsTestModel();

    expect($cast->get($model, 'value', '{"threshold":10}', ['value_type' => 'object']))->toBe(['threshold' => 10])
        ->and($cast->get($model, 'value', '["fragile","cold"]', ['value_type' => 'array']))->toBe(['fragile', 'cold'])
        ->and($cast->set($model, 'value', ['threshold' => 10], ['value_type' => 'object']))->toBe('{"threshold":10}')
        ->and($cast->set($model, 'value', ['fragile', 'cold'], ['value_type' => 'array']))->toBe('["fragile","cold"]')
        ->and($cast->get($model, 'value', 'plain text', ['value_type' => 'text']))->toBe('plain text')
        ->and($cast->set($model, 'value', 'plain text', ['value_type' => 'text']))->toBe('plain text')
        ->and($cast->get($model, 'value', 'file:not-a-uuid', ['value_type' => 'file']))->toBe('file:not-a-uuid');
});

test('custom value cast resolves existing file references into file json', function () {
    $capsule = custom_value_cast_file_database();
    $uuid    = '11111111-1111-4111-8111-111111111111';

    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid'              => $uuid,
        'public_id'         => 'file_test',
        'disk'              => 'testing',
        'path'              => 'documents/proof.pdf',
        'original_filename' => 'proof.pdf',
        'content_type'      => 'application/pdf',
        'created_at'        => '2026-01-01 00:00:00',
        'updated_at'        => '2026-01-01 00:00:00',
    ]);

    $cast   = new CustomValue();
    $model  = new CastsTestModel();
    $result = $cast->get($model, 'value', "file:{$uuid}", ['value_type' => 'file']);
    $file   = json_decode($result, true);

    expect($file['uuid'])->toBe($uuid)
        ->and($file['public_id'])->toBe('file_test')
        ->and($file['path'])->toBe('documents/proof.pdf')
        ->and($file['url'])->toBe('https://files.example.test/storage/documents/proof.pdf')
        ->and($file['hash_name'])->toBe('proof.pdf');
});
