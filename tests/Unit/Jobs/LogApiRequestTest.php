<?php

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
        }
    }
}

namespace Fleetbase\Jobs {
    if (!function_exists('Fleetbase\\Jobs\\getallheaders')) {
        function getallheaders(): array
        {
            return [
                'Authorization' => 'Bearer token-value',
                'X-Request-Id'  => 'request-1',
            ];
        }
    }
}

namespace {
    use Fleetbase\Jobs\LogApiRequest;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Eloquent\Model as EloquentModel;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Facade;

    class LogApiRequestResponseCacheFake
    {
        public int $clears = 0;

        public function clear(): void
        {
            $this->clears++;
        }
    }

    class LogApiRequestCacheFake
    {
        private array $values = [];

        public function tags(array|string $tags): self
        {
            return $this;
        }

        public function rememberForever(string $key, callable $callback): mixed
        {
            return $callback();
        }

        public function forget(string $key): bool
        {
            return true;
        }

        public function flush(): bool
        {
            return true;
        }

        public function increment(string $key, int $value = 1): int
        {
            $this->values[$key] = (int) ($this->values[$key] ?? 0) + $value;

            return $this->values[$key];
        }
    }

    function log_api_request_database(): Capsule
    {
        EloquentModel::clearBootedModels();

        $connection = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = bind_test_container([
            'api.version'                => 'v1',
            'database.default'           => 'mysql',
            'database.connections.mysql' => $connection,
            'fleetbase.connection.db'    => 'mysql',
        ]);

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
        $schema->create('api_credentials', function ($table) {
            $table->string('uuid')->primary();
            $table->string('company_uuid')->nullable();
            $table->string('key')->nullable();
            $table->string('secret')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('personal_access_tokens', function ($table) {
            $table->id();
            $table->string('tokenable_type')->nullable();
            $table->unsignedBigInteger('tokenable_id')->nullable();
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        $schema->create('api_request_logs', function ($table) {
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable();
            $table->string('_key')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('api_credential_uuid')->nullable();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->string('method')->nullable();
            $table->string('path')->nullable();
            $table->string('full_url')->nullable();
            $table->integer('status_code')->nullable();
            $table->string('reason_phrase')->nullable();
            $table->float('duration')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('version')->nullable();
            $table->string('source')->nullable();
            $table->string('content_type')->nullable();
            $table->json('related')->nullable();
            $table->json('query_params')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();
            $table->text('request_raw_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();
            $table->text('response_raw_body')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        return $capsule;
    }

    function loggable_request(float $startedAt): Request
    {
        $request = Request::create(
            '/v1/orders?status=created',
            'POST',
            ['name' => 'Order 1'],
            [],
            [],
            [
                'HTTP_USER_AGENT' => 'Fleetbase SDK',
                'CONTENT_TYPE'    => 'application/json',
                'REMOTE_ADDR'     => '198.51.100.10',
            ],
            '{"name":"Order 1"}'
        );
        $request->attributes->set('request_start_time', $startedAt);

        return $request;
    }

    beforeEach(function () {
        session()->flush();
        Facade::clearResolvedInstances();
    });

    it('builds loggable API request payloads with response and request metadata', function () {
        bind_test_container(['api.version' => 'v1']);
        session([
            'api_key'        => 'live_key',
            'api_credential' => 'internal-console',
            'company'        => 'company-1',
        ]);

        $request  = loggable_request(microtime(true) - 0.125);
        $response = new JsonResponse(['id' => 'order_1', 'status' => 'created'], 201, ['X-Fleetbase' => 'ok']);

        $payload = LogApiRequest::getPayload($request, $response);

        expect($payload)->toMatchArray([
            '_key'              => 'live_key',
            'company_uuid'      => 'company-1',
            'method'            => 'POST',
            'path'              => 'v1/orders',
            'full_url'          => 'http://localhost/v1/orders',
            'status_code'       => 201,
            'reason_phrase'     => 'Created',
            'ip_address'        => '198.51.100.10',
            'version'           => 'v1',
            'source'            => 'Fleetbase SDK',
            'content_type'      => 'application/json',
            'related'           => ['order_1'],
            'query_params'      => ['status' => 'created'],
            'request_headers'   => [
                'Authorization' => 'Bearer token-value',
                'X-Request-Id'  => 'request-1',
            ],
            'request_body'      => ['name' => 'Order 1', 'status' => 'created'],
            'request_raw_body'  => '{"name":"Order 1"}',
            'response_headers'  => [
                'Cache-Control' => 'no-cache, private',
                'Date'          => $payload['response_headers']['Date'],
                'Content-Type'  => 'application/json',
                'X-Fleetbase'   => 'ok',
            ],
            'response_raw_body' => '{"id":"order_1","status":"created"}',
        ])
            ->and((array) $payload['response_body'])->toBe(['id' => 'order_1', 'status' => 'created'])
            ->and($payload['duration'])->toBeFloat()
            ->and($payload['duration'])->toBeGreaterThan(0)
            ->and($payload)->not->toHaveKeys(['api_credential_uuid', 'access_token_id']);
    });

    it('persists API request logs on the selected connection and clears response cache', function () {
        $database = log_api_request_database();
        $cache    = new LogApiRequestResponseCacheFake();
        app()->instance('responsecache', $cache);
        app()->instance('cache', new LogApiRequestCacheFake());
        Facade::clearResolvedInstance('responsecache');
        Facade::clearResolvedInstance('cache');

        $payload = [
            '_key'              => 'live_key',
            'company_uuid'      => 'company-1',
            'method'            => 'POST',
            'path'              => 'v1/orders',
            'full_url'          => 'http://localhost/v1/orders',
            'status_code'       => 201,
            'reason_phrase'     => 'Created',
            'duration'          => 0.125,
            'ip_address'        => '198.51.100.10',
            'version'           => 'v1',
            'source'            => 'Fleetbase SDK',
            'content_type'      => 'application/json',
            'related'           => ['order_1'],
            'query_params'      => ['status' => 'created'],
            'request_headers'   => ['X-Request-Id' => 'request-1'],
            'request_body'      => ['name' => 'Order 1'],
            'request_raw_body'  => '{"name":"Order 1"}',
            'response_headers'  => ['Content-Type' => 'application/json'],
            'response_body'     => ['id' => 'order_1'],
            'response_raw_body' => '{"id":"order_1"}',
        ];

        (new LogApiRequest($payload, 'mysql'))->handle();

        $log = $database->getConnection('mysql')->table('api_request_logs')->first();

        expect($log)->not->toBeNull()
            ->and($log->_key)->toBe('live_key')
            ->and($log->company_uuid)->toBe('company-1')
            ->and($log->method)->toBe('POST')
            ->and($log->path)->toBe('v1/orders')
            ->and($log->status_code)->toBe(201)
            ->and(json_decode($log->related, true))->toBe(['order_1'])
            ->and(json_decode($log->request_body, true))->toBe(['name' => 'Order 1'])
            ->and($cache->clears)->toBe(2);
    });

    it('attributes payloads to valid API credentials and personal access tokens only', function () {
        $database = log_api_request_database();
        $database->getConnection('mysql')->table('api_credentials')->insert([
            'uuid'         => '11111111-1111-4111-8111-111111111111',
            'company_uuid' => 'company-1',
            'key'          => 'live_key',
            'secret'       => 'secret',
            'created_at'   => '2026-07-17 10:00:00',
            'updated_at'   => '2026-07-17 10:00:00',
        ]);
        $database->getConnection('mysql')->table('personal_access_tokens')->insert([
            'id'             => 99,
            'tokenable_type' => 'Fleetbase\\Models\\User',
            'tokenable_id'   => 1,
            'name'           => 'Console Token',
            'token'          => str_repeat('a', 64),
            'abilities'      => json_encode(['*']),
            'created_at'     => '2026-07-17 10:00:00',
            'updated_at'     => '2026-07-17 10:00:00',
        ]);

        session([
            'api_key'        => 'live_key',
            'api_credential' => '11111111-1111-4111-8111-111111111111',
            'company'        => 'company-1',
        ]);

        $credentialPayload = LogApiRequest::getPayload(
            loggable_request(microtime(true) - 0.01),
            new JsonResponse(['ok' => true], 200)
        );

        session([
            'api_key'        => 'token_key',
            'api_credential' => '99',
            'company'        => 'company-1',
        ]);

        $tokenPayload = LogApiRequest::getPayload(
            loggable_request(microtime(true) - 0.01),
            new JsonResponse(['ok' => true], 200)
        );

        session([
            'api_key'        => 'missing_key',
            'api_credential' => '22222222-2222-4222-8222-222222222222',
            'company'        => 'company-1',
        ]);

        $missingCredentialPayload = LogApiRequest::getPayload(
            loggable_request(microtime(true) - 0.01),
            new JsonResponse(['ok' => true], 200)
        );

        expect($credentialPayload['api_credential_uuid'])->toBe('11111111-1111-4111-8111-111111111111')
            ->and($credentialPayload)->not->toHaveKey('access_token_id')
            ->and($tokenPayload['access_token_id'])->toBe(99)
            ->and($tokenPayload)->not->toHaveKey('api_credential_uuid')
            ->and($missingCredentialPayload)->not->toHaveKeys(['api_credential_uuid', 'access_token_id']);
    });

    it('exposes stable response helper and session selection contracts', function () {
        bind_test_container(['api.version' => 'v1']);

        $response = new JsonResponse(['error' => 'rate limited'], 429, [
            'x-ratelimit-remaining' => '0',
            'x-request-id'          => 'request-2',
        ]);

        session(['is_sandbox' => false]);

        expect(LogApiRequest::getSession())->toBe('mysql')
            ->and(LogApiRequest::getResponseStatusText($response))->toBe('Too Many Requests')
            ->and(LogApiRequest::getResponseHeaders($response))->toMatchArray([
                'Cache-Control'         => 'no-cache, private',
                'Content-Type'          => 'application/json',
                'X-Ratelimit-Remaining' => '0',
                'X-Request-Id'          => 'request-2',
            ]);

        session(['is_sandbox' => true]);

        expect(LogApiRequest::getSession())->toBe('sandbox')
            ->and((new LogApiRequest(['method' => 'GET'], 'sandbox'))->payload)->toBe(['method' => 'GET'])
            ->and((new LogApiRequest(['method' => 'GET'], 'sandbox'))->dbConnection)->toBe('sandbox');
    });
}
