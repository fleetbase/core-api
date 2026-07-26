<?php

use Fleetbase\Http\Middleware\ThrottleRequests;
use Fleetbase\Routing\RESTRegistrar;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\PendingResourceRegistration as LaravelPendingResourceRegistration;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;

class RouteExpansionContainer extends FleetbaseTestContainer
{
    public function version(): string
    {
        return '10.0.0';
    }
}

class RouteExpansionAuthController
{
    public function login(): void
    {
    }

    public function signUp(): void
    {
    }

    public function logout(): void
    {
    }

    public function createPasswordReset(): void
    {
    }

    public function resetPassword(): void
    {
    }

    public function confirmEmailChange(): void
    {
    }

    public function createVerificationSession(): void
    {
    }

    public function validateVerificationSession(): void
    {
    }

    public function sendVerificationEmail(): void
    {
    }

    public function verifyEmail(): void
    {
    }

    public function validateVerificationCode(): void
    {
    }

    public function switchOrganization(): void
    {
    }

    public function joinOrganization(): void
    {
    }

    public function createOrganization(): void
    {
    }

    public function session(): void
    {
    }

    public function getUserOrganizations(): void
    {
    }

    public function services(): void
    {
    }

    public function magicLogin(): void
    {
    }

    public function profile(): void
    {
    }
}

function route_expansion_router(): Router
{
    Container::setInstance(new RouteExpansionContainer());

    $container = bind_test_container([
        'app.env' => 'testing',
    ]);

    $router = new Router(new Dispatcher($container), $container);
    $container->instance('router', $router);
    $container->bind(RESTRegistrar::class, fn () => new RESTRegistrar($router));

    Facade::clearResolvedInstances();

    $routeExpansion = new Fleetbase\Expansions\Route();
    Router::macro('fleetbaseRestRoutes', $routeExpansion->fleetbaseRestRoutes());
    Router::macro('fleetbaseRoutes', $routeExpansion->fleetbaseRoutes());
    Router::macro('fleetbaseAuthRoutes', $routeExpansion->fleetbaseAuthRoutes());
    Router::macro('registerFleetbaseOnboardRoutes', $routeExpansion->registerFleetbaseOnboardRoutes());

    $pendingExpansion = new Fleetbase\Expansions\PendingResourceRegistration();
    LaravelPendingResourceRegistration::macro('setRouter', $pendingExpansion->setRouter());
    LaravelPendingResourceRegistration::macro('extend', $pendingExpansion->extend());

    return $router;
}

function route_expansion_rows(Router $router): array
{
    return array_map(
        fn ($route) => [
            'methods'    => array_values(array_diff($route->methods(), ['HEAD'])),
            'uri'        => $route->uri(),
            'action'     => $route->getActionName(),
            'middleware' => $route->middleware(),
        ],
        $router->getRoutes()->getRoutes()
    );
}

function route_expansion_find(array $rows, string $method, string $uri): ?array
{
    return collect($rows)->first(
        fn (array $route) => $route['uri'] === $uri && in_array($method, $route['methods'], true)
    );
}

function route_expansion_index(array $rows, string $method, string $uri): int|false
{
    foreach ($rows as $index => $route) {
        if ($route['uri'] === $uri && in_array($method, $route['methods'], true)) {
            return $index;
        }
    }

    return false;
}

