<?php

namespace PhpOption {
    if (!class_exists(Option::class)) {
        class Option
        {
            public function __construct(private mixed $value)
            {
            }

            public static function fromValue(mixed $value): self
            {
                return new self($value);
            }

            public function map(callable $callback): self
            {
                return $this->value === null ? $this : new self($callback($this->value));
            }

            public function getOrCall(callable $callback): mixed
            {
                return $this->value ?? $callback();
            }
        }
    }
}

namespace {
    use Fleetbase\Http\Controllers\Internal\v1\AuthController;
    use Fleetbase\Http\Middleware\ThrottleRequests;
    use Illuminate\Container\Container;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Routing\Router;
    use Illuminate\Support\Env;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Str;

    class RoutesContractContainer extends FleetbaseTestContainer
    {
        public function version(): string
        {
            return '10.0.0';
        }
    }

    function routes_contract_router(): Router
    {
        Container::setInstance(new RoutesContractContainer());

        $container = bind_test_container([
            'app.debug'                             => false,
            'app.env'                               => 'testing',
            'fleetbase.api.routing.prefix'          => '/',
            'fleetbase.api.routing.internal_prefix' => 'int',
        ]);

        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::clearResolvedInstances();

        Router::macro('fleetbaseRestRoutes', function (string $name, $controller = null, $options = []) {
            if ($controller === null) {
                $controller = Str::studly(Str::singular($name)) . 'Controller';
            }

            $wildcard = str_replace('-', '_', Str::singular($name));

            $this->get($name, $controller . '@queryRecord');
            $this->post($name, $controller . '@createRecord');
            $this->delete($name . '/bulk-delete', $controller . '@bulkDelete');
            $this->get($name . '/{' . $wildcard . '}', $controller . '@findRecord');
            $this->match(['PUT', 'PATCH'], $name . '/{' . $wildcard . '}', $controller . '@updateRecord');

            return $this->delete($name . '/{' . $wildcard . '}', $controller . '@deleteRecord');
        });

        Router::macro('fleetbaseRoutes', function (string $name, callable|array|null $registerFn = null, $options = [], $controller = null) {
            if (is_array($registerFn) && !empty($registerFn) && empty($options)) {
                $options = $registerFn;
            }

            if (is_callable($controller) && $registerFn === null) {
                $registerFn = $controller;
                $controller = null;
            }

            if (is_callable($options) && $registerFn === null) {
                $registerFn = $options;
                $options    = [];
            }

            if ($controller === null) {
                $controller = Str::studly(Str::singular($name)) . 'Controller';
            }

            $make = fn (string $routeName): string => $controller . '@' . $routeName;

            return $this->group($options, function (Router $router) use ($name, $registerFn, $make, $controller, $options) {
                if (is_callable($registerFn)) {
                    $router->group(['prefix' => $name], function (Router $router) use ($registerFn, $make, $controller) {
                        $registerFn($router, $make, $controller);
                    });
                }

                $router->fleetbaseRestRoutes($name, $controller, $options);
            });
        });

        Router::macro('fleetbaseAuthRoutes', function (?string $authControllerClass = null) {
            $authControllerClass ??= AuthController::class;

            return $this->group(['prefix' => 'auth'], function (Router $router) use ($authControllerClass) {
                $router->group(['middleware' => [ThrottleRequests::class]], function (Router $router) use ($authControllerClass) {
                    $router->post('login', [$authControllerClass, 'login']);
                    $router->post('sign-up', [$authControllerClass, 'signUp']);
                    $router->post('logout', [$authControllerClass, 'logout']);
                    $router->post('get-magic-reset-link', [$authControllerClass, 'createPasswordReset']);
                    $router->post('reset-password', [$authControllerClass, 'resetPassword']);
                    $router->post('confirm-email-change', [$authControllerClass, 'confirmEmailChange']);
                    $router->post('create-verification-session', [$authControllerClass, 'createVerificationSession']);
                    $router->post('validate-verification-session', [$authControllerClass, 'validateVerificationSession']);
                    $router->post('send-verification-email', [$authControllerClass, 'sendVerificationEmail']);
                    $router->post('verify-email', [$authControllerClass, 'verifyEmail']);
                    $router->get('validate-verification', [$authControllerClass, 'validateVerificationCode']);
                });

                $router->group(['middleware' => ['fleetbase.protected']], function (Router $router) use ($authControllerClass) {
                    $router->post('switch-organization', [$authControllerClass, 'switchOrganization']);
                    $router->post('join-organization', [$authControllerClass, 'joinOrganization']);
                    $router->post('create-organization', [$authControllerClass, 'createOrganization']);
                    $router->get('session', [$authControllerClass, 'session']);
                    $router->get('organizations', [$authControllerClass, 'getUserOrganizations']);
                    $router->get('services', [$authControllerClass, 'services']);
                });
            });
        });

        $repository = new class {
            public function get(string $key): mixed
            {
                return [
                    'APP_DEBUG' => 'false',
                ][$key] ?? null;
            }
        };

        $envRepository = new ReflectionProperty(Env::class, 'repository');
        $envRepository->setAccessible(true);
        $envRepository->setValue(null, $repository);

        require __DIR__ . '/../../src/routes.php';

        return $router;
    }

    function routes_contract_rows(Router $router): array
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

