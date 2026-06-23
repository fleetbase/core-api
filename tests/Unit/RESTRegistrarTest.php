<?php

use Fleetbase\Routing\RESTRegistrar;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;

$registeredRestRoutes = function (array $options = []): array {
    $container = new Container();
    $router    = new Router(new Dispatcher($container), $container);
    $registrar = new RESTRegistrar($router);

    $registrar->register('devices', 'DeviceController', $options);

    return array_map(
        fn ($route) => [
            'methods' => $route->methods(),
            'uri'     => $route->uri(),
            'action'  => $route->getActionName(),
        ],
        $router->getRoutes()->getRoutes()
    );
};

test('rest routes register bulk delete before item routes', function () use ($registeredRestRoutes) {
    $routes = $registeredRestRoutes();
    $uris   = array_column($routes, 'uri');

    expect($uris)->toContain('devices/bulk-delete');
    expect(array_search('devices/bulk-delete', $uris, true))->toBeLessThan(array_search('devices/{device}', $uris, true));

    $bulkDeleteRoute = collect($routes)->first(fn ($route) => $route['uri'] === 'devices/bulk-delete');

    expect($bulkDeleteRoute['methods'])->toContain('DELETE');
    expect($bulkDeleteRoute['action'])->toBe('DeviceController@bulkDelete');
});
