<?php

use Fleetbase\Models\User;
use Fleetbase\Support\Utils;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class UtilsRedisFake
{
    public array $values = [];
    public array $sets   = [];

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): bool
    {
        $this->values[$key] = $value;
        $this->sets[]       = compact('key', 'value');

        return true;
    }
}

function utils_database(): Capsule
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

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('status')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('type')->nullable();
    });

    return $capsule;
}

afterEach(function () {
    Facade::clearResolvedInstances();
    EloquentModel::unsetEventDispatcher();
    EloquentModel::clearBootedModels();
});

test('utils formats urls headers strings and dates', function () {
    bind_test_container([
        'filesystems.disks.s3.bucket' => 'fleetbase-media',
        'filesystems.disks.s3.region' => 'ap-southeast-1',
        'fleetbase.console.host'      => 'fleetbase.test',
        'fleetbase.console.subdomain' => 'console',
        'fleetbase.console.secure'    => true,
        'fleetbase.console.path'      => '/srv/fleetbase/console/',
    ]);

    expect(Utils::consoleUrl('settings', ['tab' => 'billing']))->toBe('https://console.fleetbase.test/settings?tab=billing')
        ->and(Utils::consolePath('dist/assets'))->toBe('/srv/fleetbase/console/dist/assets')
        ->and(Utils::getDomainFromUrl('https://api.fleetbase.test:8443/v1/orders', true))->toBe('api.fleetbase.test:8443')
        ->and(Utils::getDomainFromUrl('api.fleetbase.test:8000', true))->toBe('api.fleetbase.test:8000')
        ->and(Utils::fromS3('avatars/user.png'))->toBe('https://fleetbase-media.s3-ap-southeast-1.amazonaws.com/avatars/user.png')
        ->and(Utils::assetFromFleetbase('icons/logo.png'))->toBe('https://flb-assets.s3-ap-southeast-1.amazonaws.com/icons/logo.png')
        ->and(Utils::keyHeaders(['Content-Type: application/json']))->toBe(['Content-Type' => ' application/json'])
        ->and(Utils::unkeyHeaders(['Accept' => 'application/json', 'X-Test']))->toBe(['Accept: application/json', 'X-Test'])
        ->and(Utils::stringMatches('order_123', '/^order_/'))->toBeTrue()
        ->and(Utils::stringExtract('Order #123', '/\d+/'))->toBe('123')
        ->and(Utils::toMySqlDatetime('July 17, 2026 12:34:56 (UTC)'))->toBe('2026-07-17 12:34:56')
        ->and(Utils::isDate('2026-07-17'))->toBeTrue()
        ->and(Utils::isDate('not-a-date'))->toBeFalse();
});

test('utils handles boolean json inflection and sql helpers', function () {
    expect(Utils::createObject(['active' => true]))->toEqual((object) ['active' => true])
        ->and(Utils::castBoolean('truthy'))->toBeTrue()
        ->and(Utils::castBoolean('off'))->toBeFalse()
        ->and(Utils::castBoolean(null))->toBeFalse()
        ->and(Utils::isBooleanValue('true'))->toBeTrue()
        ->and(Utils::isBooleanValue('yes'))->toBeFalse()
        ->and(Utils::isTrue('1'))->toBeTrue()
        ->and(Utils::isFalse('0'))->toBeTrue()
        ->and(Utils::isJson('{"ok":true}'))->toBeTrue()
        ->and(Utils::isJson(['not' => 'json']))->toBeFalse()
        ->and(Utils::sqlExceptionString('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mysql)'))->toBe('Integrity constraint violation: 1062 Duplicate entry')
        ->and(Utils::pluralize('company'))->toBe('companies')
        ->and(Utils::singularize('companies'))->toBe('company')
        ->and(Utils::tableize('CompanyUser'))->toBe('company_user')
        ->and(Utils::lowercase('FleetBase'))->toBe('fleetbase')
        ->and(Utils::humanize('api_uuid'))->toBe('API UUID')
        ->and(Utils::interpolateQuery('select * from users where id = ? and email = ?', [1, 'ron@example.com']))->toBe('select * from users where id = 1 and email = ron@example.com');
});