    function routes_contract_find(array $rows, string $method, string $uri): ?array
    {
        return collect($rows)->first(
            fn (array $route) => $route['uri'] === $uri && in_array($method, $route['methods'], true)
        );
    }

    function routes_contract_index(array $rows, string $method, string $uri): int|false
    {
        foreach ($rows as $index => $route) {
            if ($route['uri'] === $uri && in_array($method, $route['methods'], true)) {
                return $index;
            }
        }

        return false;
    }

    afterEach(function () {
        Container::setInstance(new FleetbaseTestContainer());
        Facade::clearResolvedInstances();
    });

    test('route file registers platform public and protected api groups with expected middleware', function () {
        $routes = routes_contract_rows(routes_contract_router());

        $platformOrganizations = routes_contract_find($routes, 'GET', 'v1/organizations');
        $currentOrganization   = routes_contract_find($routes, 'GET', 'v1/organizations/current');
        $publicFileCreate      = routes_contract_find($routes, 'POST', 'v1/files');

        expect($platformOrganizations)->not->toBeNull()
            ->and($platformOrganizations['action'])->toBe('Fleetbase\Http\Controllers\Api\v1\OrganizationController@listOrganizations')
            ->and($platformOrganizations['middleware'])->toContain('fleetbase.platform-api')
            ->and($currentOrganization)->not->toBeNull()
            ->and($currentOrganization['action'])->toBe('Fleetbase\Http\Controllers\Api\v1\OrganizationController@getCurrent')
            ->and($currentOrganization['middleware'])->toContain('fleetbase.api')
            ->and($publicFileCreate)->not->toBeNull()
            ->and($publicFileCreate['action'])->toBe('Fleetbase\Http\Controllers\Api\v1\FileController@create')
            ->and($publicFileCreate['middleware'])->toContain('fleetbase.api');
    });

    test('route file keeps unauthenticated throttled auth routes separate from protected auth routes', function () {
        $routes = routes_contract_rows(routes_contract_router());

        $login   = routes_contract_find($routes, 'POST', 'int/v1/auth/login');
        $switch  = routes_contract_find($routes, 'POST', 'int/v1/auth/switch-organization');
        $session = routes_contract_find($routes, 'GET', 'int/v1/auth/session');

        expect($login)->not->toBeNull()
            ->and($login['action'])->toBe(AuthController::class . '@login')
            ->and($login['middleware'])->toContain(ThrottleRequests::class)
            ->and($login['middleware'])->not->toContain('fleetbase.protected')
            ->and($switch)->not->toBeNull()
            ->and($switch['action'])->toBe(AuthController::class . '@switchOrganization')
            ->and($switch['middleware'])->toContain('fleetbase.protected')
            ->and($session)->not->toBeNull()
            ->and($session['action'])->toBe(AuthController::class . '@session')
            ->and($session['middleware'])->toContain('fleetbase.protected');
    });

    test('route file keeps critical internal custom routes before dynamic resource routes', function () {
        $routes = routes_contract_rows(routes_contract_router());

        expect(routes_contract_index($routes, 'GET', 'int/v1/files/download/{id?}'))
            ->toBeLessThan(routes_contract_index($routes, 'GET', 'int/v1/files/{file}'))
            ->and(routes_contract_index($routes, 'POST', 'int/v1/files/upload'))
            ->toBeLessThan(routes_contract_index($routes, 'GET', 'int/v1/files/{file}'))
            ->and(routes_contract_index($routes, 'GET', 'int/v1/reports/tables/{table}/schema'))
            ->toBeLessThan(routes_contract_index($routes, 'GET', 'int/v1/reports/{report}'))
            ->and(routes_contract_index($routes, 'POST', 'int/v1/reports/{id}/execute'))
            ->toBeLessThan(routes_contract_index($routes, 'GET', 'int/v1/reports/{report}'))
            ->and(routes_contract_index($routes, 'DELETE', 'int/v1/companies/bulk-delete'))
            ->toBeLessThan(routes_contract_index($routes, 'DELETE', 'int/v1/companies/{company}'));
    });

    test('route file exposes critical internal settings metrics and notification contracts', function () {
        $routes = routes_contract_rows(routes_contract_router());

        expect(routes_contract_find($routes, 'GET', 'int/v1/settings/filesystem-config')['action'])
            ->toBe('Fleetbase\Http\Controllers\Internal\v1\SettingController@getFilesystemConfig')
            ->and(routes_contract_find($routes, 'POST', 'int/v1/settings/test-sms-provider-config')['action'])
            ->toBe('Fleetbase\Http\Controllers\Internal\v1\SettingController@testSmsProviderConfig')
            ->and(routes_contract_find($routes, 'GET', 'int/v1/metrics/iam/kpis')['action'])
            ->toBe('Fleetbase\Http\Controllers\Internal\v1\IamMetricsController@kpis')
            ->and(routes_contract_find($routes, 'GET', 'int/v1/metrics/admin/widgets/{widget}')['action'])
            ->toBe('Fleetbase\Http\Controllers\Internal\v1\AdminMetricsController@widget')
            ->and(routes_contract_find($routes, 'GET', 'int/v1/notifications/registry')['action'])
            ->toBe('Fleetbase\Http\Controllers\Internal\v1\NotificationController@registry');
    });
}
