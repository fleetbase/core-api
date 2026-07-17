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
    use Fleetbase\Http\Middleware\ConvertStringBooleans;
    use Fleetbase\Http\Middleware\RequestTimer;
    use Fleetbase\Http\Middleware\ResetJsonResourceWrap;
    use Fleetbase\Http\Middleware\SetGlobalHeaders;
    use Fleetbase\Http\Middleware\ValidateETag;
    use Fleetbase\Support\ApiModelCache;
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
            'api.cache.debug' => false,
            'app.debug' => false,
            'cache.default' => 'array',
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

    test('attach cache headers exposes api cache status driver and debug cache key then resets state', function () {
        middleware_contracts_fixture([
            'api.cache.debug' => true,
            'cache.default' => 'redis',
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
        $transform = new ReflectionMethod($middleware, 'transform');
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
}
