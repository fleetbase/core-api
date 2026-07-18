<?php

use Fleetbase\Http\Controllers\Internal\v1\ExtensionController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class ExtensionControllerQueryFake
{
    public array $wheres = [];

    public function where(string $column, mixed $value): self
    {
        $this->wheres[] = [$column, $value];

        return $this;
    }
}

class ExtensionControllerModelFake extends Model
{
    public ?ExtensionControllerQueryFake $query = null;

    public function queryFromRequest(Request $request, ?Closure $queryCallback = null): array
    {
        $this->query = new ExtensionControllerQueryFake();

        if ($queryCallback) {
            $queryCallback($this->query);
        }

        return [
            'path'   => $request->path(),
            'wheres' => $this->query->wheres,
        ];
    }
}

class ExtensionControllerCacheFake
{
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->values[$key] ??= $callback();
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

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

function extension_controller_database(): Capsule
{
    Model::clearBootedModels();
    Model::unsetEventDispatcher();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new ExtensionControllerCacheFake());
    Facade::clearResolvedInstance('cache');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::unsetEventDispatcher();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    session()->flush();
    session(['company' => 'company-1']);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('extensions', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
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
        $table->string('version')->nullable();
        $table->boolean('core_service')->default(false);
        $table->text('meta')->nullable();
        $table->text('config')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->create('extension_installs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('extension_id')->nullable()->index();
        $table->string('extension_uuid')->nullable()->index();
        $table->string('company_uuid')->index();
        $table->text('meta')->nullable();
        $table->text('config')->nullable();
        $table->text('overwrite')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $now = '2026-07-18 10:00:00';
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Acme Logistics', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-2', 'name' => 'Beta Freight', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('extensions')->insert([
        ['uuid' => 'extension-1', 'public_id' => 'ext_public_1', 'extension_id' => 'EXTENSIONONE', 'author_uuid' => 'company-1', 'name' => 'Dispatch Board', 'display_name' => 'Dispatch Board', 'key' => 'dispatch-board', 'description' => 'Original description', 'tags' => json_encode(['dispatch']), 'namespace' => 'Fleetbase\\Dispatch', 'version' => '1.0.0', 'core_service' => false, 'meta' => json_encode(['source' => 'catalog']), 'config' => json_encode([]), 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'extension-2', 'public_id' => 'ext_public_2', 'extension_id' => 'EXTENSIONTWO', 'author_uuid' => 'company-2', 'name' => 'Other Extension', 'display_name' => 'Other Extension', 'key' => 'other-extension', 'description' => 'Other description', 'tags' => json_encode(['other']), 'namespace' => 'Fleetbase\\Other', 'version' => '1.0.0', 'core_service' => false, 'meta' => json_encode([]), 'config' => json_encode([]), 'status' => 'published', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('extension_installs')->insert([
        ['uuid' => 'install-1', 'extension_id' => 'extension-1', 'extension_uuid' => 'extension-1', 'company_uuid' => 'company-1', 'meta' => json_encode(['enabled' => true]), 'config' => json_encode([]), 'overwrite' => json_encode(['display_name' => 'My Dispatch', 'status' => 'active']), 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'install-2', 'extension_id' => 'extension-2', 'extension_uuid' => 'extension-2', 'company_uuid' => 'company-2', 'meta' => json_encode(['enabled' => true]), 'config' => json_encode([]), 'overwrite' => json_encode(['display_name' => 'Foreign Install']), 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

test('extension controller scopes authored extensions to the current company', function () {
    bind_test_container();
    session()->flush();
    session(['company' => 'company-123']);

    $model             = new ExtensionControllerModelFake();
    $controller        = (new ReflectionClass(ExtensionController::class))->newInstanceWithoutConstructor();
    $controller->model = $model;
    $request           = Request::create('/int/v1/extensions/authored', 'GET');

    $result = $controller->getAuthored($request);

    expect($result)->toBe([
        'path'   => 'int/v1/extensions/authored',
        'wheres' => [
            ['author_uuid', 'company-123'],
        ],
    ])->and($model->query)->toBeInstanceOf(ExtensionControllerQueryFake::class);
});

test('extension controller returns installed extensions with install metadata and overwrites', function () {
    extension_controller_database();

    $response = (new ExtensionController())->getInstalled(Request::create('/int/v1/extensions/installed', 'GET'));
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload)->toHaveCount(1)
        ->and($payload[0]['uuid'])->toBe('install-1')
        ->and($payload[0]['install_uuid'])->toBe('install-1')
        ->and($payload[0]['name'])->toBe('Dispatch Board')
        ->and($payload[0]['display_name'])->toBe('My Dispatch')
        ->and($payload[0]['status'])->toBe('active')
        ->and($payload[0]['installed'])->toBeTrue()
        ->and($payload[0]['meta'])->toBe(['enabled' => true]);
});
