<?php

use Fleetbase\Rules\EmailDomainExcluded;
use Fleetbase\Rules\ExcludeWords;
use Fleetbase\Rules\ExistsInAny;
use Fleetbase\Rules\FileInput;
use Fleetbase\Rules\RequiredIfCreating;
use Fleetbase\Rules\ValidPhoneNumber;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;

function exists_in_any_database(): Capsule
{
    $container = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
        'database.connections.reporting' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection(config('database.connections.testing'), 'testing');
    $capsule->addConnection(config('database.connections.reporting'), 'reporting');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

    $testingSchema = $capsule->getConnection('testing')->getSchemaBuilder();
    $testingSchema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('email')->unique();
    });
    $testingSchema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->unique();
    });
    $testingSchema->create('files', function ($table) {
        $table->string('uuid')->primary();
    });

    $reportingSchema = $capsule->getConnection('reporting')->getSchemaBuilder();
    $reportingSchema->create('reports', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->unique();
    });

    $capsule->getConnection('testing')->table('users')->insert([
        ['uuid' => 'user-1', 'email' => 'ron@fleetbase.test'],
    ]);
    $capsule->getConnection('testing')->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_1234567'],
    ]);
    $capsule->getConnection('reporting')->table('reports')->insert([
        ['uuid' => 'report-1', 'public_id' => 'report_1234567'],
    ]);

    return $capsule;
}

test('exclude words rejects full forbidden word segments case insensitively', function () {
    $rule = new ExcludeWords(['Admin', 'Root']);

    expect($rule->passes('name', 'fleet operations workspace'))->toBeTrue()
        ->and($rule->message())->toBe('The :attribute contains forbidden words.')
        ->and($rule->passes('name', 'Root access for ADMIN users'))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute contains forbidden words: admin, root.')
        ->and($rule->passes('name', 'administrator tooling'))->toBeTrue();
});

test('valid phone number requires leading plus and digits only', function (string $value, bool $expected) {
    $rule = new ValidPhoneNumber();

    expect($rule->passes('phone', $value))->toBe($expected)
        ->and($rule->message())->toBe('The :attribute must start with a "+" and include only numbers.');
})->with([
    ['+97699112233', true],
    ['97699112233', false],
    ['+1 561 276 7156', false],
    ['+1-561-276-7156', false],
    ['+', false],
    ['+abc', false],
]);

test('email domain excluded rejects configured disposable domains and keeps stable message', function () {
    $reflection = new ReflectionClass(EmailDomainExcluded::class);
    $rule       = $reflection->newInstanceWithoutConstructor();
    $domains    = $reflection->getProperty('domains');
    $domains->setAccessible(true);
    $domains->setValue($rule, [
        'mailinator.test' => 0,
        'throwaway.test'  => 1,
    ]);

    expect($rule->passes('email', 'owner@fleetbase.test'))->toBeTrue()
        ->and($rule->passes('email', 'owner@mailinator.test'))->toBeFalse()
        ->and($rule->passes('email', 'owner@throwaway.test'))->toBeFalse()
        ->and($rule->message())->toBe('The email domain is not allowed.');
});

test('file input accepts uploads public ids base64 data uris and urls', function () {
    $rule       = new FileInput();
    $uploadPath = tempnam(sys_get_temp_dir(), 'fleetbase-file-input');
    file_put_contents($uploadPath, 'avatar');

    $upload = new UploadedFile($uploadPath, 'avatar.png', 'image/png', null, true);

    expect($rule->passes('avatar', $upload))->toBeTrue()
        ->and($rule->passes('avatar', 'file_1234567'))->toBeTrue()
        ->and($rule->passes('avatar', 'file_1234567890'))->toBeTrue()
        ->and($rule->passes('avatar', 'data:image/png;base64,' . base64_encode('avatar')))->toBeTrue()
        ->and($rule->passes('avatar', 'data:application/pdf;base64,' . base64_encode('pdf')))->toBeTrue()
        ->and($rule->passes('avatar', 'https://cdn.fleetbase.test/avatar.png'))->toBeTrue()
        ->and($rule->passes('avatar', 'ftp://files.fleetbase.test/avatar.png'))->toBeTrue()
        ->and($rule->passes('avatar', 'not-a-file'))->toBeFalse()
        ->and($rule->passes('avatar', ['file_1234567']))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute must be a valid file upload, base64 string, file ID, or URL.');
});

test('required if creating follows the current request method contract', function () {
    $container = bind_test_container();
    $rule      = new RequiredIfCreating();

    $container->instance('request', Request::create('/int/v1/resources', 'POST'));
    expect($rule->passes('name', null))->toBeTrue();

    $container->instance('request', Request::create('/int/v1/resources/resource_123', 'PUT'));
    expect($rule->passes('name', null))->toBeFalse();

    $container->instance('request', Request::create('/int/v1/resources/resource_123', 'PATCH'));
    expect($rule->passes('name', 'value'))->toBeFalse()
        ->and($rule->message())->toBe('The validation error message.');
});

test('exists in any finds values across comma delimited tables and candidate columns', function () {
    exists_in_any_database();

    $rule            = new ExistsInAny('users,companies', ['uuid', 'public_id']);
    $singleTableRule = new ExistsInAny('users', 'uuid');

    expect($rule->tables)->toBe(['users', 'companies'])
        ->and($rule->column)->toBe(['uuid', 'public_id'])
        ->and($singleTableRule->tables)->toBe(['users'])
        ->and($singleTableRule->column)->toBe('uuid')
        ->and($rule->passes('subject', 'user-1'))->toBeTrue()
        ->and($rule->passes('subject', 'company_1234567'))->toBeTrue()
        ->and($rule->passes('subject', 'missing'))->toBeFalse()
        ->and($rule->message())->toBe('The :attribute does not exist.');
});

test('exists in any honors explicit connections and does not leak them to later tables', function () {
    exists_in_any_database();

    $rule = new ExistsInAny(['reporting:reports', 'files'], 'uuid');

    expect($rule->passes('subject', 'report-1'))->toBeTrue()
        ->and($rule->passes('subject', 'file-1'))->toBeFalse()
        ->and($rule->passes('subject', 'company-1'))->toBeFalse();
});

test('exists in any safely ignores tables without requested columns', function () {
    exists_in_any_database();

    $rule = new ExistsInAny(['files', 'companies'], 'public_id');

    expect($rule->passes('subject', 'company_1234567'))->toBeTrue()
        ->and($rule->passes('subject', 'file-1'))->toBeFalse();
});
