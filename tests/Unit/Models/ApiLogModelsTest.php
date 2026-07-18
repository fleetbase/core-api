<?php

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\ApiRequestLog;
use Fleetbase\Models\Company;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Models\WebhookRequestLog;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class ApiLogModelsCacheFake
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

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

function bind_api_log_models_container(): ApiLogModelsCacheFake
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

    $cache = new ApiLogModelsCacheFake();
    $container->instance('cache', $cache);
    Facade::clearResolvedInstance('cache');
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

    return $cache;
}

it('casts api event and request log payloads while preserving response visibility contracts', function () {
    bind_api_log_models_container();

    $event = new ApiEvent();
    $event->fill([
        '_key'                => 'event-key',
        'company_uuid'        => 'company-1',
        'api_credential_uuid' => 'credential-1',
        'access_token_id'     => 'token-1',
        'event'               => 'order.created',
        'source'              => 'api',
        'method'              => 'POST',
        'description'         => 'Order created through API',
        'data'                => [
            'order'   => ['public_id' => 'order_123'],
            'attempt' => 1,
        ],
    ]);

    $credential = new ApiCredential();
    $credential->setRawAttributes([
        'uuid' => 'credential-1',
        'name' => 'Dispatch Integration',
        'key'  => 'flb_live_dispatch',
    ], true);

    $requestLog = new ApiRequestLog();
    $requestLog->fill([
        '_key'                => 'request-key',
        'company_uuid'        => 'company-1',
        'api_credential_uuid' => 'credential-1',
        'access_token_id'     => 'token-1',
        'public_id'           => 'req_123',
        'method'              => 'POST',
        'path'                => '/v1/orders',
        'full_url'            => 'https://api.fleetbase.test/v1/orders?expand=customer',
        'status_code'         => 201,
        'reason_phrase'       => 'Created',
        'duration'            => 42,
        'ip_address'          => '203.0.113.10',
        'version'             => 'v1',
        'source'              => 'public-api',
        'content_type'        => 'application/json',
        'related'             => ['order' => 'order-1'],
        'query_params'        => ['expand' => 'customer'],
        'request_headers'     => ['authorization' => ['Bearer redacted']],
        'request_body'        => ['payload' => ['customer_uuid' => 'customer-1']],
        'request_raw_body'    => '{"payload":{"customer_uuid":"customer-1"}}',
        'response_headers'    => ['content-type' => ['application/json']],
        'response_body'       => ['id' => 'order_123'],
        'response_raw_body'   => '{"id":"order_123"}',
    ]);
    $requestLog->setRelation('apiCredential', $credential);

    $array = $requestLog->toArray();

    expect($event->data)->toBe([
        'order'   => ['public_id' => 'order_123'],
        'attempt' => 1,
    ])
        ->and($event->searcheableFields())->toBe(['event', 'description', 'method'])
        ->and($requestLog->related)->toBe(['order' => 'order-1'])
        ->and($requestLog->query_params)->toBe(['expand' => 'customer'])
        ->and($requestLog->request_headers)->toBe(['authorization' => ['Bearer redacted']])
        ->and($requestLog->request_body)->toBe(['payload' => ['customer_uuid' => 'customer-1']])
        ->and($requestLog->response_headers)->toBe(['content-type' => ['application/json']])
        ->and($requestLog->response_body)->toBe(['id' => 'order_123'])
        ->and($requestLog->api_credential_name)->toBe('Dispatch Integration (flb_live_dispatch)')
        ->and($requestLog->related_resources)->toBeNull()
        ->and($requestLog->searcheableFields())->toBe(['path', 'method', 'full_url', 'content_type', 'ip_address'])
        ->and($array)->toHaveKey('api_credential_name')
        ->and($array)->toHaveKey('related_resources')
        ->and($array)->not->toHaveKey('api_credential');
});

it('falls back to api credential keys and reuses cached request log accessor values', function () {
    $cache = bind_api_log_models_container();

    $credential = new ApiCredential();
    $credential->setRawAttributes([
        'uuid' => 'credential-2',
        'key'  => 'flb_test_key_only',
    ], true);

    $requestLog = new ApiRequestLog();
    $requestLog->setRawAttributes([
        'uuid'                => 'request-log-1',
        'api_credential_uuid' => 'credential-2',
    ], true);
    $requestLog->setRelation('apiCredential', $credential);

    expect($requestLog->api_credential_name)->toBe('flb_test_key_only')
        ->and($cache->values)->not->toBeEmpty();

    $credential->setRawAttributes([
        'uuid' => 'credential-2',
        'key'  => 'flb_test_changed',
    ], true);

    expect($requestLog->api_credential_name)->toBe('flb_test_key_only');
});

it('normalizes webhook request log methods and casts outbound delivery metadata', function () {
    bind_api_log_models_container();

    $log = new WebhookRequestLog();
    $log->fill([
        '_key'                => 'webhook-log-key',
        'public_id'           => 'webhook_req_123',
        'company_uuid'        => 'company-1',
        'webhook_uuid'        => 'webhook-1',
        'api_credential_uuid' => 'credential-1',
        'access_token_id'     => 'token-1',
        'api_event_uuid'      => 'event-1',
        'method'              => 'post',
        'status_code'         => 202,
        'reason_phrase'       => 'Accepted',
        'duration'            => 150,
        'url'                 => 'https://example.com/webhooks/fleetbase',
        'attempt'             => 2,
        'response'            => ['ok' => true],
        'status'              => 'success',
        'headers'             => ['x-fleetbase-signature' => ['sha256=abc']],
        'meta'                => ['retry' => false],
        'sent_at'             => '2026-07-17 12:00:00',
    ]);

    expect($log->method)->toBe('POST')
        ->and($log->response)->toBe(['ok' => true])
        ->and($log->headers)->toBe(['x-fleetbase-signature' => ['sha256=abc']])
        ->and($log->meta)->toBe(['retry' => false])
        ->and((fn () => $this->with)->call($log))->toBe(['apiEvent']);
});

it('keeps api log relationship foreign and owner keys aligned with uuid columns', function () {
    bind_api_log_models_container();

    $event      = new ApiEvent();
    $requestLog = new ApiRequestLog();
    $webhookLog = new WebhookRequestLog();

    expect($event->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($event->company()->getOwnerKeyName())->toBe('uuid')
        ->and($event->apiCredential()->getForeignKeyName())->toBe('api_credential_uuid')
        ->and($event->apiCredential()->getOwnerKeyName())->toBe('uuid')
        ->and($requestLog->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($requestLog->apiCredential()->getForeignKeyName())->toBe('api_credential_uuid')
        ->and($webhookLog->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($webhookLog->webhook()->getForeignKeyName())->toBe('webhook_uuid')
        ->and($webhookLog->webhook()->getRelated())->toBeInstanceOf(WebhookEndpoint::class)
        ->and($webhookLog->apiCredential()->getForeignKeyName())->toBe('api_credential_uuid')
        ->and($webhookLog->apiEvent()->getForeignKeyName())->toBe('api_event_uuid')
        ->and($webhookLog->apiEvent()->getRelated())->toBeInstanceOf(ApiEvent::class)
        ->and($event->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($requestLog->apiCredential()->getRelated())->toBeInstanceOf(ApiCredential::class);
});
