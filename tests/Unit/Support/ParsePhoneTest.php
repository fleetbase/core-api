<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Support\ParsePhone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use libphonenumber\PhoneNumberFormat;

class ParsePhoneRecord extends Model
{
    protected $guarded = [];
}

function parse_phone_company_database(): Capsule
{
    Model::clearBootedModels();
    Model::unsetConnectionResolver();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    session()->flush();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $capsule->getConnection('mysql')->getSchemaBuilder()->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->string('phone')->nullable();
        $table->string('country')->nullable();
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    return $capsule;
}

afterEach(function () {
    session()->flush();
    Model::unsetConnectionResolver();
    Model::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('parse phone preserves valid international numbers and supports alternate output formats', function () {
    $record = new ParsePhoneRecord(['phone' => '+14155552671']);

    expect(ParsePhone::fromModel($record))->toBe('+14155552671')
        ->and(ParsePhone::fromModel($record, [], PhoneNumberFormat::NATIONAL))->toBe('(415) 555-2671');
});

test('parse phone resolves numbers from model country and explicit option country', function () {
    $withCountry = new ParsePhoneRecord([
        'phone'    => '4155552671',
        'country'  => 'US',
        'currency' => 'USD',
        'timezone' => 'America/Los_Angeles',
    ]);
    $withTelephoneAlias = new ParsePhoneRecord(['telephone' => '02079460018']);

    expect(ParsePhone::fromModel($withCountry))->toBe('+14155552671')
        ->and(ParsePhone::fromModel($withTelephoneAlias, [
            'country'  => 'GB',
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
        ]))->toBe('+442079460018');
});

test('parse phone returns original empty or unparseable values when no valid context exists', function () {
    session()->flush();

    expect(ParsePhone::fromModel(new ParsePhoneRecord()))->toBeNull()
        ->and(ParsePhone::fromModel(new ParsePhoneRecord(['phone' => ''])))->toBe('')
        ->and(ParsePhone::fromModel(new ParsePhoneRecord(['phone' => 'definitely-not-a-number']), [
            'country' => 'US',
        ]))->toBe('definitely-not-a-number');
});

test('parse phone falls back from invalid international numbers to available regional context', function () {
    $record = new ParsePhoneRecord([
        'phone'   => '+not-a-number',
        'country' => 'US',
    ]);

    expect(ParsePhone::fromModel($record))->toBe('+not-a-number');
});

test('parse phone resolves country from currency and timezone metadata', function () {
    bind_test_container([
        'countries.cache.enabled' => false,
    ]);

    $withCurrency = new ParsePhoneRecord([
        'phone'    => '02079460018',
        'currency' => 'GBP',
    ]);
    $withTimezone = new ParsePhoneRecord([
        'phone'    => '99112233',
        'timezone' => 'Asia/Ulaanbaatar',
    ]);

    expect(ParsePhone::fromModel($withCurrency))->toBe('+442079460018')
        ->and(ParsePhone::fromModel($withTimezone))->toBe('+97699112233');
});

test('parse phone returns original value when currency or timezone metadata cannot produce a valid number', function () {
    bind_test_container([
        'countries.cache.enabled' => false,
    ]);

    $withCurrency = new ParsePhoneRecord([
        'phone'    => 'not-a-number',
        'currency' => 'GBP',
    ]);
    $withTimezone = new ParsePhoneRecord([
        'phone'    => 'not-a-number',
        'timezone' => 'Asia/Ulaanbaatar',
    ]);

    expect(ParsePhone::fromModel($withCurrency))->toBe('not-a-number')
        ->and(ParsePhone::fromModel($withTimezone))->toBe('not-a-number');
});

test('parse phone wrappers preserve company and user parsing contracts', function () {
    bind_test_container();

    $company = new Company([
        'phone'    => '4155552671',
        'country'  => 'US',
        'currency' => 'USD',
        'timezone' => 'America/Los_Angeles',
    ]);
    $user = new User([
        'phone' => '02079460018',
    ]);

    expect(ParsePhone::fromCompany($company))->toBe('+14155552671')
        ->and(ParsePhone::fromUser($user, ['country' => 'GB'], PhoneNumberFormat::NATIONAL))->toBe('020 7946 0018');
});

test('parse phone fills missing regional context from the authenticated company', function () {
    $capsule = parse_phone_company_database();
    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'       => 'company-phone-context',
        'public_id'  => 'company_phone_context',
        'name'       => 'Phone Context Co',
        'phone'      => '+14155552671',
        'country'    => 'US',
        'currency'   => 'USD',
        'timezone'   => 'America/Los_Angeles',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    session(['company' => 'company-phone-context']);

    expect(ParsePhone::fromModel(new ParsePhoneRecord(['phone' => '4155552671'])))->toBe('+14155552671');
});

test('parse phone strips extensions from a valid international number in E164 output', function () {
    session()->flush();

    // libphonenumber parses the extension but E164 output excludes it.
    $record = new ParsePhoneRecord(['phone' => '+14155552671 ext. 123']);

    expect(ParsePhone::fromModel($record))->toBe('+14155552671');
});

test('parse phone returns non-string numeric input unchanged when no region context resolves', function () {
    session()->flush();

    // A non-string phone value has no leading "+" and no country/currency/timezone context,
    // so it falls through every parse attempt and is returned as-is (documents current behavior).
    $record = new ParsePhoneRecord(['phone' => 14155552671]);

    expect(ParsePhone::fromModel($record))->toBe(14155552671);
});
