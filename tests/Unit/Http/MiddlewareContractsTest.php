<?php

namespace Illuminate\Foundation\Http\Middleware {
    if (!class_exists(TransformsRequest::class)) {
        class TransformsRequest
        {
        }
    }
}

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
    use Fleetbase\Http\Middleware\AdminGuard;
    use Fleetbase\Http\Middleware\AttachCacheHeaders;
    use Fleetbase\Http\Middleware\AuthenticateOnceWithBasicAuth;
    use Fleetbase\Http\Middleware\ConvertStringBooleans;
    use Fleetbase\Http\Middleware\EnsureFleetbaseConfigured;
    use Fleetbase\Http\Middleware\LogApiRequests;
    use Fleetbase\Http\Middleware\PerformanceMonitoring;
    use Fleetbase\Http\Middleware\RequestTimer;
    use Fleetbase\Http\Middleware\ResetJsonResourceWrap;
    use Fleetbase\Http\Middleware\SetGlobalHeaders;
    use Fleetbase\Http\Middleware\SetupFleetbaseSession;
    use Fleetbase\Http\Middleware\ThrottleRequests;
    use Fleetbase\Http\Middleware\ValidateETag;
    use Fleetbase\Models\User as FleetbaseUser;
    use Fleetbase\Support\ApiModelCache;
    use Illuminate\Cache\ArrayStore;
    use Illuminate\Cache\RateLimiter;
    use Illuminate\Cache\Repository;
    use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\Resources\Json\JsonResource;
    use Illuminate\Support\Facades\Bus;
    use Illuminate\Support\Facades\Facade;
    use Laravel\Sanctum\PersonalAccessToken;

    class MiddlewareContractsHeaders extends SetGlobalHeaders
    {
        protected array $except = ['health'];
    }

    class MiddlewareContractsTestRoute
    {
        public array $action = [];

        public function __construct(private string $uri, string $namespace = '')
        {
            $this->action = ['namespace' => $namespace];
        }

        public function uri(): string
        {
            return $this->uri;
        }
    }

    class MiddlewareContractsDbConnectionFake
    {
        public function __construct(
            private ?string $databaseName = 'fleetbase',
            private bool $throws = false,
        ) {
        }

        public function getPdo(): object
        {
            if ($this->throws) {
                throw new RuntimeException('database unavailable');
            }

            return new stdClass();
        }

        public function getDatabaseName(): ?string
        {
            return $this->databaseName;
        }
    }

    class MiddlewareContractsDbFake
    {
        public function __construct(private MiddlewareContractsDbConnectionFake $connection)
        {
        }

        public function connection(): MiddlewareContractsDbConnectionFake
        {
            return $this->connection;
        }
    }

    class MiddlewareContractsSchemaFake
    {
        public function __construct(private array $tables)
        {
        }

        public function hasTable(string $table): bool
        {
            return in_array($table, $this->tables, true);
        }
    }

    class MiddlewareContractsBusFake
    {
        public array $jobs = [];
        public ?Throwable $throws = null;

        public function dispatch(mixed $job): mixed
        {
            if ($this->throws) {
                throw $this->throws;
            }

            $this->jobs[] = $job;

            return $job;
        }
    }

    class MiddlewareContractsUser extends FleetbaseUser
    {
        private bool $adminForTest;

        public function __construct(bool $admin = false)
        {
            parent::__construct();
            $this->adminForTest = $admin;
            $this->setRawAttributes([
                'uuid' => $admin ? 'admin-user' : 'standard-user',
                'type' => $admin ? 'admin' : 'user',
            ], true);
        }

        public function isAdmin(): bool
        {
            return $this->adminForTest;
        }
    }

    function middleware_contracts_fixture(array $config = []): void
    {
        bind_test_container(array_replace([
            'api.cache.enabled' => true,
            'api.cache.debug'   => false,
            'app.debug'         => false,
            'cache.default'     => 'array',
        ], $config));

        Facade::clearResolvedInstance('config');
        Facade::clearResolvedInstance('log');
        middleware_contracts_set_cache_state(null, null);
    }

    function middleware_contracts_request(string $uri, string $routeUri, array $headers = []): Request
    {
        $request = Request::create($uri, 'GET', [], [], [], [], null);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $request->setRouteResolver(fn () => new MiddlewareContractsTestRoute($routeUri));

        return $request;
    }

    function middleware_contracts_mutation_request(string $uri, string $routeUri, string $method = 'POST'): Request
    {
        $request = Request::create(
            $uri,
            $method,
            ['name' => 'Order 1'],
            [],
            [],
            [
                'HTTP_USER_AGENT' => 'Fleetbase SDK',
                'CONTENT_TYPE'    => 'application/json',
                'REMOTE_ADDR'     => '198.51.100.25',
            ],
            '{"name":"Order 1"}'
        );
        $request->attributes->set('request_start_time', microtime(true) - 0.075);
        $request->setRouteResolver(fn () => new MiddlewareContractsTestRoute($routeUri));

        return $request;
    }

    function middleware_contracts_set_cache_state(?string $status, ?string $key): void
    {
        foreach (['cacheStatus' => $status, 'cacheKey' => $key] as $property => $value) {
            $reflection = new ReflectionProperty(ApiModelCache::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $value);
        }
    }

    function middleware_contracts_throttle(): ThrottleRequests
    {
        return new ThrottleRequests(new RateLimiter(new Repository(new ArrayStore())));
    }

    function middleware_contracts_configured_middleware(?string $databaseName = 'fleetbase', array $tables = ['settings', 'users', 'companies'], bool $throws = false): EnsureFleetbaseConfigured
    {
        $reflection = new ReflectionProperty(EnsureFleetbaseConfigured::class, 'configured');
        $reflection->setAccessible(true);
        $reflection->setValue(null, false);

        app()->instance('db', new MiddlewareContractsDbFake(new MiddlewareContractsDbConnectionFake($databaseName, $throws)));
        app()->instance('db.schema', new MiddlewareContractsSchemaFake($tables));
        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('db.schema');

        return new EnsureFleetbaseConfigured();
    }

    function middleware_contracts_log_middleware(bool $enabled = true): LogApiRequests
    {
        $middleware = new LogApiRequests();
        $reflection = new ReflectionProperty(LogApiRequests::class, 'enabled');
        $reflection->setAccessible(true);
        $reflection->setValue($middleware, $enabled);

        return $middleware;
    }

    test('ensure fleetbase configured skips non api and options requests', function () {
        middleware_contracts_fixture();

        $middleware = middleware_contracts_configured_middleware(null, [], true);
        $health     = $middleware->handle(
            middleware_contracts_request('/health', 'health'),
            fn () => new JsonResponse(['ok' => true])
        );

        $optionsRequest = Request::create('/v1/orders', 'OPTIONS');
        $optionsRequest->setRouteResolver(fn () => new MiddlewareContractsTestRoute('v1/orders'));
        $options = $middleware->handle($optionsRequest, fn () => new JsonResponse(['ok' => true]));

        expect($health->getStatusCode())->toBe(200)
            ->and($health->getData(true))->toBe(['ok' => true])
            ->and($options->getStatusCode())->toBe(200)
            ->and($options->getData(true))->toBe(['ok' => true]);
    });

    test('ensure fleetbase configured returns setup error when database or core tables are missing', function () {
        middleware_contracts_fixture();

        $missingDatabase = middleware_contracts_configured_middleware(null)->handle(
            middleware_contracts_request('/v1/orders', 'v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );
        $missingTable = middleware_contracts_configured_middleware('fleetbase', ['settings', 'users'])->handle(
            middleware_contracts_request('/int/v1/orders', 'int/v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );
        $dbException = middleware_contracts_configured_middleware('fleetbase', [], true)->handle(
            middleware_contracts_request('/v1/orders', 'v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );

        foreach ([$missingDatabase, $missingTable, $dbException] as $response) {
            expect($response->getStatusCode())->toBe(503)
                ->and($response->getData(true))->toMatchArray([
                    'error' => 'fleetbase_not_configured',
                    'errors' => ['fleetbase_not_configured'],
                ]);
        }
    });

    test('ensure fleetbase configured allows configured api requests and caches success', function () {
        middleware_contracts_fixture();

        $middleware = middleware_contracts_configured_middleware();

        $first = $middleware->handle(
            middleware_contracts_request('/v1/orders', 'v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );

        app()->instance('db.schema', new MiddlewareContractsSchemaFake([]));
        Facade::clearResolvedInstance('db.schema');
        $second = $middleware->handle(
            middleware_contracts_request('/int/v1/orders', 'int/v1/orders'),
            fn () => new JsonResponse(['cached' => true])
        );

        expect($first->getStatusCode())->toBe(200)
            ->and($first->getData(true))->toBe(['ok' => true])
            ->and($second->getStatusCode())->toBe(200)
            ->and($second->getData(true))->toBe(['cached' => true]);
    });

    test('log api requests skips disabled read internal and excluded requests', function () {
        middleware_contracts_fixture(['api.version' => 'v1']);
        $bus = new MiddlewareContractsBusFake();
        app()->instance('bus', $bus);
        app()->instance(BusDispatcher::class, $bus);
        Facade::clearResolvedInstance('bus');
        Facade::clearResolvedInstance(BusDispatcher::class);

        $disabled = middleware_contracts_log_middleware(false)->handle(
            middleware_contracts_mutation_request('/v1/orders', 'v1/orders'),
            fn () => new JsonResponse(['id' => 'order_1'], 201)
        );
        $read = middleware_contracts_log_middleware()->handle(
            middleware_contracts_mutation_request('/v1/orders', 'v1/orders', 'HEAD'),
            fn () => new JsonResponse(null, 204)
        );
        $internal = middleware_contracts_log_middleware()->handle(
            middleware_contracts_mutation_request('/int/v1/orders', 'int/v1/orders'),
            fn () => new JsonResponse(['id' => 'order_2'], 201)
        );
        $excluded = middleware_contracts_log_middleware()->handle(
            middleware_contracts_mutation_request('/v1/drivers/driver_1/track', 'v1/drivers/{driver}/track'),
            fn () => new JsonResponse(['id' => 'track_1'], 201)
        );

        expect($disabled->getStatusCode())->toBe(201)
            ->and($read->getStatusCode())->toBe(204)
            ->and($internal->getStatusCode())->toBe(201)
            ->and($excluded->getStatusCode())->toBe(201)
            ->and($bus->jobs)->toBeEmpty();
    });

    test('log api requests dispatches public mutation request logs with payload and session', function () {
        middleware_contracts_fixture(['api.version' => 'v1']);
        session([
            'api_key' => 'flb_live_key',
            'company' => 'company-1',
            'api_credential' => 'internal-console',
            'is_sandbox' => true,
        ]);
        $bus = new MiddlewareContractsBusFake();
        app()->instance('bus', $bus);
        app()->instance(BusDispatcher::class, $bus);
        Facade::clearResolvedInstance('bus');
        Facade::clearResolvedInstance(BusDispatcher::class);

        $response = middleware_contracts_log_middleware()->handle(
            middleware_contracts_mutation_request('/v1/orders?status=created', 'v1/orders'),
            fn () => new JsonResponse(['id' => 'order_1', 'status' => 'created'], 201)
        );

        expect($response->getStatusCode())->toBe(201)
            ->and($bus->jobs)->toHaveCount(1)
            ->and($bus->jobs[0])->toBeInstanceOf(Fleetbase\Jobs\LogApiRequest::class)
            ->and($bus->jobs[0]->dbConnection)->toBe('sandbox')
            ->and($bus->jobs[0]->payload)->toMatchArray([
                '_key' => 'flb_live_key',
                'company_uuid' => 'company-1',
                'method' => 'POST',
                'path' => 'v1/orders',
                'status_code' => 201,
                'reason_phrase' => 'Created',
                'version' => 'v1',
                'source' => 'Fleetbase SDK',
                'content_type' => 'application/json',
                'related' => ['order_1'],
                'query_params' => ['status' => 'created'],
                'request_body' => ['name' => 'Order 1', 'status' => 'created'],
                'request_raw_body' => '{"name":"Order 1"}',
            ]);
    });

    test('log api requests swallows dispatch errors and keeps original response', function () {
        middleware_contracts_fixture(['api.version' => 'v1']);
        $bus = new MiddlewareContractsBusFake();
        $bus->throws = new RuntimeException('queue unavailable');
        app()->instance('bus', $bus);
        app()->instance(BusDispatcher::class, $bus);
        app()->instance('log', new class {
            public array $entries = [];
            public array $errors = [];
            public array $warnings = [];

            public function error(string $message, array $context = []): void
            {
                $this->entries[] = ['error', $message, $context];
                $this->errors[] = [$message, $context];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->entries[] = ['warning', $message, $context];
                $this->warnings[] = [$message, $context];
            }
        });
        Facade::clearResolvedInstance('bus');
        Facade::clearResolvedInstance(BusDispatcher::class);
        Facade::clearResolvedInstance('log');

        $response = middleware_contracts_log_middleware()->handle(
            middleware_contracts_mutation_request('/v1/orders', 'v1/orders'),
            fn () => new JsonResponse(['id' => 'order_1'], 201)
        );

        expect($response->getStatusCode())->toBe(201)
            ->and(app('log')->errors)->toHaveCount(1)
            ->and(app('log')->errors[0][0])->toContain('API request logging failed: queue unavailable');

        middleware_contracts_fixture();
        Facade::clearResolvedInstance(BusDispatcher::class);
    });

    test('attach cache headers exposes api cache status driver and debug cache key then resets state', function () {
        middleware_contracts_fixture([
            'api.cache.debug' => true,
            'cache.default'   => 'redis',
        ]);
        middleware_contracts_set_cache_state('HIT', '{api_query}:orders:company_company-1:v1:abc');

        $response = (new AttachCacheHeaders())->handle(
            middleware_contracts_request('/int/v1/orders', 'int/v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );

        expect($response->headers->get('X-Cache-Status'))->toBe('HIT')
            ->and($response->headers->get('X-Cache-Key'))->toBe('{api_query}:orders:company_company-1:v1:abc')
            ->and($response->headers->get('X-Cache-Driver'))->toBe('redis')
            ->and(ApiModelCache::getCacheStatus())->toBeNull()
            ->and(ApiModelCache::getCacheKey())->toBeNull();
    });

    test('attach cache headers marks api requests as bypass disabled or non debug without leaking keys', function () {
        middleware_contracts_fixture();

        $bypass = (new AttachCacheHeaders())->handle(
            middleware_contracts_request('/v1/orders', 'v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );

        expect($bypass->headers->get('X-Cache-Status'))->toBe('BYPASS')
            ->and($bypass->headers->has('X-Cache-Key'))->toBeFalse()
            ->and($bypass->headers->has('X-Cache-Driver'))->toBeFalse();

        middleware_contracts_fixture(['api.cache.enabled' => false]);
        middleware_contracts_set_cache_state('MISS', '{api_query}:orders:company_company-1:v1:def');

        $disabled = (new AttachCacheHeaders())->handle(
            middleware_contracts_request('/int/v1/orders', 'int/v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );

        expect($disabled->headers->get('X-Cache-Status'))->toBe('DISABLED')
            ->and($disabled->headers->has('X-Cache-Key'))->toBeFalse();

        middleware_contracts_fixture(['api.cache.debug' => false]);
        middleware_contracts_set_cache_state('MISS', '{api_query}:orders:company_company-1:v1:ghi');

        $nonDebug = (new AttachCacheHeaders())->handle(
            middleware_contracts_request('/api/orders', 'orders', ['Accept' => 'application/json']),
            fn () => new JsonResponse(['ok' => true])
        );

        expect($nonDebug->headers->get('X-Cache-Status'))->toBe('MISS')
            ->and($nonDebug->headers->has('X-Cache-Key'))->toBeFalse()
            ->and($nonDebug->headers->get('X-Cache-Driver'))->toBe('array');
    });

    test('attach cache headers ignores non api requests', function () {
        middleware_contracts_fixture(['api.cache.debug' => true]);
        middleware_contracts_set_cache_state('HIT', '{api_query}:orders:company_company-1:v1:abc');

        $response = (new AttachCacheHeaders())->handle(
            middleware_contracts_request('/health', 'health'),
            fn () => new JsonResponse(['ok' => true])
        );

        expect($response->headers->has('X-Cache-Status'))->toBeFalse()
            ->and($response->headers->has('X-Cache-Key'))->toBeFalse();
    });

    test('convert string booleans normalizes api payload booleans without touching lookalikes', function () {
        $middleware = new ConvertStringBooleans();
        $transform  = new ReflectionMethod($middleware, 'transform');
        $transform->setAccessible(true);

        expect($transform->invoke($middleware, 'enabled', 'true'))->toBeTrue()
            ->and($transform->invoke($middleware, 'enabled', 'TRUE'))->toBeTrue()
            ->and($transform->invoke($middleware, 'enabled', 'false'))->toBeFalse()
            ->and($transform->invoke($middleware, 'enabled', 'FALSE'))->toBeFalse()
            ->and($transform->invoke($middleware, 'enabled', 'True'))->toBe('True')
            ->and($transform->invoke($middleware, 'enabled', '0'))->toBe('0')
            ->and($transform->invoke($middleware, 'enabled', true))->toBeTrue();
    });

    test('validate etag returns not modified only when request validators match response etag', function () {
        $matching = Request::create('/int/v1/current-user');
        $matching->headers->set('If-None-Match', '"current-user-etag", "other"');

        $notModified = (new ValidateETag())->handle($matching, function () {
            $response = new JsonResponse(['uuid' => 'user-1']);
            $response->setEtag('current-user-etag');

            return $response;
        });

        expect($notModified->getStatusCode())->toBe(304)
            ->and($notModified->getContent())->toBe('');

        $missing = (new ValidateETag())->handle(
            Request::create('/int/v1/current-user'),
            fn () => new JsonResponse(['uuid' => 'user-1'])
        );

        expect($missing->getStatusCode())->toBe(200)
            ->and($missing->getData(true))->toBe(['uuid' => 'user-1']);

        $nonMatching = Request::create('/int/v1/current-user');
        $nonMatching->headers->set('If-None-Match', '"stale"');

        $fresh = (new ValidateETag())->handle($nonMatching, function () {
            $response = new JsonResponse(['uuid' => 'user-1']);
            $response->setEtag('current-user-etag');

            return $response;
        });

        expect($fresh->getStatusCode())->toBe(200)
            ->and($fresh->headers->get('ETag'))->toBe('"current-user-etag"');
    });

    test('set global headers denies framing unless request is explicitly excepted', function () {
        $middleware = new MiddlewareContractsHeaders();

        $protected = $middleware->handle(
            Request::create('/int/v1/orders'),
            fn () => new JsonResponse(['ok' => true])
        );

        $excepted = $middleware->handle(
            Request::create('/health'),
            fn () => new JsonResponse(['ok' => true])
        );

        expect($protected->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($excepted->headers->has('X-Frame-Options'))->toBeFalse();
    });

    test('request timer stores request start time before later middleware executes', function () {
        $request = Request::create('/int/v1/orders');

        $response = (new RequestTimer())->handle($request, function (Request $handledRequest) {
            expect($handledRequest->attributes->has('request_start_time'))->toBeTrue()
                ->and($handledRequest->attributes->get('request_start_time'))->toBeFloat()
                ->and($handledRequest->attributes->get('request_start_time'))->toBeLessThanOrEqual(microtime(true));

            return new JsonResponse(['ok' => true]);
        });

        expect($response->getData(true))->toBe(['ok' => true]);
    });

    test('reset json resource wrap disables default resource data envelope', function () {
        JsonResource::wrap('data');

        $response = (new ResetJsonResourceWrap())->handle(
            Request::create('/int/v1/users/user-1'),
            fn () => new JsonResource(['uuid' => 'user-1'])
        );

        expect($response->resolve())->toBe(['uuid' => 'user-1']);

        JsonResource::wrap('data');
    });

    test('throttle requests bypasses disabled throttling and audits production usage', function () {
        middleware_contracts_fixture([
            'api.throttle.enabled' => false,
            'app.env'              => 'production',
        ]);

        $request = Request::create('/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Fleetbase Test',
            'REMOTE_ADDR'     => '127.0.0.1',
        ]);

        $response = middleware_contracts_throttle()->handle(
            $request,
            fn () => new JsonResponse(['ok' => true])
        );

        expect($response->getData(true))->toBe(['ok' => true])
            ->and(app('log')->entries)->toContain([
                'warning',
                'API throttling is DISABLED globally',
                [
                    'ip'         => '127.0.0.1',
                    'user_agent' => 'Fleetbase Test',
                    'path'       => 'v1/orders',
                    'method'     => 'POST',
                ],
            ]);
    });

    test('throttle requests bypasses configured unlimited keys from bearer basic or query credentials', function () {
        middleware_contracts_fixture([
            'api.throttle.enabled'        => true,
            'api.throttle.unlimited_keys' => ['Bearer unlimited-key', 'Basic:basic-key', 'Query:query-key'],
        ]);

        app()->instance('log', new class {
            public array $entries = [];

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = ['info', $message, $context];
            }
        });
        Facade::clearResolvedInstance('log');

        $middleware = middleware_contracts_throttle();

        $bearer = $middleware->handle(
            Request::create('/v1/orders', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer unlimited-key']),
            fn () => new JsonResponse(['source' => 'bearer'])
        );

        $query = $middleware->handle(
            Request::create('/v1/orders?api_key=query-key', 'GET'),
            fn () => new JsonResponse(['source' => 'query'])
        );

        $extractApiKey = new ReflectionMethod($middleware, 'extractApiKey');
        $extractApiKey->setAccessible(true);
        $isUnlimitedApiKey = new ReflectionMethod($middleware, 'isUnlimitedApiKey');
        $isUnlimitedApiKey->setAccessible(true);
        $basicRequest = Request::create('/v1/orders', 'GET', [], [], [], ['PHP_AUTH_USER' => 'basic-key']);
        $basicRequest->headers->remove('Authorization');
        $basicKey = $extractApiKey->invoke($middleware, $basicRequest);

        expect($bearer->getData(true))->toBe(['source' => 'bearer'])
            ->and($query->getData(true))->toBe(['source' => 'query'])
            ->and($basicKey)->toBe('Basic:basic-key')
            ->and($isUnlimitedApiKey->invoke($middleware, $basicKey))->toBeTrue()
            ->and(app('log')->entries)->toHaveCount(2)
            ->and(app('log')->entries[0][2]['api_key_prefix'])->toBe('Bearer unlimited-key...')
            ->and(app('log')->entries[1][2]['api_key_prefix'])->toBe('Query:query-key...');
    });

    test('basic auth middleware rejects requests without bearer credentials before continuing', function () {
        middleware_contracts_fixture();

        $continued = false;
        $response  = (new AuthenticateOnceWithBasicAuth())->handle(
            Request::create('/v1/orders', 'GET'),
            function () use (&$continued) {
                $continued = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        expect($continued)->toBeFalse()
            ->and($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe([
                'errors' => ['Oops! The API credentials provided were not valid'],
            ]);
    });

    test('performance monitoring adds timing headers and logs debug and slow request context', function () {
        middleware_contracts_fixture([
            'app.debug' => true,
        ]);

        $request = Request::create('/int/v1/reports?period=today', 'GET');
        $request->setUserResolver(fn () => (object) ['uuid' => 'user-1']);
        $middleware = new PerformanceMonitoring();

        $fastResponse = $middleware->handle($request, fn () => new JsonResponse(['ok' => true]));

        expect($fastResponse->headers->get('X-Response-Time'))->toEndWith('ms')
            ->and($fastResponse->headers->get('X-Memory-Usage'))->toEndWith('MB')
            ->and(app('log')->entries[0][0])->toBe('debug')
            ->and(app('log')->entries[0][1])->toBe('[Performance]')
            ->and(app('log')->entries[0][2]['method'])->toBe('GET')
            ->and(app('log')->entries[0][2]['url'])->toBe('int/v1/reports');

        $slowRequest = Request::create('/int/v1/slow?debug=1', 'POST');
        $slowRequest->setUserResolver(fn () => (object) ['uuid' => 'user-2']);
        $slowResponse = $middleware->handle($slowRequest, function () {
            usleep(1010000);

            return new JsonResponse(['slow' => true]);
        });

        expect($slowResponse->headers->get('X-Response-Time'))->toEndWith('ms')
            ->and(app('log')->entries[1][0])->toBe('warning')
            ->and(app('log')->entries[1][1])->toBe('[Performance] Slow request detected')
            ->and(app('log')->entries[1][2]['method'])->toBe('POST')
            ->and(app('log')->entries[1][2]['url'])->toContain('/int/v1/slow?debug=1')
            ->and(app('log')->entries[1][2]['user'])->toBe('user-2')
            ->and(app('log')->entries[2][0])->toBe('debug')
            ->and(app('log')->entries[2][2]['url'])->toBe('int/v1/slow');
    });

    test('setup fleetbase session stores authenticated user sandbox and sanctum token context', function () {
        middleware_contracts_fixture([
            'database.default'        => 'mysql',
            'fleetbase.connection.db' => 'mysql',
        ]);
        session()->flush();

        $token = new class(['token' => 'plain-sanctum-token']) extends PersonalAccessToken {
            public function getDateFormat()
            {
                return 'Y-m-d H:i:s';
            }
        };
        $token->setRawAttributes([
            'id' => 987,
            'token' => 'plain-sanctum-token',
            'created_at' => '2026-07-18 12:00:00',
        ], true);

        $user = new class($token) {
            public string $uuid = 'user-1';
            public string $company_uuid = 'company-1';

            public function __construct(private PersonalAccessToken $token)
            {
            }

            public function isAdmin(): bool
            {
                return true;
            }

            public function isType(string $type): bool
            {
                return $type === 'driver';
            }

            public function currentAccessToken(): PersonalAccessToken
            {
                return $this->token;
            }
        };

        $request = Request::create('/int/v1/orders', 'GET', [], [], [], [
            'HTTP_ACCESS_CONSOLE_SANDBOX' => '1',
            'HTTP_ACCESS_CONSOLE_SANDBOX_KEY' => 'sandbox-credential-1',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = (new SetupFleetbaseSession())->handle($request, fn () => new JsonResponse(['ok' => true]));

        expect($response->getData(true))->toBe(['ok' => true])
            ->and(session('company'))->toBe('company-1')
            ->and(session('user'))->toBe('user-1')
            ->and(session('is_admin'))->toBeTrue()
            ->and(session('is_customer'))->toBeFalse()
            ->and(session('is_driver'))->toBeTrue()
            ->and(config('database.default'))->toBe('sandbox')
            ->and(config('fleetbase.connection.db'))->toBe('sandbox')
            ->and(session('is_sandbox'))->toBeTrue()
            ->and(session('sandbox_api_credential'))->toBe('sandbox-credential-1')
            ->and(session('is_sanctum_token'))->toBeTrue()
            ->and(session('api_credential'))->toBe(987)
            ->and(session('api_key'))->toBe('plain-sanctum-token')
            ->and(session('api_environment'))->toBe('live')
            ->and(session('api_test_mode'))->toBeFalse();
    });

    test('admin guard only continues for authenticated admin users', function () {
        middleware_contracts_fixture();
        session()->flush();

        $guestContinued = false;
        $guestResponse = (new AdminGuard())->handle(
            Request::create('/int/v1/admin', 'GET'),
            function () use (&$guestContinued) {
                $guestContinued = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        session(['user' => 'admin-user']);
        $adminRequest = Request::create('/int/v1/admin', 'GET');
        $adminRequest->setUserResolver(fn () => new MiddlewareContractsUser(true));
        $adminContinued = false;
        $adminResponse = (new AdminGuard())->handle(
            $adminRequest,
            function () use (&$adminContinued) {
                $adminContinued = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        session(['user' => 'standard-user']);
        $standardRequest = Request::create('/int/v1/admin', 'GET');
        $standardRequest->setUserResolver(fn () => new MiddlewareContractsUser(false));
        $standardContinued = false;
        $standardResponse = (new AdminGuard())->handle(
            $standardRequest,
            function () use (&$standardContinued) {
                $standardContinued = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        expect($guestContinued)->toBeFalse()
            ->and($guestResponse->getStatusCode())->toBe(401)
            ->and($guestResponse->getData(true))->toBe(['errors' => ['User is not authorized to access this resource.']])
            ->and($adminContinued)->toBeTrue()
            ->and($adminResponse->getData(true))->toBe(['ok' => true])
            ->and($standardContinued)->toBeFalse()
            ->and($standardResponse->getStatusCode())->toBe(401)
            ->and($standardResponse->getData(true))->toBe(['errors' => ['User is not authorized to access this resource.']]);
    });
}
