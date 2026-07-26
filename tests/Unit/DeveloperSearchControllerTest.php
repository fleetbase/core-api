<?php

use Fleetbase\Http\Controllers\Internal\v1\DeveloperSearchController;
use Fleetbase\Models\User as FleetbaseUser;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Spatie\Permission\PermissionRegistrar;

class DeveloperSearchAdminUser extends FleetbaseUser
{
    public function isAdmin(): bool
    {
        return true;
    }
}

function developer_search_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'                           => false,
        'auth.defaults.guard'                         => 'sanctum',
        'cache.default'                               => 'array',
        'cache.stores.array.driver'                   => 'array',
        'database.default'                            => 'mysql',
        'database.connections.mysql'                  => $connection,
        'database.connections.testing'                => $connection,
        'fleetbase.connection.db'                     => 'mysql',
        'permission.cache.expiration_time'            => DateInterval::createFromDateString('24 hours'),
        'permission.cache.key'                        => 'spatie.permission.cache',
        'permission.column_names.model_morph_key'     => 'model_uuid',
        'permission.models.permission'                => Fleetbase\Models\Permission::class,
        'permission.models.role'                      => Fleetbase\Models\Role::class,
        'permission.table_names.model_has_permissions'=> 'model_has_permissions',
        'permission.table_names.model_has_roles'      => 'model_has_roles',
        'permission.table_names.permissions'          => 'permissions',
        'permission.table_names.role_has_permissions' => 'role_has_permissions',
        'permission.table_names.roles'                => 'roles',
    ]);
    $container->instance('cache', new CacheManager($container));
    $container->forgetInstance(PermissionRegistrar::class);
    $container->singleton(PermissionRegistrar::class, fn ($app) => new PermissionRegistrar($app['cache']));
    Facade::clearResolvedInstance('cache');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => new DeveloperSearchAdminUser(),
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();

    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('username')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->index();
        $table->string('user_uuid')->index();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->string('service')->nullable();
        $table->timestamps();
    });

    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('guard_name')->nullable();
        $table->timestamps();
    });

    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
    });

    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });

    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });

    $schema->create('model_has_policies', function ($table) {
        $table->string('policy_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });

    $schema->create('api_credentials', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->string('_key')->nullable();
        $table->boolean('test_mode')->default(false);
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('webhook_endpoints', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable()->index();
        $table->string('url')->nullable();
        $table->string('description')->nullable();
        $table->string('status')->nullable();
        $table->string('mode')->nullable();
        $table->string('version')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('api_request_logs', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('api_credential_uuid')->nullable()->index();
        $table->string('method')->nullable();
        $table->string('path')->nullable();
        $table->string('full_url')->nullable();
        $table->integer('status_code')->nullable();
        $table->string('reason_phrase')->nullable();
        $table->string('ip_address')->nullable();
        $table->string('version')->nullable();
        $table->string('source')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $schema->create('api_events', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable()->index();
        $table->string('event')->nullable();
        $table->string('source')->nullable();
        $table->string('description')->nullable();
        $table->string('method')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $capsule;
}

function developer_search_seed(Capsule $capsule): void
{
    $db  = $capsule->getConnection('mysql');
    $now = '2026-07-26 00:00:00';

    $db->table('companies')->insert([
        ['uuid' => 'company-1', 'public_id' => 'company_developer_1', 'name' => 'Developer Company', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $db->table('users')->insert([
        ['uuid' => 'developer-1', 'public_id' => 'user_developer_1', 'company_uuid' => 'company-1', 'email' => 'developer@example.test', 'name' => 'Developer User', 'type' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $db->table('company_users')->insert([
        ['uuid' => 'company-user-developer', 'company_uuid' => 'company-1', 'user_uuid' => 'developer-1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $db->table('permissions')->insert([
        ['id' => 'permission-developers-see-log', 'name' => 'developers see log', 'guard_name' => 'sanctum', 'service' => 'developers', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $db->table('model_has_permissions')->insert([
        'permission_id' => 'permission-developers-see-log',
        'model_type'    => Fleetbase\Models\CompanyUser::class,
        'model_uuid'    => 'company-user-developer',
    ]);

    $db->table('api_credentials')->insert([
        ['uuid' => 'credential-live', 'company_uuid' => 'company-1', 'name' => 'Live Orders', 'key' => 'flb_live_orders', '_key' => 'live_orders_hash', 'test_mode' => 0, 'expires_at' => null],
        ['uuid' => 'credential-test', 'company_uuid' => 'company-1', 'name' => null, 'key' => 'flb_test_orders', '_key' => 'test_orders_hash', 'test_mode' => 1, 'expires_at' => null],
        ['uuid' => 'credential-other', 'company_uuid' => 'company-2', 'name' => 'Other Orders', 'key' => 'flb_live_other', '_key' => 'other_orders_hash', 'test_mode' => 0, 'expires_at' => null],
    ]);

    $db->table('webhook_endpoints')->insert([
        ['uuid' => 'webhook-live', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/orders', 'description' => 'Order hooks', 'status' => 'enabled', 'mode' => 'live', 'version' => '2026-01'],
        ['uuid' => 'webhook-test', 'company_uuid' => 'company-1', 'url' => 'https://hooks.example.test/fallback', 'description' => null, 'status' => 'disabled', 'mode' => 'test', 'version' => '2026-02'],
        ['uuid' => 'webhook-other', 'company_uuid' => 'company-2', 'url' => 'https://hooks.example.test/other-orders', 'description' => 'Other tenant', 'status' => 'enabled', 'mode' => 'live', 'version' => '2026-01'],
    ]);

    $db->table('api_request_logs')->insert([
        ['uuid' => 'log-1', 'public_id' => 'req_orders_1', 'company_uuid' => 'company-1', 'api_credential_uuid' => 'credential-live', 'method' => 'GET', 'path' => 'v1/orders', 'full_url' => 'https://api.example.test/v1/orders', 'status_code' => 200, 'reason_phrase' => 'OK', 'ip_address' => '198.51.100.10', 'version' => 'v1', 'source' => 'api'],
        ['uuid' => 'log-2', 'public_id' => null, 'company_uuid' => 'company-1', 'api_credential_uuid' => 'credential-test', 'method' => 'POST', 'path' => 'v1/order%_special', 'full_url' => 'https://api.example.test/v1/order%25_special', 'status_code' => 500, 'reason_phrase' => 'Server Error', 'ip_address' => '198.51.100.11', 'version' => 'v1', 'source' => 'api'],
        ['uuid' => 'log-other', 'public_id' => 'req_other', 'company_uuid' => 'company-2', 'api_credential_uuid' => 'credential-other', 'method' => 'DELETE', 'path' => 'v1/orders/other', 'full_url' => 'https://api.example.test/v1/orders/other', 'status_code' => 404, 'reason_phrase' => 'Not Found', 'ip_address' => '198.51.100.12', 'version' => 'v1', 'source' => 'api'],
    ]);

    $db->table('api_events')->insert([
        ['uuid' => 'event-1', 'public_id' => 'event_orders_1', 'company_uuid' => 'company-1', 'event' => 'order.created', 'source' => 'api', 'description' => 'Order created', 'method' => 'POST'],
        ['uuid' => 'event-2', 'public_id' => 'event_invoice_1', 'company_uuid' => 'company-1', 'event' => null, 'source' => 'webhook', 'description' => null, 'method' => 'GET'],
        ['uuid' => 'event-other', 'public_id' => 'event_other', 'company_uuid' => 'company-2', 'event' => 'order.deleted', 'source' => 'api', 'description' => 'Other tenant', 'method' => 'DELETE'],
    ]);
}

function developer_search_controller(): DeveloperSearchController
{
    $capsule = developer_search_database();
    developer_search_seed($capsule);

    return new DeveloperSearchController();
}

afterEach(function () {
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('developer search returns empty results for blank query', function () {
    $controller = new DeveloperSearchController();
    $response   = $controller->search(Request::create('/developers/search', 'GET', ['query' => '   ']));
    $payload    = json_decode($response->getContent(), true);

    expect($payload)->toBe(['results' => []]);
});

test('developer search returns tenant scoped results across all searchable developer resources', function () {
    $response = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'query' => 'orders',
        'limit' => 8,
    ]));

    $payload = $response->getData(true);
    $types   = array_column($payload['results'], 'type');

    expect($response->getStatusCode())->toBe(200)
        ->and($types)->toContain('API Key', 'Webhook', 'Request Log', 'Event')
        ->and($payload['results'])->toContain([
            'label'       => 'Live Orders',
            'description' => 'Live API key - flb_live_orders',
            'icon'        => 'key',
            'type'        => 'API Key',
            'route'       => 'console.developers.api-keys.index',
            'breadcrumb'  => 'Developers > API Keys',
            'queryParams' => [
                'query'        => 'orders',
                'view_api_key' => 'credential-live',
            ],
        ])
        ->and($payload['results'])->toContain([
            'label'       => 'https://hooks.example.test/orders',
            'description' => 'Order hooks',
            'icon'        => 'globe-asia',
            'type'        => 'Webhook',
            'route'       => 'console.developers.webhooks.view',
            'models'      => ['webhook-live'],
            'breadcrumb'  => 'Developers > Webhooks',
        ])
        ->and($payload['results'])->toContain([
            'label'       => 'req_orders_1',
            'description' => 'GET /v1/orders 200 OK',
            'icon'        => 'file-lines',
            'type'        => 'Request Log',
            'route'       => 'console.developers.logs.view',
            'models'      => ['req_orders_1'],
            'breadcrumb'  => 'Developers > Logs',
        ])
        ->and($payload['results'])->toContain([
            'label'       => 'order.created',
            'description' => 'Order created',
            'icon'        => 'calendar-day',
            'type'        => 'Event',
            'route'       => 'console.developers.events.view',
            'models'      => ['event_orders_1'],
            'breadcrumb'  => 'Developers > Events',
        ])
        ->and(collect($payload['results'])->pluck('label')->implode('|'))->not->toContain('Other Orders')
        ->and(collect($payload['results'])->pluck('models')->flatten()->implode('|'))->not->toContain('event_other');
});

test('developer search honors requested types fallback labels q aliases and hard limits', function () {
    $response = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'q'     => 'orders',
        'types' => 'logs,events,not-real',
        'limit' => 1,
    ]));

    $payload = $response->getData(true);

    expect($payload['results'])->toHaveCount(1)
        ->and($payload['results'][0])->toMatchArray([
            'label'       => 'req_orders_1',
            'description' => 'GET /v1/orders 200 OK',
            'type'        => 'Request Log',
            'models'      => ['req_orders_1'],
        ]);

    $webhookResponse = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'query' => 'disabled',
        'types' => ['webhooks'],
        'limit' => 24,
    ]));

    expect($webhookResponse->getData(true)['results'])->toBe([
        [
            'label'       => 'https://hooks.example.test/fallback',
            'description' => 'test disabled 2026-02',
            'icon'        => 'globe-asia',
            'type'        => 'Webhook',
            'route'       => 'console.developers.webhooks.view',
            'models'      => ['webhook-test'],
            'breadcrumb'  => 'Developers > Webhooks',
        ],
    ]);

    $eventResponse = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'query' => 'webhook',
        'types' => ['events'],
    ]));

    expect($eventResponse->getData(true)['results'])->toBe([
        [
            'label'       => 'event_invoice_1',
            'description' => 'webhook GET',
            'icon'        => 'calendar-day',
            'type'        => 'Event',
            'route'       => 'console.developers.events.view',
            'models'      => ['event_invoice_1'],
            'breadcrumb'  => 'Developers > Events',
        ],
    ]);
});

test('developer search falls back to all result types for malformed types input', function () {
    $response = developer_search_controller()->search(Request::create('/developers/search', 'GET', [
        'query' => 'orders',
        'types' => 123,
        'limit' => 8,
    ]));

    $types = array_column($response->getData(true)['results'], 'type');

    expect($response->getStatusCode())->toBe(200)
        ->and($types)->toContain('API Key', 'Webhook', 'Request Log', 'Event');
});

test('developer search skips unauthorized result types for non admin users', function () {
    $controller = developer_search_controller();
    session(['user' => 'developer-1']);

    $response = $controller->search(Request::create('/developers/search', 'GET', [
        'query' => 'orders',
        'types' => ['api_keys', 'logs'],
        'limit' => 8,
    ]));

    $results = $response->getData(true)['results'];

    expect($response->getStatusCode())->toBe(200)
        ->and(array_unique(array_column($results, 'type')))->toBe(['Request Log'])
        ->and(array_column($results, 'type'))->not->toContain('API Key')
        ->and(collect($results)->contains(fn (array $result) => $result['label'] === 'req_orders_1' && $result['description'] === 'GET /v1/orders 200 OK'))->toBeTrue()
        ->and($results[0])->toMatchArray([
            'label'       => 'req_orders_1',
            'description' => 'GET /v1/orders 200 OK',
            'models'      => ['req_orders_1'],
        ]);
});
