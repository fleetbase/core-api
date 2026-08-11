<?php

use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Fleetbase\Support\Utils;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str as SupportStr;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 3);

        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
    }
}

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

class UtilsCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $callback();
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }
}

class UtilsResponseCacheFake
{
    public int $clears = 0;

    public function clear(array $tags = []): void
    {
        $this->clears++;
    }
}

class UtilsFailingDatabaseFake
{
    public function connection(): object
    {
        return new class {
            public function getPdo(): void
            {
                throw new RuntimeException('database unavailable');
            }
        };
    }
}

class UtilsRecordingDatabaseFake
{
    public array $queries = [];

    public function connection(string $name): object
    {
        return new class($this, $name) {
            public function __construct(private UtilsRecordingDatabaseFake $database, public string $name)
            {
            }

            public function getPdo(): object
            {
                return new class($this->database) {
                    public function __construct(private UtilsRecordingDatabaseFake $database)
                    {
                    }

                    public function exec(string $query): int
                    {
                        $this->database->queries[] = $query;

                        return 1;
                    }
                };
            }
        };
    }
}

class UtilsConvertDbDatabaseFake
{
    public array $queries = [];

    public function __construct(public int $longIndexedCount = 0, public array $varcharRows = [])
    {
    }

    public function raw(string $query): string
    {
        $this->queries[] = $query;

        return $query;
    }

    public function connection(string $name): object
    {
        return new class($this) {
            public function __construct(private UtilsConvertDbDatabaseFake $database)
            {
            }

            public function select(string $query): array
            {
                if (str_contains($query, "DATA_TYPE = 'varchar'")) {
                    return $this->database->varcharRows ?: [
                        (object) [
                            'TABLE_NAME'               => 'customers',
                            'COLUMN_NAME'              => 'name',
                            'CHARACTER_MAXIMUM_LENGTH' => 255,
                        ],
                        (object) [
                            'TABLE_NAME'               => 'orders',
                            'COLUMN_NAME'              => 'code',
                            'CHARACTER_MAXIMUM_LENGTH' => 100,
                        ],
                    ];
                }

                if (str_contains($query, 'SHOW INDEX FROM `customers`')) {
                    return [(object) ['Column_name' => 'name']];
                }

                if (str_contains($query, 'SHOW INDEX FROM `orders`')) {
                    return [];
                }

                if (str_contains($query, 'length(`name`) > 191')) {
                    return [(object) ['count' => $this->database->longIndexedCount]];
                }

                if (str_contains($query, "DATA_TYPE like '%text%'")) {
                    return [
                        (object) [
                            'TABLE_NAME'  => 'customers',
                            'COLUMN_NAME' => 'notes',
                            'DATA_TYPE'   => 'text',
                        ],
                    ];
                }

                if (str_contains($query, 'INFORMATION_SCHEMA.TABLES')) {
                    return [
                        (object) ['TABLE_NAME' => 'customers'],
                    ];
                }

                return [];
            }
        };
    }
}

class UtilsHttpStreamFake
{
    public static array $responses = [];
    private string $content        = '';
    private int $position          = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!array_key_exists($path, self::$responses)) {
            return false;
        }

        $this->content  = self::$responses[$path];
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk          = substr($this->content, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    public function stream_stat(): array
    {
        return [];
    }
}

class UtilsInstalledExtensionsFake extends Utils
{
    public static array $packages = [];

    public static function getInstalledFleetbaseExtensions()
    {
        return static::$packages;
    }
}

class UtilsAutoloadFake extends Utils
{
    public static function namespaceFromAutoload(array $psr4, string $directory): ?string
    {
        return static::getNamespaceFromAutoload($psr4, $directory);
    }
}

class UtilsSubjectQueryFake
{
    public array $constraints = [];

    public function __construct(private object $subject)
    {
    }

    public function where(string $column, mixed $value): self
    {
        $this->constraints[] = compact('column', 'value');

        return $this;
    }

    public function first(): object
    {
        return $this->subject;
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
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('country')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('status')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('uploader_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('type')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('disk')->nullable();
        $table->string('path')->nullable();
        $table->string('bucket')->nullable();
        $table->string('folder')->nullable();
        $table->string('content_type')->nullable();
        $table->integer('file_size')->nullable();
        $table->string('slug')->nullable();
        $table->text('caption')->nullable();
        $table->json('meta')->nullable();
        $table->string('etag')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
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
        'app.env'                     => 'production',
        'fleetbase.console.host'      => 'fleetbase.test',
        'fleetbase.console.subdomain' => 'console',
        'fleetbase.console.secure'    => true,
        'fleetbase.console.path'      => '/srv/fleetbase/console/',
    ]);

