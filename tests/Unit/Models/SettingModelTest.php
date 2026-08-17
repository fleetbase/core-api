<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class SettingModelCacheFake
{
    public array $forgotten = [];
    private array $values   = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function tags(array $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }
}

class SettingModelFilesystemFake
{
    public function disk(string $name): SettingModelDiskFake
    {
        return new SettingModelDiskFake($name);
    }
}

class SettingModelDiskFake implements Filesystem
{
    public function __construct(private string $name)
    {
    }

    public function temporaryUrl(string $path, mixed $expiration): string
    {
        return 'https://cdn.example.test/' . $this->name . '/' . $path;
    }

    public function url(string $path): string
    {
        return 'https://cdn.example.test/' . $this->name . '/' . $path;
    }

    public function exists($path): bool
    {
        return true;
    }

    public function get($path): ?string
    {
        return null;
    }

    public function readStream($path)
    {
        return null;
    }

    public function put($path, $contents, $options = []): bool
    {
        return true;
    }

    public function writeStream($path, $resource, array $options = []): bool
    {
        return true;
    }

    public function getVisibility($path): string
    {
        return Filesystem::VISIBILITY_PUBLIC;
    }

    public function setVisibility($path, $visibility): bool
    {
        return true;
    }

    public function prepend($path, $data): bool
    {
        return true;
    }

    public function append($path, $data): bool
    {
        return true;
    }

    public function delete($paths): bool
    {
        return true;
    }

    public function copy($from, $to): bool
    {
        return true;
    }

    public function move($from, $to): bool
    {
        return true;
    }

    public function size($path): int
    {
        return 0;
    }

    public function lastModified($path): int
    {
        return 0;
    }

    public function files($directory = null, $recursive = false): array
    {
        return [];
    }

    public function allFiles($directory = null): array
    {
        return [];
    }

    public function directories($directory = null, $recursive = false): array
    {
        return [];
    }

    public function allDirectories($directory = null): array
    {
        return [];
    }

    public function makeDirectory($path): bool
    {
        return true;
    }

    public function deleteDirectory($directory): bool
    {
        return true;
    }
}

function setting_model_database(): array
{
    EloquentModel::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'           => false,
        'database.default'            => 'mysql',
        'database.connections.mysql'  => $connectionConfig,
        'fleetbase.connection.db'     => 'mysql',
        'fleetbase.branding.icon_url' => 'https://static.example.test/default-icon.svg',
        'fleetbase.branding.logo_url' => 'https://static.example.test/default-logo.svg',
    ]);

    $cache = new SettingModelCacheFake();
    $container->instance('cache', $cache);
    $container->instance('filesystem', new SettingModelFilesystemFake());

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('filesystem');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('disk')->nullable();
        $table->string('path')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('content_type')->nullable();
        $table->unsignedBigInteger('file_size')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return [$capsule, $cache];
}

it('retrieves system settings with prefixes nested keys defaults and cache reuse', function () {
    [, $cache] = setting_model_database();

    Setting::query()->create([
        'key'   => 'system.mail.from',
        'value' => ['name' => 'Fleetbase', 'address' => 'hello@fleetbase.io'],
    ]);
    Setting::query()->create([
        'key'   => 'system.timezone',
        'value' => 'UTC',
    ]);

    expect(Setting::system('mail.from.name'))->toBe('Fleetbase')
        ->and(Setting::system('timezone'))->toBe('UTC')
        ->and(Setting::system('mail.from.missing', 'fallback'))->toBe('fallback')
        ->and(Setting::system('', 'empty-default'))->toBe('empty-default');

    Setting::query()->where('key', 'system.timezone')->update(['value' => '"Asia/Ulaanbaatar"']);

    expect(Setting::system('timezone'))->toBe('UTC')
        ->and($cache->forgotten)->toContain('system_settings.system.mail.from')
        ->and($cache->forgotten)->toContain('system_settings.system.timezone');
});

it('invalidates the cache entry system() actually reads when a setting is written', function () {
    // system() caches under the key AS PASSED — 'system_settings.platform_api.token_hash' —
    // while the row is stored as 'system.platform_api.token_hash'. Building the cache key
    // from the row produced 'system_settings.system.platform_api.token_hash', which nothing
    // ever writes, so the entry the reader uses survived every write.
    //
    // clearSystemCache() looked like it covered the gap, but it pattern-clears through
    // Redis and api/.env.example ships CACHE_DRIVER=file — so on a default install neither
    // path invalidated anything, and a rotated platform API token kept validating against
    // the previously cached hash.
    setting_model_database();

    Setting::configureSystem('platform_api.token_hash', 'OLD_HASH');
    expect(Setting::system('platform_api.token_hash'))->toBe('OLD_HASH');

    Setting::configureSystem('platform_api.token_hash', 'NEW_HASH');

    expect(Setting::system('platform_api.token_hash'))->toBe('NEW_HASH');
});

it('configures and looks up company settings from session context', function () {
    setting_model_database();
    session()->flush();

    expect(Setting::configureCompany('dispatch.enabled', true))->toBeFalse()
        ->and(Setting::lookupCompany('dispatch.enabled', 'default'))->toBe('default');

    $companyUuid = '8b5cc964-2d67-4d9f-8b5d-0aa3070a5b5d';
    session(['company' => $companyUuid]);

    $setting = Setting::configureCompany('dispatch.enabled', true);

    expect($setting)->toBeInstanceOf(Setting::class)
        ->and($setting->key)->toBe('company.' . $companyUuid . '.dispatch.enabled')
        ->and(Setting::lookupCompany('dispatch.enabled', false))->toBeTrue()
        ->and(Setting::lookup('missing.key', 'fallback'))->toBe('fallback')
        ->and(Setting::getByKey('company.' . $companyUuid . '.dispatch.enabled'))->toBeInstanceOf(Setting::class);
});