test('utils validates identifiers base64 and numeric strings across edge cases', function () {
    expect(Utils::isPublicId('order_abcdef1'))->toBeTrue()
        ->and(Utils::isPublicId('order_abcdefghij'))->toBeTrue()
        ->and(Utils::isPublicId('order_abcdefghijklmnop'))->toBeFalse()
        ->and(Utils::isPublicId('order_abc-1234'))->toBeFalse()
        ->and(Utils::isPublicId('order'))->toBeFalse()
        ->and(Utils::isPublicId(null))->toBeFalse()
        ->and(Utils::isBase64String(base64_encode('fleetbase')))->toBeTrue()
        ->and(Utils::isBase64String('not base64!'))->toBeFalse()
        ->and(Utils::isBase64String(''))->toBeFalse()
        ->and(Utils::isBase64('plain+base64/shape=='))->toBeTrue()
        ->and(Utils::numbersOnly('+1 (561) 276-7156 ext. 9'))->toBe(156127671569)
        ->and(Utils::removeSpecialCharacters('Fleet-Ops #42', ['\-', ' ']))->toBe('Fleet-Ops 42')
        ->and(Utils::calculatePercentage(12.5, 200))->toBe(25.0);
});

test('utils resolves model class mutation and ember resource type contracts', function () {
    $user = new User();

    expect(Utils::getModelClassName('users'))->toBe('\Fleetbase\Models\User')
        ->and(Utils::getModelClassName($user))->toBe('\Fleetbase\Models\User')
        ->and(Utils::getModelClassName('orders', ['Fleetbase', 'FleetOps', 'Models']))->toBe('Fleetbase\FleetOps\Models\Order')
        ->and(fn () => Utils::getModelClassName('orders', 123))->toThrow(InvalidArgumentException::class)
        ->and(Utils::getMutationType($user))->toBe(User::class)
        ->and(Utils::getMutationType(User::class))->toBe(User::class)
        ->and(Utils::getMutationType('fleet-ops:order'))->toBe('Fleetbase\FleetOps\Models\Order')
        ->and(Utils::getTypeFromClassName('Fleetbase\FleetOps\Models\UserDevice'))->toBe('userdevice')
        ->and(Utils::humanizeClassName('Fleetbase\FleetOps\Models\ApiCredential'))->toBe('API Credential')
        ->and(Utils::toEmberResourceType('Fleetbase\FleetOps\Models\IntegratedVendor'))->toBe('fleet-ops:integrated-vendor')
        ->and(Utils::toEmberResourceType('fliit:client'))->toBe('fliit:client')
        ->and(Utils::toEmberResourceType('SimpleClass'))->toBe('simple-class')
        ->and(Utils::toEmberResourceType(null))->toBeNull();
});

test('utils reads and writes nested data without overwriting protected values', function () {
    $target = [
        'contact' => [
            'email'  => '',
            'phone'  => '+15612767156',
            'counts' => ['orders' => [1, 2, 3]],
        ],
    ];

    $object = (object) [
        'meta' => [
            'timezone' => 'Asia/Ulaanbaatar',
        ],
    ];

    expect(Utils::isset($target, 'contact.phone'))->toBeTrue()
        ->and(Utils::exists($target, 'contact.email'))->toBeFalse()
        ->and(Utils::notSet($target, 'contact.email'))->toBeTrue()
        ->and(Utils::firstValue($target, ['contact.email', 'contact.phone'], 'fallback'))->toBe('+15612767156')
        ->and(Utils::firstValue('not-readable', ['contact.phone'], 'fallback'))->toBe('fallback')
        ->and(Utils::or($object, ['meta.locale', 'meta.timezone'], 'UTC'))->toBe('Asia/Ulaanbaatar')
        ->and(Utils::count($target, 'contact.counts.orders'))->toBe(3)
        ->and(Utils::count($target, 'contact.phone'))->toBe(0);

    $written = Utils::setProperties($target, [
        'contact.email'       => 'new@example.test',
        'contact.phone'       => 'blocked',
        'contact.preferences' => ['sms' => true],
    ], false);

    expect($written['contact']['email'])->toBe('')
        ->and($written['contact']['phone'])->toBe('+15612767156')
        ->and($written['contact']['preferences'])->toBe(['sms' => true]);
});

