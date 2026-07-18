<?php

use Fleetbase\Models\Setting;
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

function setting_model_database(): array
{
    EloquentModel::clearBootedModels();

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connectionConfig,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $cache = new SettingModelCacheFake();
    $container->instance('cache', $cache);

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

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
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
});
