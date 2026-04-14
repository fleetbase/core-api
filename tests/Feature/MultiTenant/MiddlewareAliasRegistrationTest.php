<?php

test('fleetbase.company.context middleware alias is registered', function () {
    /** @var \Illuminate\Routing\Router $router */
    $router = app('router');
    $aliases = $router->getMiddleware();

    expect($aliases)->toHaveKey('fleetbase.company.context');
    expect($aliases['fleetbase.company.context'])->toBe(\Fleetbase\Http\Middleware\CompanyContextResolver::class);
});