test('utils normalizes country currency dates delimiters and template bindings', function () {
    $range = Utils::dateRange('2026-07-01,2026-07-31');
    $date  = Utils::dateRange('2026-07-18');

    expect(Utils::resolveCurrencyCode(['USD', 'EUR']))->toBe('USD')
        ->and(Utils::resolveCurrencyCode(['MNT' => ['name' => 'Mongolian togrog']]))->toBe('MNT')
        ->and(Utils::resolveCurrencyCode(new Collection(['GBP' => ['name' => 'Pound sterling']])))->toBe('GBP')
        ->and(Utils::resolveCurrencyCode('USD'))->toBeNull()
        ->and(Utils::getCountryCodeByName('United States'))->toBe('US')
        ->and(Utils::getCountryCodeByName('', 'ZZ'))->toBe('ZZ')
        ->and(Utils::getCountryCodeByCurrency('MNT'))->toBe('MN')
        ->and(Utils::getCountryCodeByCurrency('', 'ZZ'))->toBe('ZZ')
        ->and(Utils::findDelimiterFromString('a|b|c,d'))->toBe('|')
        ->and(Utils::findDelimiterFromString(null, ';'))->toBe(';')
        ->and(Utils::filterArray(['a' => 1, 'b' => null, 'c' => false]))->toBe(['a' => 1, 'c' => false])
        ->and(Utils::bindVariablesToString('Hello {user.name}, order {order.id}', [
            'user'  => ['name' => 'Ron'],
            'order' => [],
        ]))->toBe('Hello Ron, order #null')
        ->and($range[0]->toDateString())->toBe('2026-07-01')
        ->and($range[1]->toDateString())->toBe('2026-07-31')
        ->and($date->toDateString())->toBe('2026-07-18');
});

test('utils resolves uuids and models across tables and ember style resource types', function () {
    $capsule = utils_database();
    $capsule->getConnection('mysql')->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_1234567', 'status' => 'active'],
    ]);
    $capsule->getConnection('mysql')->table('files')->insert([
        ['uuid' => 'file-1', 'public_id' => 'file_1234567', 'type' => 'pod'],
    ]);

    $orderModel = Utils::findModel('orders', ['public_id' => 'order_1234567']);
    $fileModel  = Utils::findModel(['orders', 'files'], ['public_id' => 'file_1234567']);

    expect(Utils::getUuid('fleet-ops:order', ['public_id' => 'order_1234567']))->toBe('order-1')
        ->and(Utils::getUuid(['order', 'file'], ['public_id' => 'file_1234567'], ['with_table' => true]))->toBe([
            'uuid'  => 'file-1',
            'table' => 'file',
        ])
        ->and(Utils::getUuid(['order', 'file'], ['public_id' => 'none']))->toBeNull()
        ->and($orderModel->uuid)->toBe('order-1')
        ->and($fileModel->uuid)->toBe('file-1');
});

test('utils resolves country metadata cache fallback and locale helpers', function () {
    bind_test_container();
    $redis                           = new UtilsRedisFake();
    $redis->values['countryData:US'] = json_encode([
        'iso2'      => 'US',
        'currency'  => 'USD',
        'dial_code' => '1',
        'capital'   => 'Washington D.C.',
    ]);
    app()->instance('redis', $redis);
    Facade::clearResolvedInstance('redis');

    $mongolia = Utils::getCountryData('MN');

    expect(Utils::getCountryCodeByName('Mongolia Country'))->toBe('MN')
        ->and(Utils::getCountryCodeByName('United', 'ZZ'))->toBe('ZZ')
        ->and(Utils::findCountryFromTimezone(null))->toHaveCount(0)
        ->and(Utils::getCountryData(null))->toBeNull()
        ->and(Utils::getCountryData('US'))->toMatchArray([
            'iso2'      => 'US',
            'currency'  => 'USD',
            'dial_code' => '1',
            'capital'   => 'Washington D.C.',
        ])
        ->and($mongolia['iso2'])->toBe('MN')
        ->and($redis->sets[0]['key'])->toBe('countryData:MN')
        ->and(Utils::getCurrenyFromCountryCode(null))->toBeNull()
        ->and(Utils::getCurrenyFromCountryCode('US'))->toBe('USD')
        ->and(Utils::getDialCodeFromCountryCode('US'))->toBe('1')
        ->and(Utils::getCapitalCityFromCountryCode('US'))->toBe('Washington D.C.')
        ->and(Utils::smartHumanize('api_id_and_sku'))->toBe('API ID And SKU');
});