    expect(Utils::apiUrl('/api/user', ['id' => 1], 8080))->toBe('https://fleetbase.test:8080/api/user?id=1')
        ->and(Utils::apiUrl('health', [], 443))->toBe('https://fleetbase.test/health')
        ->and(Utils::consoleUrl('settings', ['tab' => 'billing']))->toBe('https://console.fleetbase.test/settings?tab=billing')
        ->and(Utils::consolePath('dist/assets'))->toBe('/srv/fleetbase/console/dist/assets')
        ->and(Utils::getDomainFromUrl('https://api.fleetbase.test:8443/v1/orders', true))->toBe('api.fleetbase.test:8443')
        ->and(Utils::getDomainFromUrl('fleetbase.test'))->toBe('fleetbase.test')
        ->and(Utils::getDomainFromUrl('api.fleetbase.test:8000', true))->toBe('api.fleetbase.test:8000')
        ->and(Utils::getDomainFromUrl('//api.fleetbase.test'))->toBe('api.fleetbase.test')
        ->and(Utils::fromS3('avatars/user.png'))->toBe('https://fleetbase-media.s3-ap-southeast-1.amazonaws.com/avatars/user.png')
        ->and(Utils::assetFromS3('icons/logo.png', 'us-east-1'))->toBe('https://flb-assets.s3-us-east-1.amazonaws.com/icons/logo.png')
        ->and(Utils::assetFromFleetbase('icons/logo.png'))->toBe('https://flb-assets.s3-ap-southeast-1.amazonaws.com/icons/logo.png')
        ->and(Utils::keyHeaders(['Content-Type: application/json']))->toBe(['Content-Type' => ' application/json'])
        ->and(Utils::unkeyHeaders(['Accept' => 'application/json', 'X-Test']))->toBe(['Accept: application/json', 'X-Test'])
        ->and(Utils::stringMatches('order_123', '/^order_/'))->toBeTrue()
        ->and(Utils::stringExtract('Order #123', '/\d+/'))->toBe('123')
        ->and(Utils::toMySqlDatetime('July 17, 2026 12:34:56 (UTC)'))->toBe('2026-07-17 12:34:56')
        ->and(Utils::isDate(null))->toBeFalse()
        ->and(Utils::isDate('2026-07-17'))->toBeTrue()
        ->and(Utils::isDate('not-a-date'))->toBeFalse();

    config(['app.env' => 'local']);

    expect(Utils::apiUrl('api/user', ['id' => 2], 80))->toBe('http://fleetbase.test/api/user?id=2');
});

test('utils handles boolean json inflection and sql helpers', function () {
    $query = new class {
        public function toSql(): string
        {
            return 'select * from `orders` where `status` = ? and `company_uuid` = ?';
        }

        public function getBindings(): array
        {
            return ['active', 'company-1'];
        }
    };

    expect(Utils::createObject(['active' => true]))->toEqual((object) ['active' => true])
        ->and(Utils::castBoolean('truthy'))->toBeTrue()
        ->and(Utils::castBoolean('off'))->toBeFalse()
        ->and(Utils::castBoolean(null))->toBeFalse()
        ->and(Utils::castBoolean('definitely'))->toBeNull()
        ->and(Utils::isBooleanValue('true'))->toBeTrue()
        ->and(Utils::isBooleanValue(true))->toBeTrue()
        ->and(Utils::isBooleanValue('yes'))->toBeFalse()
        ->and(Utils::isBooleanValue(1))->toBeFalse()
        ->and(Utils::isTrue('1'))->toBeTrue()
        ->and(Utils::isTrue('definitely'))->toBeFalse()
        ->and(Utils::isTrue('definitely', true))->toBeNull()
        ->and(Utils::isFalse('0'))->toBeTrue()
        ->and(Utils::isJson('{"ok":true}'))->toBeTrue()
        ->and(Utils::isJson(['not' => 'json']))->toBeFalse()
        ->and(Utils::sqlExceptionString('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mysql)'))->toBe('Integrity constraint violation: 1062 Duplicate entry')
        ->and(Utils::sqlExceptionString(new RuntimeException('plain database failure')))->toBe('plain database failure')
        ->and(Utils::pluralize('company'))->toBe('companies')
        ->and(Utils::pluralize(null))->toBe('')
        ->and(Utils::singularize('companies'))->toBe('company')
        ->and(Utils::singularize(null))->toBe('')
        ->and(Utils::tableize('CompanyUser'))->toBe('company_user')
        ->and(Utils::lowercase('FleetBase'))->toBe('fleetbase')
        ->and(Utils::humanize('api_uuid'))->toBe('API UUID')
        ->and(Utils::interpolateQuery('select * from users where id = ? and email = ?', [1, 'ron@example.com']))->toBe('select * from users where id = 1 and email = ron@example.com')
        ->and(Utils::interpolateQuery('select * from users where id = :id and role = :role', [
            'id'   => 7,
            'role' => 'admin',
        ]))->toBe('select * from users where id = 7 and role = admin')
        ->and(Utils::queryBuilderToString($query))->toBe('select * from `orders` where `status` = "active" and `company_uuid` = "company-1"');

    ob_start();
    Utils::sqlDump($query, false);
    $formattedSql = ob_get_clean();

    expect($formattedSql)->toContain('active')
        ->and($formattedSql)->toContain('company-1');

    ob_start();
    Utils::sqlDump($query, false, true);
    $rawSql = ob_get_clean();

    expect($rawSql)->toContain('select')
        ->and($rawSql)->toContain('`orders`')
        ->and($rawSql)->toContain('?');
});

test('utils validates identifiers base64 and numeric strings across edge cases', function () {
    expect(Utils::isPublicId('order_abcdef1'))->toBeTrue()
        ->and(Utils::isPublicId('order_abcdefghij'))->toBeTrue()
        ->and(Utils::isPublicId('order_abcdefghijklmnop'))->toBeFalse()
        ->and(Utils::isPublicId('order_abc-1234'))->toBeFalse()
        ->and(Utils::isPublicId('order_'))->toBeFalse()
        ->and(Utils::isPublicId('order'))->toBeFalse()
        ->and(Utils::isPublicId(null))->toBeFalse()
        ->and(Utils::isBase64String(base64_encode('fleetbase')))->toBeTrue()
        ->and(Utils::isBase64String('not base64!'))->toBeFalse()
        ->and(Utils::isBase64String(''))->toBeFalse()
        ->and(Utils::isBase64('plain+base64/shape=='))->toBeTrue()
        ->and(Utils::isBase64('not base64!'))->toBeFalse()
        ->and(Utils::numbersOnly('+1 (561) 276-7156 ext. 9'))->toBe(156127671569)
        ->and(Utils::removeSpecialCharacters('Fleet-Ops #42', ['\-', ' ']))->toBe('Fleet-Ops 42')
        ->and(Utils::calculatePercentage(12.5, 200))->toBe(25.0);
});

