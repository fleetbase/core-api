<?php

use Fleetbase\Attributes\SkipAuthorizationCheck;
use Fleetbase\Support\ControllerResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ControllerResolverTestController extends Controller
{
    #[SkipAuthorizationCheck]
    public function publicAction(): string
    {
        return 'public';
    }

    public function protectedAction(): string
    {
        return 'protected';
    }
}

class ControllerResolverTestRoute
{
    public function __construct(
        public Controller $controller,
        private string $controllerAction,
        private string $actionMethod,
    ) {
    }

    public function getAction(string $key): ?string
    {
        return $key === 'controller' ? $this->controllerAction : null;
    }

    public function getActionMethod(): string
    {
        return $this->actionMethod;
    }
}

function controller_resolver_request(ControllerResolverTestRoute $route): Request
{
    $request = Request::create('/int/v1/test');
    $request->setRouteResolver(fn () => $route);

    return $request;
}

test('controller resolver returns the route controller instance', function () {
    bind_test_container();
    $controller = new ControllerResolverTestController();
    $request    = controller_resolver_request(new ControllerResolverTestRoute(
        $controller,
        ControllerResolverTestController::class . '@protectedAction',
        'protectedAction'
    ));

    expect((new ControllerResolver())->resolveController($request))->toBe($controller)
        ->and(ControllerResolver::resolve($request))->toBe($controller);
});

test('controller resolver extracts controller namespace from route action', function () {
    $request = controller_resolver_request(new ControllerResolverTestRoute(
        new ControllerResolverTestController(),
        ControllerResolverTestController::class . '@publicAction',
        'publicAction'
    ));

    expect(ControllerResolver::getControllerNamespace($request))
        ->toBe(ControllerResolverTestController::class);
});

test('controller resolver detects skip authorization attributes only on decorated methods', function () {
    $controller = new ControllerResolverTestController();

    $publicRequest = controller_resolver_request(new ControllerResolverTestRoute(
        $controller,
        ControllerResolverTestController::class . '@publicAction',
        'publicAction'
    ));
    $protectedRequest = controller_resolver_request(new ControllerResolverTestRoute(
        $controller,
        ControllerResolverTestController::class . '@protectedAction',
        'protectedAction'
    ));

    expect(ControllerResolver::methodHasAttribute($publicRequest, SkipAuthorizationCheck::class))->toBeTrue()
        ->and(ControllerResolver::methodHasAttribute($protectedRequest, SkipAuthorizationCheck::class))->toBeFalse()
        ->and(ControllerResolver::methodHasAttribute($publicRequest, Deprecated::class))->toBeFalse();
});
