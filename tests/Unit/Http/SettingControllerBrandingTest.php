<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class SettingControllerBrandingCacheStore
{
    public array $values = [];

    public function rememberForever(string $key, Closure $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
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

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function getPrefix(): string
    {
        return 'fleetbase_cache:';
    }

    public function tags(array $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }
}

class SettingControllerBrandingRedisFake
{
    public array $patterns = [];

    public function connection(): self
    {
        return $this;
    }

    public function keys(string $pattern): array
    {
        $this->patterns[] = $pattern;

        return [];
    }
}

class SettingControllerBrandingFilesystemFake
{
    public array $disks = [];

    public function disk(string $name): SettingControllerBrandingDiskFake
    {
        return $this->disks[$name] ??= new SettingControllerBrandingDiskFake($name);
    }
}

class SettingControllerBrandingDiskFake implements Filesystem
{
    public array $temporaryUrls = [];

    public function __construct(private string $disk)
    {
    }

    public function temporaryUrl(string $path, mixed $expiration): string
    {
        $url                         = "https://{$this->disk}.example.test/temporary/" . ltrim($path, '/');
        $this->temporaryUrls[$path]  = $url;

        return $url;
    }

    public function url(string $path): string
    {
        return "https://{$this->disk}.example.test/" . ltrim($path, '/');
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

function setting_controller_branding_fixtures(): Capsule
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'            => 'mysql',
        'database.connections.mysql'  => $connectionConfig,
        'fleetbase.branding.icon_url' => 'https://static.example.test/default-icon.svg',
        'fleetbase.branding.logo_url' => 'https://static.example.test/default-logo.svg',
        'filesystems.default'         => 's3',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $cache = new SettingControllerBrandingCacheStore();
    $container->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');

    $container->instance('redis', new SettingControllerBrandingRedisFake());
    Facade::clearResolvedInstance('redis');

    $filesystem = new SettingControllerBrandingFilesystemFake();
    $container->instance('filesystem', $filesystem);
    Facade::clearResolvedInstance('filesystem');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
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
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

function setting_controller_branding_request(array $input = []): AdminRequest
{
    return AdminRequest::create('/int/v1/settings/branding', 'POST', $input);
}

function setting_controller_branding_insert_file(Capsule $capsule, string $uuid, string $path): void
{
    $capsule->getConnection('mysql')->table('files')->insert([
        'uuid'              => $uuid,
        'public_id'         => 'file_' . substr(str_replace('-', '', $uuid), 0, 10),
        'disk'              => 's3',
        'path'              => $path,
        'original_filename' => basename($path),
        'content_type'      => str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png',
        'file_size'         => 1024,
        'meta'              => json_encode([]),
    ]);
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('branding settings response returns configured defaults when no overrides exist', function () {
    setting_controller_branding_fixtures();

    $response = (new SettingController())->getBrandingSettings();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'brand' => [
                'id'            => 1,
                'uuid'          => 1,
                'icon_url'      => 'https://static.example.test/default-icon.svg',
                'logo_url'      => 'https://static.example.test/default-logo.svg',
                'icon_uuid'     => null,
                'logo_uuid'     => null,
                'default_theme' => 'dark',
            ],
        ]);
});

test('save branding settings persists selected assets and resolves file backed urls', function () {
    $capsule  = setting_controller_branding_fixtures();
    $iconUuid = '11111111-1111-4111-8111-111111111111';
    $logoUuid = '22222222-2222-4222-8222-222222222222';
    setting_controller_branding_insert_file($capsule, $iconUuid, 'branding/icon.svg');
    setting_controller_branding_insert_file($capsule, $logoUuid, 'branding/logo.png');

    $response = (new SettingController())->saveBrandingSettings(setting_controller_branding_request([
        'brand' => [
            'icon_uuid'     => $iconUuid,
            'logo_uuid'     => $logoUuid,
            'default_theme' => 'light',
        ],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'brand' => [
                'id'            => 1,
                'uuid'          => 1,
                'icon_url'      => 'https://s3.example.test/temporary/branding/icon.svg',
                'logo_url'      => 'https://s3.example.test/temporary/branding/logo.png',
                'icon_uuid'     => $iconUuid,
                'logo_uuid'     => $logoUuid,
                'default_theme' => 'light',
            ],
        ])
        ->and(Setting::lookup('branding.icon_uuid'))->toBe($iconUuid)
        ->and(Setting::lookup('branding.logo_uuid'))->toBe($logoUuid)
        ->and(Setting::lookup('branding.default_theme'))->toBe('light');
});

test('save branding settings clears asset overrides when uuids are omitted and preserves existing theme', function () {
    $capsule  = setting_controller_branding_fixtures();
    $iconUuid = '33333333-3333-4333-8333-333333333333';
    $logoUuid = '44444444-4444-4444-8444-444444444444';
    setting_controller_branding_insert_file($capsule, $iconUuid, 'branding/old-icon.svg');
    setting_controller_branding_insert_file($capsule, $logoUuid, 'branding/old-logo.png');

    (new SettingController())->saveBrandingSettings(setting_controller_branding_request([
        'brand' => [
            'icon_uuid'     => $iconUuid,
            'logo_uuid'     => $logoUuid,
            'default_theme' => 'light',
        ],
    ]));

    $response = (new SettingController())->saveBrandingSettings(setting_controller_branding_request([
        'brand' => [],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'brand' => [
                'id'            => 1,
                'uuid'          => 1,
                'icon_url'      => 'https://static.example.test/default-icon.svg',
                'logo_url'      => 'https://static.example.test/default-logo.svg',
                'icon_uuid'     => null,
                'logo_uuid'     => null,
                'default_theme' => 'light',
            ],
        ])
        ->and(Setting::lookup('branding.icon_uuid'))->toBeNull()
        ->and(Setting::lookup('branding.logo_uuid'))->toBeNull()
        ->and(Setting::lookup('branding.default_theme'))->toBe('light');
});