test('utils numbers only preserves a leading sign and normalizes non-numeric input', function () {
    expect(Utils::numbersOnly(500))->toBe(500)
        // Leading minus sign is preserved for negative amounts (refunds, adjustments).
        ->and(Utils::numbersOnly(-500))->toBe(-500)
        ->and(Utils::numbersOnly('-5.00'))->toBe(-500)
        ->and(Utils::numbersOnly('-$5.00'))->toBe(-500)
        ->and(Utils::numbersOnly('$-5.00'))->toBe(-500)
        // Positive/zero/formatted values.
        ->and(Utils::numbersOnly('$1,234.56'))->toBe(123456)
        ->and(Utils::numbersOnly(0))->toBe(0)
        ->and(Utils::numbersOnly('0.00'))->toBe(0)
        // A hyphen occurring after digits (e.g. phone numbers) is not a sign.
        ->and(Utils::numbersOnly('276-7156'))->toBe(2767156)
        // A leading plus is not a negative sign.
        ->and(Utils::numbersOnly('+5'))->toBe(5)
        // Non-numeric and null collapse to zero.
        ->and(Utils::numbersOnly('abc'))->toBe(0)
        ->and(Utils::numbersOnly(''))->toBe(0)
        ->and(Utils::numbersOnly(null))->toBe(0);
});

test('utils resolves model class mutation and ember resource type contracts', function () {
    $user  = new User();
    $order = (object) [
        'public_id' => 'order_1234567',
        'status'    => 'created',
    ];
    $subjectQuery = new UtilsSubjectQueryFake($order);

    bind_test_container();
    app()->instance('Fleetbase\FleetOps\Models\Order', $subjectQuery);
    app()->instance('Fleetbase\Storefront\Models\Store', new UtilsSubjectQueryFake((object) []));

    expect(Utils::getModelClassName('users'))->toBe('\Fleetbase\Models\User')
        ->and(Utils::getModelClassName($user))->toBe('\Fleetbase\Models\User')
        ->and(Utils::getModelClassName('orders', ['Fleetbase', 'FleetOps', 'Models']))->toBe('Fleetbase\FleetOps\Models\Order')
        ->and(Utils::getModelClassName('\\' . User::class))->toBe('\\' . User::class)
        ->and(fn () => Utils::getModelClassName('orders', 123))->toThrow(InvalidArgumentException::class)
        ->and(Utils::getMutationType($user))->toBe(User::class)
        ->and(Utils::getMutationType(User::class))->toBe(User::class)
        ->and(Utils::getMutationType('fleet-ops:order'))->toBe('Fleetbase\FleetOps\Models\Order')
        ->and(Utils::getTypeFromClassName('Fleetbase\FleetOps\Models\UserDevice'))->toBe('userdevice')
        ->and(Utils::humanizeClassName('Fleetbase\FleetOps\Models\ApiCredential'))->toBe('API Credential')
        ->and(Utils::toEmberResourceType('Fleetbase\FleetOps\Models\IntegratedVendor'))->toBe('fleet-ops:integrated-vendor')
        ->and(Utils::toEmberResourceType('Acme\Packages\Models\Invoice'))->toBe('invoice')
        ->and(Utils::toEmberResourceType('fliit:client'))->toBe('fliit:client')
        ->and(Utils::toEmberResourceType('SimpleClass'))->toBe('simple-class')
        ->and(Utils::toEmberResourceType(null))->toBeNull()
        ->and(Utils::resolveSubject('order_1234567'))->toBe($order)
        ->and(Utils::resolveSubject('store_1234567'))->toEqual((object) [])
        ->and($subjectQuery->constraints)->toBe([
            [
                'column' => 'public_id',
                'value'  => 'order_1234567',
            ],
        ]);
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
        ->and(Utils::isset(null))->toBeFalse()
        ->and(Utils::exists($target, 'contact.email'))->toBeFalse()
        ->and(Utils::notSet($target, 'contact.email'))->toBeTrue()
        ->and(Utils::firstValue($target, ['contact.email', 'contact.phone'], 'fallback'))->toBe('+15612767156')
        ->and(Utils::firstValue($target, ['contact.email', 'contact.missing'], 'fallback'))->toBe('fallback')
        ->and(Utils::firstValue('not-readable', ['contact.phone'], 'fallback'))->toBe('fallback')
        ->and(Utils::or($object, ['meta.locale', 'meta.timezone'], 'UTC'))->toBe('Asia/Ulaanbaatar')
        ->and(Utils::count($target, 'contact.counts.orders'))->toBe(3)
        ->and(Utils::count($target, 'contact.phone'))->toBe(0)
        ->and(Utils::isNotScalar(['fleetbase']))->toBeTrue()
        ->and(Utils::isNotScalar('fleetbase'))->toBeFalse();

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
        ->and(Utils::getUuid(['order', 'file'], ['public_id' => 'order_1234567']))->toBe('order-1')
        ->and(Utils::getUuid(['order', 'file'], ['public_id' => 'file_1234567'], ['with_table' => true]))->toBe([
            'uuid'  => 'file-1',
            'table' => 'file',
        ])
        ->and(Utils::getUuid(['order', 'file'], ['public_id' => 'none']))->toBeNull()
        ->and($orderModel->uuid)->toBe('order-1')
        ->and($fileModel->uuid)->toBe('file-1')
        // A multi-table lookup that matches nothing must answer null, like getUuid does.
        // It used to fall through to the scalar branch with the ARRAY still in hand,
        // which DB::table() stringified to the literal table name "Array" and threw
        // SQLSTATE[42S02] — turning every "not found" into a 500 for callers that were
        // already written to handle null.
        ->and(Utils::findModel(['orders', 'files'], ['public_id' => 'nothing_matches']))->toBeNull();
});