afterEach(function () {
    Router::flushMacros();
    LaravelPendingResourceRegistration::flushMacros();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('route expansion registers rest routes with default controller callback and bulk delete ordering', function () {
    $router = route_expansion_router();

    expect(Fleetbase\Expansions\Route::target())->toBe(Illuminate\Support\Facades\Route::class);

    $pending = $router->fleetbaseRestRoutes('delivery-zones', function (Router $router) {
        $router->get('delivery-zones/map', 'DeliveryZoneController@map');
    });
    $pending->register();

    $routes = route_expansion_rows($router);

    expect(route_expansion_find($routes, 'GET', 'delivery-zones/map')['action'])
        ->toBe('DeliveryZoneController@map')
        ->and(route_expansion_find($routes, 'GET', 'delivery-zones')['action'])
        ->toBe('DeliveryZoneController@queryRecord')
        ->and(route_expansion_find($routes, 'POST', 'delivery-zones')['action'])
        ->toBe('DeliveryZoneController@createRecord')
        ->and(route_expansion_find($routes, 'DELETE', 'delivery-zones/bulk-delete')['action'])
        ->toBe('DeliveryZoneController@bulkDelete')
        ->and(route_expansion_find($routes, 'GET', 'delivery-zones/{delivery_zone}')['action'])
        ->toBe('DeliveryZoneController@findRecord')
        ->and(route_expansion_find($routes, 'PUT', 'delivery-zones/{delivery_zone}')['action'])
        ->toBe('DeliveryZoneController@updateRecord')
        ->and(route_expansion_find($routes, 'PATCH', 'delivery-zones/{delivery_zone}')['action'])
        ->toBe('DeliveryZoneController@updateRecord')
        ->and(route_expansion_find($routes, 'DELETE', 'delivery-zones/{delivery_zone}')['action'])
        ->toBe('DeliveryZoneController@deleteRecord')
        ->and(route_expansion_index($routes, 'DELETE', 'delivery-zones/bulk-delete'))
        ->toBeLessThan(route_expansion_index($routes, 'DELETE', 'delivery-zones/{delivery_zone}'));
});

test('route expansion creates a rest registrar when the container has no registrar binding', function () {
    $container = bind_test_container(['app.env' => 'testing']);
    $router    = new Router(new Dispatcher($container), $container);

    $routeExpansion = new Fleetbase\Expansions\Route();
    Router::macro('fleetbaseRestRoutes', $routeExpansion->fleetbaseRestRoutes());

    $pendingExpansion = new Fleetbase\Expansions\PendingResourceRegistration();
    LaravelPendingResourceRegistration::macro('setRouter', $pendingExpansion->setRouter());
    LaravelPendingResourceRegistration::macro('extend', $pendingExpansion->extend());

    $router->fleetbaseRestRoutes('audit-events')->register();

    $routes = route_expansion_rows($router);

    expect(route_expansion_find($routes, 'GET', 'audit-events')['action'])
        ->toBe('AuditEventController@queryRecord')
        ->and(route_expansion_find($routes, 'DELETE', 'audit-events/bulk-delete')['action'])
        ->toBe('AuditEventController@bulkDelete');
});

test('route expansion wraps custom fleetbase routes and preserves generated controller helper', function () {
    $router = route_expansion_router();

    $router->group(['prefix' => 'int/v1'], function (Router $router) {
        $router->fleetbaseRoutes('widgets', function (Router $router, callable $make, string $controller) {
            expect($controller)->toBe('WidgetController')
                ->and($make('stats'))->toBe('WidgetController@stats');

            $router->get('stats', $make('stats'));
        });
    });

    $routes = route_expansion_rows($router);

    expect(route_expansion_find($routes, 'GET', 'int/v1/widgets/stats')['action'])
        ->toBe('WidgetController@stats')
        ->and(route_expansion_find($routes, 'GET', 'int/v1/widgets')['action'])
        ->toBe('WidgetController@queryRecord')
        ->and(route_expansion_find($routes, 'GET', 'int/v1/widgets/{widget}')['action'])
        ->toBe('WidgetController@findRecord')
        ->and(route_expansion_find($routes, 'DELETE', 'int/v1/widgets/bulk-delete')['action'])
        ->toBe('WidgetController@bulkDelete');
});

test('route expansion registers prefixed rest route groups from slash separated names', function () {
    $router = route_expansion_router();

    $router->fleetbaseRestRoutes('admin/widgets', 'AdminWidgetController')->register();

    $routes = route_expansion_rows($router);

    expect(route_expansion_find($routes, 'GET', 'admin/widgets')['action'])
        ->toBe('AdminWidgetController@queryRecord')
        ->and(route_expansion_find($routes, 'POST', 'admin/widgets')['action'])
        ->toBe('AdminWidgetController@createRecord')
        ->and(route_expansion_find($routes, 'DELETE', 'admin/widgets/bulk-delete')['action'])
        ->toBe('AdminWidgetController@bulkDelete')
        ->and(route_expansion_find($routes, 'GET', 'admin/widgets/{widget}')['action'])
        ->toBe('AdminWidgetController@findRecord');
});

test('route expansion accepts rest route callbacks in the options slot', function () {
    $router = route_expansion_router();

    $router->fleetbaseRestRoutes('service-areas', 'ServiceAreaController', function (Router $router) {
        $router->get('service-areas/summary', 'ServiceAreaController@summary');
    })->register();

    $routes = route_expansion_rows($router);

    expect(route_expansion_find($routes, 'GET', 'service-areas/summary')['action'])
        ->toBe('ServiceAreaController@summary')
        ->and(route_expansion_find($routes, 'GET', 'service-areas')['action'])
        ->toBe('ServiceAreaController@queryRecord');
});

test('route expansion accepts fleetbase route options and controller-slot callbacks', function () {
    $router = route_expansion_router();

    $router->fleetbaseRoutes('service-tools', ['middleware' => ['fleetbase.tools']]);

    $router->fleetbaseRoutes('service-zones', null, function (Router $router, callable $make, string $controller) {
        expect($controller)->toBe('ServiceZoneController')
            ->and($make('coverage'))->toBe('ServiceZoneController@coverage');

        $router->get('coverage', $make('coverage'));
    });

    $router->fleetbaseRoutes('audits', null, [], function (Router $router, callable $make, string $controller) {
        expect($controller)->toBe('AuditController')
            ->and($make('timeline'))->toBe('AuditController@timeline');

        $router->get('timeline', $make('timeline'));
    });

    $routes = route_expansion_rows($router);

    expect(route_expansion_find($routes, 'GET', 'service-tools')['middleware'])
        ->toContain('fleetbase.tools')
        ->and(route_expansion_find($routes, 'GET', 'service-tools')['action'])
        ->toBe('ServiceToolController@queryRecord')
        ->and(route_expansion_find($routes, 'GET', 'service-zones/coverage')['action'])
        ->toBe('ServiceZoneController@coverage')
        ->and(route_expansion_find($routes, 'GET', 'service-zones')['action'])
        ->toBe('ServiceZoneController@queryRecord')
        ->and(route_expansion_find($routes, 'GET', 'audits/timeline')['action'])
        ->toBe('AuditController@timeline')
        ->and(route_expansion_find($routes, 'GET', 'audits')['action'])
        ->toBe('AuditController@queryRecord');
});

test('route expansion registers public and protected auth routes and extension callbacks', function () {
    $router = route_expansion_router();

    $router->fleetbaseAuthRoutes(
        RouteExpansionAuthController::class,
        fn (Router $router) => $router->post('magic-login', [RouteExpansionAuthController::class, 'magicLogin']),
        fn (Router $router) => $router->get('profile', [RouteExpansionAuthController::class, 'profile'])
    );

    $routes  = route_expansion_rows($router);
    $login   = route_expansion_find($routes, 'POST', 'auth/login');
    $magic   = route_expansion_find($routes, 'POST', 'auth/magic-login');
    $switch  = route_expansion_find($routes, 'POST', 'auth/switch-organization');
    $profile = route_expansion_find($routes, 'GET', 'auth/profile');

    expect($login)->not->toBeNull()
        ->and($login['action'])->toBe(RouteExpansionAuthController::class . '@login')
        ->and($login['middleware'])->toContain(ThrottleRequests::class)
        ->and($login['middleware'])->not->toContain('fleetbase.protected')
        ->and($magic)->not->toBeNull()
        ->and($magic['action'])->toBe(RouteExpansionAuthController::class . '@magicLogin')
        ->and($magic['middleware'])->toContain(ThrottleRequests::class)
        ->and($switch)->not->toBeNull()
        ->and($switch['action'])->toBe(RouteExpansionAuthController::class . '@switchOrganization')
        ->and($switch['middleware'])->toContain('fleetbase.protected')
        ->and($profile)->not->toBeNull()
        ->and($profile['action'])->toBe(RouteExpansionAuthController::class . '@profile')
        ->and($profile['middleware'])->toContain('fleetbase.protected');
});

test('route expansion onboard macro returns the router instance', function () {
    $router = route_expansion_router();

    expect($router->registerFleetbaseOnboardRoutes())->toBe($router);
});