it('exposes JSON value helpers and database connection checks', function () {
    setting_model_database();

    $setting        = new Setting();
    $setting->value = [
        'feature' => [
            'enabled' => 'yes',
            'label'   => 'Routing',
        ],
    ];

    expect($setting->getValue('feature.label'))->toBe('Routing')
        ->and($setting->getValue('feature.missing', 'fallback'))->toBe('fallback')
        ->and($setting->getBoolean('feature.enabled'))->toBeTrue()
        ->and(Setting::hasConnection())->toBeTrue()
        ->and(Setting::doesntHaveConnection())->toBeFalse();

    app('db')->connection('mysql')->getSchemaBuilder()->drop('settings');

    expect(Setting::hasConnection())->toBeFalse()
        ->and(Setting::doesntHaveConnection())->toBeTrue();
});

it('treats database connection exceptions as an unavailable settings connection', function () {
    bind_test_container();
    app()->instance('db', new class {
        public function connection()
        {
            return new class {
                public function getPdo(): void
                {
                    throw new RuntimeException('connection unavailable');
                }
            };
        }
    });
    Facade::clearResolvedInstance('db');

    expect(Setting::hasConnection())->toBeFalse()
        ->and(Setting::doesntHaveConnection())->toBeTrue();
});

it('resolves branding logo and icon urls from files with default fallbacks', function () {
    [$capsule] = setting_model_database();

    expect(Setting::getBrandingLogoUrl())->toBe('https://static.example.test/default-logo.svg')
        ->and(Setting::getBrandingIconUrl())->toBe('https://static.example.test/default-icon.svg');

    Setting::query()->create([
        'key'   => 'branding.logo_uuid',
        'value' => 'not-a-uuid',
    ]);
    Setting::query()->create([
        'key'   => 'branding.icon_uuid',
        'value' => '33333333-3333-4333-8333-333333333333',
    ]);

    expect(Setting::getBrandingLogoUrl())->toBe('https://static.example.test/default-logo.svg')
        ->and(Setting::getBrandingIconUrl())->toBe('https://static.example.test/default-icon.svg');

    $logoUuid = '11111111-1111-4111-8111-111111111111';
    $iconUuid = '22222222-2222-4222-8222-222222222222';

    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid'              => $logoUuid,
        'public_id'         => 'file_logo',
        'disk'              => 's3',
        'path'              => 'branding/logo.svg',
        'original_filename' => 'logo.svg',
        'content_type'      => 'image/svg+xml',
        'file_size'         => 1024,
        'meta'              => json_encode([]),
    ]);
    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid'              => $iconUuid,
        'public_id'         => 'file_icon',
        'disk'              => 's3',
        'path'              => 'branding/icon.svg',
        'original_filename' => 'icon.svg',
        'content_type'      => 'image/svg+xml',
        'file_size'         => 512,
        'meta'              => json_encode([]),
    ]);

    Setting::query()->where('key', 'branding.logo_uuid')->update(['value' => json_encode($logoUuid)]);
    Setting::query()->where('key', 'branding.icon_uuid')->update(['value' => json_encode($iconUuid)]);

    expect(Setting::getBrandingLogoUrl())->toBe('https://cdn.example.test/s3/branding/logo.svg')
        ->and(Setting::getBrandingIconUrl())->toBe('https://cdn.example.test/s3/branding/icon.svg');
});

it('clears deleted setting cache entries and resolves owner models from setting keys', function () {
    [$capsule, $cache] = setting_model_database();

    $companyUuid = '8b5cc964-2d67-4d9f-8b5d-0aa3070a5b5d';
    $userUuid    = '3fd99df4-c3a5-44e0-8760-6c40e438c0bb';
    $capsule->getConnection('mysql')->table('companies')->insert([
        'uuid'       => $companyUuid,
        'name'       => 'Dispatch Co',
        'created_at' => '2026-07-19 00:00:00',
        'updated_at' => '2026-07-19 00:00:00',
    ]);
    $capsule->getConnection('mysql')->table('users')->insert([
        'uuid'       => $userUuid,
        'name'       => 'Ops User',
        'email'      => 'ops@example.test',
        'created_at' => '2026-07-19 00:00:00',
        'updated_at' => '2026-07-19 00:00:00',
    ]);

    $companySetting = Setting::query()->create([
        'key'   => 'company.' . $companyUuid . '.dispatch.enabled',
        'value' => true,
    ]);
    $userSetting = Setting::query()->create([
        'key'   => 'user.' . $userUuid . '.notifications.email',
        'value' => true,
    ]);
    $globalSetting = Setting::query()->create([
        'key'   => 'system.dispatch.enabled',
        'value' => false,
    ]);

    $globalSetting->delete();

    expect($cache->forgotten)->toContain('system_settings.system.dispatch.enabled')
        ->and($companySetting->getCompany())->toBeInstanceOf(Company::class)
        ->and($companySetting->getCompany()->uuid)->toBe($companyUuid)
        ->and($companySetting->getUser())->toBeNull()
        ->and($userSetting->getUser())->toBeInstanceOf(User::class)
        ->and($userSetting->getUser()->uuid)->toBe($userUuid)
        ->and($userSetting->getCompany())->toBeNull();
});