test('utils deletes model collections and keeps empty deletes as no ops', function () {
    $capsule = utils_database();
    $capsule->getConnection('mysql')->table('files')->insert([
        ['uuid' => 'file-delete-1', 'public_id' => 'file_delete_1', 'type' => 'pod', 'original_filename' => 'one.jpg'],
        ['uuid' => 'file-delete-2', 'public_id' => 'file_delete_2', 'type' => 'pod', 'original_filename' => 'two.jpg'],
        ['uuid' => 'file-keep-1', 'public_id' => 'file_keep_1', 'type' => 'avatar', 'original_filename' => 'keep.jpg'],
    ]);

    expect(Utils::deleteModels(new Illuminate\Database\Eloquent\Collection()))->toBeTrue()
        ->and(Utils::deleteModels(File::where('type', 'pod')->get()))->toBe(2)
        ->and(File::pluck('uuid')->all())->toBe(['file-keep-1']);
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
        ->and(Utils::getDialCodeFromCountryCode(null))->toBeNull()
        ->and(Utils::getDialCodeFromCountryCode('US'))->toBe('1')
        ->and(Utils::getCapitalCityFromCountryCode(null))->toBeNull()
        ->and(Utils::getCapitalCityFromCountryCode('US'))->toBe('Washington D.C.')
        ->and(Utils::smartHumanize('api_id_and_sku'))->toBe('API ID And SKU');
});

test('utils handles numeric text url formatting and encoded string edge cases', function () {
    bind_test_container();
    putenv('MAIL_FROM_ADDRESS');
    putenv('CONSOLE_HOST');
    app('request')->server->set('SERVER_ADDR', '192.0.2.44');

    if (!SupportStr::hasMacro('domain')) {
        SupportStr::macro('domain', (new StrExpansion())->domain());
    }

    expect(Utils::randomNumber(6))->toMatch('/^\d{6}$/')
        ->and(Utils::ordinalNumber(21))->toBe('21st')
        ->and(Utils::numberAsWord(42))->toBe('forty-two')
        ->and(Utils::numericStringToDigits('one hundred twenty-three thousand four hundred fifty-six'))->toBe('123456')
        ->and(Utils::numericStringToDigits('two million three'))->toBe('2000003')
        ->and(Utils::unicodeDecode('Fleetbase \\u2713'))->toBe('Fleetbase ✓')
        ->and(Utils::isUnicodeString('Fleetbase ✓'))->toBeTrue()
        ->and(Utils::isUnicodeString('Fleetbase'))->toBeFalse()
        ->and(Utils::parseUrl('https://fleetbase.test/%E2%9C%93?name=Fleetbase%20Core'))->toMatchArray([
            'scheme' => 'https',
            'host'   => 'fleetbase.test',
            'path'   => '/✓',
            'query'  => 'name=Fleetbase Core',
        ])
        ->and(fn () => Utils::parseUrl('http:///path'))->toThrow(InvalidArgumentException::class, 'Malformed URL')
        ->and(Utils::addWwwToUrl(null))->toBeNull()
        ->and(Utils::addWwwToUrl('fleetbase.io'))->toBe('www.fleetbase.io')
        ->and(Utils::addWwwToUrl('www.fleetbase.io'))->toBe('www.fleetbase.io')
        ->and(Utils::addWwwToUrl('https://fleetbase.io/docs?tab=api#intro'))->toBe('https://www.fleetbase.io/docs?tab=api#intro')
        ->and(Utils::getDefaultMailFromAddress('support@example.test'))->toBe('support@example.test')
        ->and(Utils::formatSeconds(90))->toContain('minute')
        ->and(Utils::isEmail('ron@example.test'))->toBeTrue()
        ->and(Utils::isEmail('not-an-email'))->toBeFalse()
        ->and(Utils::classExists(Utils::class))->toBeTrue()
        ->and(Utils::classExists(''))->toBeFalse()
        ->and(Utils::classExists(null))->toBeFalse()
        ->and(Utils::getObjectKeyValue((object) ['status' => 'active'], 'status', 'pending'))->toBe('active')
        ->and(Utils::getObjectKeyValue((object) [], 'status', 'pending'))->toBe('pending')
        ->and(Utils::slugify('HelloWorld API v2!'))->toBe('hello-world-api-v2')
        ->and(Utils::formatPhoneNumber('+1 561-276-7156'))->toBe('+15612767156')
        ->and(Utils::delinkify('Email ron@example.test or visit https://fleetbase.io'))->toContain('&#8203;@')
        ->and(Utils::delinkify('Email ron@example.test or visit https://fleetbase.io'))->toContain('https://&#8203;')
        // Callers pass model attributes straight in — the verification and credentials
        // mail views call this on `$user->name`, and a user created from an identity alone
        // has none. A TypeError from inside a compiled Blade view surfaced as a 500 on
        // POST /storefront/v1/customers/request-creation-code.
        ->and(Utils::delinkify(null))->toBe('')
        ->and(Utils::delinkify(''))->toBe('');

    putenv('CONSOLE_HOST=https://console.fleetbase.test');

    expect(Utils::getDefaultMailFromAddress(null))->toBe('hello@fleetbase.test');

    putenv('CONSOLE_HOST');

    expect(Utils::getDefaultMailFromAddress(null))->toBe('hello@192.0.2.44')
        ->and(Utils::getDefaultMailFromAddress(''))->toBe('');
});

