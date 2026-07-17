<?php

namespace Illuminate\Foundation\Http\Middleware {
    if (!class_exists(TransformsRequest::class)) {
        class TransformsRequest
        {
        }
    }
}

namespace {
    use Fleetbase\Http\Middleware\AttachCacheHeaders;
    use Fleetbase\Http\Middleware\AuthenticateOnceWithBasicAuth;
    use Fleetbase\Http\Middleware\ConvertStringBooleans;
    use Fleetbase\Http\Middleware\RequestTimer;
    use Fleetbase\Http\Middleware\ResetJsonResourceWrap;
    use Fleetbase\Http\Middleware\SetGlobalHeaders;
    use Fleetbase\Http\Middleware\ThrottleRequests;
    use Fleetbase\Http\Middleware\ValidateETag;
    use Fleetbase\Support\ApiModelCache;
    use Illuminate\Cache\ArrayStore;
    use Illuminate\Cache\RateLimiter;
    use Illuminate\Cache\Repository;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\Resources\Json\JsonResource;
    use Illuminate\Support\Facades\Facade;

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

    function middleware_contracts_fixture(array $config = []): void
    {
        bind_test_container(array_replace([
            'api.cache.enabled' => true,
            'api.cache.debug'   => false,
            'app.debug'         => false,
            'cache.default'     => 'array',
        ], $config));

        Facade::clearResolvedInstance('config');
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
}
