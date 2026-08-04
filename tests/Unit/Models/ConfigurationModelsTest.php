<?php

use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\Dashboard;
use Fleetbase\Models\DashboardWidget;
use Fleetbase\Models\Extension;
use Fleetbase\Models\ExtensionInstall;
use Fleetbase\Models\File;
use Fleetbase\Models\Type;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class ConfigurationModelsCacheFake
{
    public array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
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

    public function rememberForever(string $key, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $callback();
        }

        return $this->values[$key];
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

class ConfigurationCategoryIconFile extends File
{
    public function getUrlAttribute(): string
    {
        return 'https://cdn.fleetbase.test/icons/category.svg';
    }
}

function configuration_models_database(): Capsule
{
    EloquentModel::clearBootedModels();

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
    $container->instance('cache', new ConfigurationModelsCacheFake());
    $container->instance('responsecache', new class {
        public function clear(): bool
        {
            return true;
        }
    });
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');
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

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('dashboards', function ($table) {
        $table->string('uuid')->primary();
        $table->string('user_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('extension')->nullable();
        $table->string('name')->nullable();
        $table->boolean('is_default')->default(false);
        $table->text('tags')->nullable();
        $table->text('meta')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('dashboard_widgets', function ($table) {
        $table->string('uuid')->primary();
        $table->string('dashboard_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('component')->nullable();
        $table->text('grid_options')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('extensions', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('_key')->nullable();
        $table->string('extension_id')->nullable();
        $table->string('author_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('type_uuid')->nullable();
        $table->string('icon_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('display_name')->nullable();
        $table->string('key')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->string('namespace')->nullable();
        $table->string('internal_route')->nullable();
        $table->string('fa_icon')->nullable();
        $table->string('version')->nullable();
        $table->string('website_url')->nullable();
        $table->string('privacy_policy_url')->nullable();
        $table->string('tos_url')->nullable();
        $table->string('contact_email')->nullable();
        $table->text('domains')->nullable();
        $table->boolean('core_service')->default(false);
        $table->text('meta')->nullable();
        $table->string('meta_type')->nullable();
        $table->text('config')->nullable();
        $table->string('secret')->nullable();
        $table->string('client_token')->nullable();
        $table->string('status')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('extension_installs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('_key')->nullable();
        $table->string('extension_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->text('config')->nullable();
        $table->text('overwrite')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

it('casts dashboard and widget configuration and preserves widget relation contract', function () {
    configuration_models_database();

    $dashboard = new Dashboard([
        'user_uuid'    => 'user-1',
        'company_uuid' => 'company-1',
        'extension'    => 'fleet-ops',
        'name'         => 'Operations',
        'is_default'   => 1,
        'tags'         => ['ops', 'dispatch'],
        'meta'         => ['layout' => 'wide'],
        'options'      => ['refresh' => 60],
    ]);
    $widget = new DashboardWidget([
        'dashboard_uuid' => 'dashboard-1',
        'name'           => 'Active Orders',
        'component'      => 'active-orders',
        'grid_options'   => ['x' => 0, 'y' => 0, 'w' => 6],
        'options'        => ['metric' => 'active_orders'],
    ]);

    expect($dashboard->is_default)->toBeTrue()
        ->and($dashboard->tags)->toBe(['ops', 'dispatch'])
        ->and($dashboard->meta)->toBe(['layout' => 'wide'])
        ->and($dashboard->options)->toBe(['refresh' => 60])
        ->and((fn () => $this->with)->call($dashboard))->toBe(['widgets'])
        ->and($dashboard->widgets()->getForeignKeyName())->toBe('dashboard_uuid')
        ->and($dashboard->widgets()->getLocalKeyName())->toBe('uuid')
        ->and($widget->grid_options)->toBe(['x' => 0, 'y' => 0, 'w' => 6])
        ->and($widget->options)->toBe(['metric' => 'active_orders']);
});

it('derives category slugs owner types icon URLs casts and relationship keys', function () {
    configuration_models_database();

    $category = new Category([
        'name'          => 'Service Quotes',
        'description'   => 'Quote categories',
        'tags'          => ['quotes', 'dispatch'],
        'meta'          => ['color' => 'blue'],
        'translations'  => ['mn' => ['name' => 'Uilchilgee']],
        'core_category' => 1,
    ]);
    $category->owner_type = new Company();

    $icon = new ConfigurationCategoryIconFile();
    $icon->setRawAttributes(['uuid' => 'file-1'], true);
    $category->setRelation('iconFile', $icon);

    expect($category->getSlugOptions()->generateSlugFrom)->toBe(['name'])
        ->and($category->getSlugOptions()->slugField)->toBe('slug')
        ->and($category->tags)->toBe(['quotes', 'dispatch'])
        ->and($category->meta)->toBe(['color' => 'blue'])
        ->and($category->translations)->toBe(['mn' => ['name' => 'Uilchilgee']])
        ->and($category->core_category)->toBeTrue()
        ->and($category->owner_type)->toBe(Company::class)
        ->and($category->icon_url)->toBe('https://cdn.fleetbase.test/icons/category.svg')
        ->and((new Category())->icon_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/images/fallback-placeholder-1.png')
        ->and($category->parentCategory()->getForeignKeyName())->toBe('parent_uuid')
        ->and($category->subCategories()->getForeignKeyName())->toBe('parent_uuid')
        ->and($category->iconFile()->getForeignKeyName())->toBe('icon_file_uuid');
});

it('casts type metadata and keeps company and subject relationship contracts stable', function () {
    configuration_models_database();

    $type = new Type([
        'company_uuid' => 'company-1',
        'name'         => 'Driver',
        'description'  => 'Driver subject type',
        'key'          => 'driver',
        'subject_uuid' => 'user-1',
        'subject_type' => User::class,
        'for'          => 'users',
        'meta'         => ['assignable' => true],
    ]);

    expect($type->meta)->toBe(['assignable' => true])
        ->and($type->getSlugOptions()->generateSlugFrom)->toBe(['name'])
        ->and($type->getSlugOptions()->slugField)->toBe('slug')
        ->and($type->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($type->company()->getOwnerKeyName())->toBe('uuid')
        ->and($type->subject()->getMorphType())->toBe('subject_type')
        ->and($type->subject()->getForeignKeyName())->toBe('subject_uuid');
});

it('creates extension installs from session context and converts installs back to extensions', function () {
    configuration_models_database();
    session([
        'company' => 'company-1',
        'api_key' => 'flb_live_console',
    ]);

    $extension = Extension::query()->create([
        'uuid'         => 'extension-1',
        'public_id'    => 'ext_public',
        'name'         => 'Dispatch Maps',
        'display_name' => 'Dispatch Maps',
        'key'          => 'dispatch-maps',
        'namespace'    => Extension::createNamespace('Fleetbase', 'Dispatch Maps', null, 'Realtime'),
        'core_service' => false,
        'tags'         => ['maps', 'dispatch'],
        'meta'         => ['default_region' => 'mn'],
        'config'       => ['enabled' => true],
        'secret'       => 'hidden-secret',
        'status'       => 'published',
    ]);

    $install = $extension->install();
    $install->setRelation('extension', $extension);
    $install->overwrite = [
        'display_name' => 'Company Dispatch Maps',
        'status'       => 'installed',
    ];

    $installedExtension = $install->asExtension();

    expect($extension->extension_id)->toBeString()
        ->and($extension->extension_id)->toHaveLength(14)
        ->and($extension->extension_id)->toBe(strtoupper($extension->extension_id))
        ->and($extension->tags)->toBe(['maps', 'dispatch'])
        ->and($extension->meta)->toBe(['default_region' => 'mn'])
        ->and($extension->config)->toBe(['enabled' => true])
        ->and($extension->core_service)->toBeFalse()
        ->and($extension->is_installed)->toBeTrue()
        ->and($extension->install_count)->toBe(1)
        ->and($extension->toArray())->not->toHaveKey('secret')
        ->and($install)->toBeInstanceOf(ExtensionInstall::class)
        ->and($install->_key)->toBeNull()
        ->and($install->company_uuid)->toBe('company-1')
        ->and($install->config)->toBe(['enabled' => true])
        ->and($install->meta)->toBe(['default_region' => 'mn'])
        ->and($installedExtension)->toBeInstanceOf(Extension::class)
        ->and($installedExtension->uuid)->toBe($install->uuid)
        ->and($installedExtension->install_uuid)->toBe($install->uuid)
        ->and($installedExtension->installed)->toBeTrue()
        ->and($installedExtension->is_installed)->toBeTrue()
        ->and($installedExtension->display_name)->toBe('Company Dispatch Maps')
        ->and($installedExtension->status)->toBe('installed');
});

it('keeps extension marketplace relationship keys and fallback cached labels stable', function () {
    configuration_models_database();

    $coreExtension = new Extension([
        'name'         => 'Core Dispatch',
        'core_service' => true,
    ]);

    expect($coreExtension->is_installed)->toBeTrue()
        ->and($coreExtension->type_name)->toBeNull()
        ->and($coreExtension->category_name)->toBeNull()
        ->and($coreExtension->author_name)->toBeNull()
        ->and($coreExtension->icon_url)->toBe('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png')
        ->and(Extension::createNamespace('Fleetbase', 'Fleet-Ops', 12, 'Orders'))->toBe('fleetbase:fleet-ops:orders')
        ->and($coreExtension->installs()->getForeignKeyName())->toBe('extension_uuid')
        ->and($coreExtension->installs()->getLocalKeyName())->toBe('uuid')
        ->and($coreExtension->author()->getForeignKeyName())->toBe('author_uuid')
        ->and($coreExtension->category()->getForeignKeyName())->toBe('category_uuid')
        ->and($coreExtension->type()->getForeignKeyName())->toBe('type_uuid')
        ->and($coreExtension->icon()->getForeignKeyName())->toBe('icon_uuid')
        ->and((new ExtensionInstall())->extension()->getForeignKeyName())->toBe('extension_uuid')
        ->and((new ExtensionInstall())->company()->getForeignKeyName())->toBe('company_uuid');
});