test('utils converts arrays from nullable strings objects and iterables', function () {
    $iterator = new ArrayIterator(['first' => 'alpha', 'second' => 'beta']);
    $object   = (object) ['status' => 'active', 'type' => 'order'];

    expect(Utils::arrayFrom(null))->toBe([])
        ->and(Utils::arrayFrom(['ready', 'done']))->toBe(['ready', 'done'])
        ->and(Utils::arrayFrom('ready, done, cancelled'))->toBe(['ready', 'done', 'cancelled'])
        ->and(Utils::arrayFrom('ready|done|cancelled'))->toBe(['ready', 'done', 'cancelled'])
        ->and(Utils::arrayFrom('ready'))->toBe(['ready'])
        ->and(Utils::arrayFrom(7))->toBe(['7'])
        ->and(Utils::arrayFrom($iterator))->toBe(['first' => 'alpha', 'second' => 'beta'])
        ->and(Utils::arrayFrom($object))->toBe(['status' => 'active', 'type' => 'order']);
});

test('utils formats stripe amounts for zero decimal and precision backed currencies', function () {
    expect(Utils::formatAmountForStripe(1250, 'JPY'))->toBe(1250)
        ->and(Utils::formatAmountForStripe(1250, 'USD'))->toBe(1250)
        ->and(Utils::formatAmountForStripe(1250, 'MNT'))->toBe(1250);
});

test('utils serializes resources images queues countries and connectivity edges', function () {
    bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ],
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $previousEnv = [];
    foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'SQS_EVENTS_QUEUE', 'QUEUE_URL_EVENTS', 'REDIS_QUEUE'] as $key) {
        $previousEnv[$key] = getenv($key);
        putenv($key);
    }

    try {
        $record = new Company();
        $record->setRawAttributes([
            'uuid' => 'company-1',
            'name' => 'Fleetbase Test',
        ], true);

        $child = new class(['status' => 'active']) extends JsonResource {
            public function toArray($request): array
            {
                return [
                    'status'  => $this->resource['status'],
                    'seen_at' => Carbon::parse('2026-07-18 09:10:11'),
                ];
            }
        };

        $resource = new class($record, $child) extends JsonResource {
            public function __construct(Company $resource, private JsonResource $child)
            {
                parent::__construct($resource);
            }

            public function toArray($request): array
            {
                return [
                    'company'    => $this->resource,
                    'child'      => $this->child,
                    'created_at' => Carbon::parse('2026-07-18 08:00:00'),
                ];
            }
        };

        $png              = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lvS1WQAAAABJRU5ErkJggg==';
        $countryCodeModel = new class extends EloquentModel {};
        $countryCodeModel->setRawAttributes(['country' => 'SG'], true);
        $countryNameModel = new class extends EloquentModel {};
        $countryNameModel->setRawAttributes(['country' => 'United States'], true);
        Company::query()->create([
            'uuid'    => 'company-session-country',
            'country' => 'Canada',
        ]);
        session(['company' => 'company-session-country']);

        $serialized = Utils::serializeJsonResource($resource);

        expect($serialized['company']['uuid'])->toBe('company-1')
            ->and($serialized['company']['name'])->toBe('Fleetbase Test')
            ->and($serialized['child'])->toBe([
                'status'  => 'active',
                'seen_at' => '2026-07-18 09:10:11',
            ])
            ->and($serialized['created_at'])->toBe('2026-07-18 08:00:00')
            ->and(Utils::getBase64ImageSize($png))->toBe(70)
            ->and(Utils::getImageSizeFromString($png)[0])->toBe(1)
            ->and(Utils::getImageSizeFromString($png)[1])->toBe(1)
            ->and(Utils::getImageSizeFromString(base64_decode($png))[0])->toBe(1)
            ->and(Utils::getImageSizeFromString(base64_decode($png))[1])->toBe(1)
            ->and(Utils::getEventsQueue())->toBe('default')
            ->and(Utils::chooseQueueConnection())->toBe('redis')
            ->and(Utils::getModelCountry($countryCodeModel))->toBe('SG')
            ->and(Utils::getModelCountry($countryNameModel))->toBe('US')
            ->and(Utils::getModelCountry(new User()))->toBe('CA')
            ->and(Utils::getModelCountry(new Company(['country' => 'United States'])))->toBe('US')
            ->and(Utils::getModelCountry(new Company()))->toBeNull()
            ->and(Utils::getFleetbaseDatabaseName())->toBe(':memory:')
            ->and(Utils::hasDatabaseConnection())->toBeTrue();

        session(['company' => 'missing-company']);
        config(['api.subscription_required_endpoints' => ['post:orders']]);
        expect(Utils::isSubscriptionValidForAction(Request::create('/v1/orders', 'POST')))->toBeFalse();

        session(['company' => 'company-session-country']);
        expect(Utils::isSubscriptionValidForAction(Request::create('/v1/orders', 'GET')))->toBeTrue();

        session()->flush();

        expect(Utils::getModelCountry(new User()))->toBeNull();

        app()->instance('db', new UtilsFailingDatabaseFake());
        DB::clearResolvedInstance('db');

        expect(Utils::hasDatabaseConnection())->toBeFalse();

        putenv('AWS_ACCESS_KEY_ID=test-key');
        putenv('AWS_SECRET_ACCESS_KEY=test-secret');
        putenv('SQS_EVENTS_QUEUE=events-primary');

        expect(Utils::getEventsQueue())->toBe('events-primary')
            ->and(Utils::chooseQueueConnection())->toBe('events-primary');

        putenv('QUEUE_URL_EVENTS=https://sqs.ap-southeast-1.amazonaws.com/123456789/events-from-url');

        expect(Utils::getEventsQueue())->toBe('events-from-url');
    } finally {
        foreach ($previousEnv as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
    }
});

test('utils converts storefront urls into stored file records with owner metadata', function () {
    utils_database();
    $root = sys_get_temp_dir() . '/fleetbase-utils-storefront-files-' . uniqid();

    config([
        'filesystems.default'          => 'local',
        'filesystems.disks.local'      => [
            'driver' => 'local',
            'root'   => $root . '/local',
            'url'    => 'https://files.example.test/storage',
        ],
        'filesystems.disks.s3'         => [
            'driver' => 'local',
            'root'   => $root . '/s3',
        ],
        'filesystems.disks.s3.bucket'  => 'storefront-assets',
        'activitylog.enabled'          => false,
    ]);

    $filesystem = new FilesystemManager(app());
    app()->instance('filesystem', $filesystem);
    app()->instance(ConfigRepository::class, config());
    app()->instance('cache', new UtilsCacheFake());
    app()->instance('responsecache', new UtilsResponseCacheFake());
    Illuminate\Support\Facades\Cache::swap(app('cache'));
    Facade::clearResolvedInstance('responsecache');
    Storage::clearResolvedInstances();

    $owner = new Company();
    $owner->setRawAttributes([
        'uuid'         => 'user-owner',
        'company_uuid' => 'company-owner',
    ], true);

    if (!SupportStr::hasMacro('humanize')) {
        SupportStr::macro('humanize', (new StrExpansion())->humanize());
    }

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lvS1WQAAAABJRU5ErkJggg==');

    stream_wrapper_unregister('http');
    stream_wrapper_register('http', UtilsHttpStreamFake::class);
    UtilsHttpStreamFake::$responses = [
        'http://images.example.test/catalog/Logo%20Mark' => $png,
        'http://images.example.test/catalog/empty.png'   => '',
    ];

    try {
        $file = Utils::urlToStorefrontFile('http://images.example.test/catalog/Logo%20Mark', 'Hero Image', $owner);

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $missingFile = Utils::urlToStorefrontFile('http://images.example.test/missing.png', 'Hero Image', $owner);
        restore_error_handler();

        expect(Utils::urlToStorefrontFile(null, 'Hero Image', $owner))->toBeNull()
            ->and(Utils::urlToStorefrontFile('', 'Hero Image', $owner))->toBeNull()
            ->and(Utils::urlToStorefrontFile('ftp://images.example.test/logo.png', 'Hero Image', $owner))->toBeNull()
            ->and($missingFile)->toBeNull()
            ->and(Utils::urlToStorefrontFile('http://images.example.test/catalog/empty.png', 'Hero Image', $owner))->toBeNull()
            ->and($file)->toBeInstanceOf(File::class)
            ->and($file->company_uuid)->toBe('company-owner')
            ->and($file->uploader_uuid)->toBe('user-owner')
            ->and($file->subject_uuid)->toBe('user-owner')
            ->and($file->subject_type)->toBe(Company::class)
            ->and($file->bucket)->toBe('storefront-assets')
            ->and($file->type)->toBe('hero_image')
            ->and($file->original_filename)->toBe('Logo Mark.jpg')
            ->and($file->content_type)->toBe('image/jpeg')
            ->and($file->file_size)->toBe(Utils::getBase64ImageSize($png))
            ->and($file->path)->toBe('uploads/storefront/user-owner/hero-image/Logo Mark.jpg')
            ->and(Storage::disk('s3')->exists($file->path))->toBeTrue()
            ->and(Storage::disk('s3')->get($file->path))->toBe($png);
    } finally {
        restore_error_handler();
        UtilsHttpStreamFake::$responses = [];
        stream_wrapper_restore('http');
        Utils::deleteDirectory($root);
    }
});

test('utils recursively deletes directories and ignores missing paths', function () {
    $root = sys_get_temp_dir() . '/fleetbase-utils-delete-' . uniqid();
    $leaf = $root . '/nested/deep';

    mkdir($leaf, 0777, true);
    file_put_contents($root . '/top.txt', 'top');
    file_put_contents($leaf . '/child.txt', 'child');

    Utils::deleteDirectory($root);
    Utils::deleteDirectory($root);

    expect(is_dir($root))->toBeFalse();
});

test('utils looks up ip metadata through the configured external api contract', function () {
    bind_test_container();
    putenv('IPINFO_API_KEY=test-ip-key');
    app('request')->server->set('REMOTE_ADDR', '198.51.100.24');

    Http::fake([
        'https://api.ipdata.co/203.0.113.42?api-key=test-ip-key' => Http::response([
            'ip'           => '203.0.113.42',
            'country_code' => 'US',
        ]),
        'https://api.ipdata.co/198.51.100.24?api-key=test-ip-key' => Http::response([
            'ip'           => '198.51.100.24',
            'country_code' => 'MN',
        ]),
    ]);

    expect(Utils::lookupIp('203.0.113.42'))->toBe([
        'ip'           => '203.0.113.42',
        'country_code' => 'US',
    ])->and(Utils::lookupIp())->toBe([
        'ip'           => '198.51.100.24',
        'country_code' => 'MN',
    ]);

    Http::assertSent(fn ($request) => (string) $request->url() === 'https://api.ipdata.co/203.0.113.42?api-key=test-ip-key');
});

test('utils generates public ids and emits dry run database statements without executing them', function () {
    expect(Utils::generatePublicId('order'))->toMatch('/^order_[A-Za-z0-9]{7}$/');

    ob_start();
    Utils::dbExec('ALTER TABLE `orders` CONVERT TO CHARACTER SET utf8mb4', true, 'mysql');
    $output = ob_get_clean();

    $database = new UtilsRecordingDatabaseFake();
    app()->instance('db', $database);
    DB::clearResolvedInstance('db');

    Utils::dbExec('SET FOREIGN_KEY_CHECKS = 0', false, 'mysql');

    expect($output)->toBe("ALTER TABLE `orders` CONVERT TO CHARACTER SET utf8mb4;\n")
        ->and($database->queries)->toBe(['SET FOREIGN_KEY_CHECKS = 0']);
});

test('utils database conversion dry run emits charset ddl and protects indexed long varchar data', function () {
    bind_test_container([
        'database.connections.mysql.database' => 'fleetbase_testing',
    ]);

    $database = new UtilsConvertDbDatabaseFake();
    app()->instance('db', $database);
    DB::clearResolvedInstance('db');

    ob_start();
    Utils::convertDb('mysql', 'utf8mb4', 'utf8mb4_unicode_ci', true);
    $output = ob_get_clean();

    expect($output)->toContain('SET FOREIGN_KEY_CHECKS = 0;')
        ->and($output)->toContain('ALTER SCHEMA fleetbase_testing DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci;')
        ->and($output)->toContain('-- Shrinking: customers.name(255)')
        ->and($output)->toContain('CHANGE `name` `name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        ->and($output)->toContain('CHANGE `code` `code` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        ->and($output)->toContain('CHANGE `notes` `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        ->and($output)->toContain('CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        ->and($output)->toContain('DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci')
        ->and($output)->toContain('SET FOREIGN_KEY_CHECKS = 1;')
        ->and($output)->toContain('-- fleetbase_testing CONVERTED TO utf8mb4-utf8mb4_unicode_ci');

    $database = new UtilsConvertDbDatabaseFake(longIndexedCount: 2);
    app()->instance('db', $database);
    DB::clearResolvedInstance('db');

    ob_start();
    $failure = null;
    try {
        Utils::convertDb('mysql', 'utf8mb4', 'utf8mb4_unicode_ci', true);
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    $failureOutput = ob_get_clean();

    expect($failure)->toBeInstanceOf(Exception::class)
        ->and($failure->getMessage())->toBe('Aborting due to data truncation')
        ->and($failureOutput)->toContain('-- DATA TRUNCATION: customers.name(255) => 2');

    $database = new UtilsConvertDbDatabaseFake(varcharRows: [
        (object) [
            'TABLE_NAME'               => 'customers',
            'COLUMN_NAME'              => 'legacy_name',
            'CHARACTER_MAXIMUM_LENGTH' => 191,
        ],
    ]);
    app()->instance('db', $database);
    DB::clearResolvedInstance('db');

    ob_start();
    Utils::convertDb('mysql', 'utf8', 'utf8_unicode_ci', true);
    $utf8Output = ob_get_clean();

    expect($utf8Output)->toContain('-- Expanding: customers.legacy_name(191)')
        ->and($utf8Output)->toContain('CHANGE `legacy_name` `legacy_name` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci');
});

test('utils resolves package namespaces from root and server composer layouts', function () {
    $rootPackage   = sys_get_temp_dir() . '/fleetbase-utils-root-package-' . uniqid();
    $serverPackage = sys_get_temp_dir() . '/fleetbase-utils-server-package-' . uniqid();

    mkdir($rootPackage . '/src', 0777, true);
    mkdir($serverPackage . '/server/src', 0777, true);
    file_put_contents($rootPackage . '/composer.json', json_encode([
        'autoload' => [
            'psr-4' => [
                'Fleetbase\\RootPackage\\' => 'src/',
            ],
        ],
    ]));
    file_put_contents($serverPackage . '/composer.json', json_encode([
        'autoload' => [
            'psr-4' => [
                'Fleetbase\\ServerPackage\\' => 'server/src/',
            ],
        ],
    ]));

    try {
        expect(Utils::findPackageNamespace(null))->toBeNull()
            ->and(Utils::findPackageNamespace($rootPackage . '/src/Models/Thing.php'))->toBe('Fleetbase\\RootPackage')
            ->and(Utils::findPackageNamespace($serverPackage . '/server/src/Models/Thing.php'))->toBe('Fleetbase\\ServerPackage')
            ->and(Utils::findPackageNamespace(sys_get_temp_dir() . '/missing-package/server/src/Models/Thing.php'))->toBeNull()
            ->and(UtilsAutoloadFake::namespaceFromAutoload([
                'Fleetbase\\RootPackage\\' => 'src/',
            ], 'server/src'))->toBeNull();
    } finally {
        Utils::deleteDirectory($rootPackage);
        Utils::deleteDirectory($serverPackage);
    }
});

test('utils reads composer package keyword metadata from the lock file', function () {
    $packages = Utils::findComposerPackagesWithKeyword('amazon');

    expect($packages)->toHaveKey('aws/aws-sdk-php')
        ->and($packages['aws/aws-sdk-php']['keywords'])->toContain('amazon');
});

test('utils discovers extension seeders migrations and auth schemas from installed package metadata', function () {
    $demoRoot     = base_path('vendor/acme/fleetbase-demo');
    $emptyRoot    = base_path('vendor/acme/fleetbase-empty');
    $fallbackRoot = base_path('vendor/acme/fleetbase-root-only');
    $noPsrRoot    = base_path('vendor/acme/fleetbase-no-psr');

    Utils::deleteDirectory($demoRoot);
    Utils::deleteDirectory($emptyRoot);
    Utils::deleteDirectory($fallbackRoot);
    Utils::deleteDirectory($noPsrRoot);

    mkdir($demoRoot . '/server/seeders', 0777, true);
    mkdir($demoRoot . '/server/migrations', 0777, true);
    mkdir($demoRoot . '/migrations', 0777, true);
    mkdir($demoRoot . '/server/src/Auth/Schemas', 0777, true);
    mkdir($emptyRoot . '/server/src', 0777, true);
    mkdir($fallbackRoot . '/seeders', 0777, true);
    mkdir($fallbackRoot . '/migrations', 0777, true);
    mkdir($fallbackRoot . '/src/Acme/Fallback/Auth/Schemas', 0777, true);
    mkdir($noPsrRoot . '/server/src', 0777, true);

    file_put_contents($demoRoot . '/server/seeders/DemoSeeder.php', '<?php');
    file_put_contents($demoRoot . '/server/migrations/2026_07_25_000000_create_demo_table.php', '<?php');
    file_put_contents($demoRoot . '/migrations/2026_07_25_000001_create_demo_root_table.php', '<?php');
    file_put_contents($demoRoot . '/server/src/Auth/Schemas/Demo.php', '<?php');
    file_put_contents($fallbackRoot . '/seeders/FallbackSeeder.php', '<?php');
    file_put_contents($fallbackRoot . '/migrations/2026_07_25_000002_create_fallback_table.php', '<?php');
    file_put_contents($fallbackRoot . '/src/Acme/Fallback/Auth/Schemas/Fallback.php', '<?php');

    if (!class_exists('Acme\\Demo\\Auth\\Schemas\\Demo', false)) {
        eval('namespace Acme\\Demo\\Auth\\Schemas; class Demo {}');
    }

    if (!class_exists('Acme\\Fallback\\Auth\\Schemas\\Fallback', false)) {
        eval('namespace Acme\\Fallback\\Auth\\Schemas; class Fallback {}');
    }

    UtilsInstalledExtensionsFake::$packages = [
        'acme/fleetbase-demo'      => [
            'name'     => 'acme/fleetbase-demo',
            'autoload' => [
                'psr-4' => [
                    'Acme\\Demo\\'          => 'server/src/',
                    'Acme\\Demo\\Seeders\\' => 'server/seeders/',
                ],
            ],
        ],
        'acme/fleetbase-root-only' => [
            'name'     => 'acme/fleetbase-root-only',
            'autoload' => [
                'psr-4' => [
                    'Acme\\Fallback\\'          => 'src/',
                    'Acme\\Fallback\\Seeders\\' => 'seeders/',
                ],
            ],
        ],
        'acme/fleetbase-empty'     => [
            'name'     => 'acme/fleetbase-empty',
            'autoload' => [
                'psr-4' => [
                    'Acme\\Empty\\' => 'server/src/',
                ],
            ],
        ],
        'acme/fleetbase-no-psr'    => [
            'name'     => 'acme/fleetbase-no-psr',
            'autoload' => [
                'classmap' => [
                    'server/src/',
                ],
            ],
        ],
    ];

    try {
        $seeders       = UtilsInstalledExtensionsFake::getSeederClassesFromExtensions();
        $seederPaths   = UtilsInstalledExtensionsFake::getSeedersFromExtensions();
        $migrationDirs = UtilsInstalledExtensionsFake::getMigrationDirectories();
        $authSchemas   = UtilsInstalledExtensionsFake::getAuthSchemaNamespaces();

        expect($seeders)->toContain('Acme\\Demo\\Seeders\\DemoSeeder')
            ->and($seeders)->toContain('Acme\\Fallback\\Seeders\\FallbackSeeder')
            ->and($seederPaths)->toContain([
                'class' => 'Acme\\Demo\\Seeders\\DemoSeeder',
                'path'  => $demoRoot . '/server/seeders/DemoSeeder.php',
            ])
            ->and($seederPaths)->toContain([
                'class' => 'Acme\\Fallback\\Seeders\\FallbackSeeder',
                'path'  => $fallbackRoot . '/seeders/FallbackSeeder.php',
            ])
            ->and($migrationDirs)->toContain($demoRoot . '/server/migrations/')
            ->and($migrationDirs)->toContain($demoRoot . '//migrations/')
            ->and($migrationDirs)->toContain($fallbackRoot . '//migrations/')
            ->and(UtilsInstalledExtensionsFake::getMigrationDirectoryForExtension('acme/fleetbase-demo'))->toBe($demoRoot . '/server/migrations/')
            ->and(UtilsInstalledExtensionsFake::getMigrationDirectoryForExtension('acme/fleetbase-root-only'))->toBe($fallbackRoot . '//migrations/')
            ->and(UtilsInstalledExtensionsFake::getMigrationDirectoryForExtension('acme/missing'))->toBeNull()
            ->and($authSchemas)->toContain('Acme\\Demo\\Auth\\Schemas\\Demo')
            ->and($authSchemas)->toContain('Acme\\Fallback\\Auth\\Schemas\\Fallback')
            ->and($authSchemas)->not->toContain('Acme\\Empty\\Auth\\Schemas\\Missing')
            ->and($authSchemas)->not->toContain('Acme\\NoPsr\\Auth\\Schemas\\Missing');
    } finally {
        UtilsInstalledExtensionsFake::$packages = [];
        Utils::deleteDirectory($demoRoot);
        Utils::deleteDirectory($emptyRoot);
        Utils::deleteDirectory($fallbackRoot);
        Utils::deleteDirectory($noPsrRoot);
    }
});
