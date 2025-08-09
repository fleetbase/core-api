<?php

namespace Fleetbase\Expansions;

use Closure;
use Fleetbase\Build\Expansion;
use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Middleware\ThrottleRequests;
use Fleetbase\Routing\RESTRegistrar;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

class Route implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Illuminate\Support\Facades\Route::class;
    }

    /**
     * Registers a REST complicit collection of routes.
     *
     * @return \Closure
     */
    public function fleetbaseRestRoutes()
    {
        /*
         * Registers a REST complicit collection of routes.
         *
         * @param string $name
         * @param string|null $controller
         * @param array $options
         * @param Closure $callback Can be use to define additional routes
         * @return PendingResourceRegistration
         */
        return function (string $name, $controller = null, $options = [], ?\Closure $callback = null) {
            /** @var Router $this */
            if (is_callable($controller) && $callback === null) {
                $callback   = $controller;
                $controller = null;
            }

            if (is_callable($options) && $callback === null) {
                $callback = $options;
                $options  = [];
            }

            if ($controller === null) {
                $controller = Str::studly(Str::singular($name)) . 'Controller';
            }

            if ($this->container && $this->container->bound(RESTRegistrar::class)) {
                $registrar = $this->container->make(RESTRegistrar::class);
            } else {
                $registrar = new RESTRegistrar($this);
            }

            return (new PendingResourceRegistration($registrar, $name, $controller, $options))->setRouter($this)->extend($callback);
        };
    }

    public function fleetbaseRoutes()
    {
        return function (string $name, callable|array|null $registerFn = null, $options = [], $controller = null) {
            /** @var Router $this */
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

            if (app()->version() > 8) {
                $options['controller'] = $controller;
            }

            $make = function (string $routeName) use ($controller) {
                return $controller . '@' . $routeName;
            };

            // Add groupstack to options
            $options['groupStack'] = $this->getGroupStack();

            $register = function ($router) use ($name, $registerFn, $make, $controller, $options) {
                if (is_callable($registerFn)) {
                    $router->group(
                        ['prefix' => $name],
                        function ($router) use ($registerFn, $make, $controller) {
                            $registerFn($router, $make, $controller);
                        }
                    );
                }

                $router->fleetbaseRestRoutes($name, $controller, $options);
            };

            return $this->group($options, $register);
        };
    }

    public function fleetbaseAuthRoutes(): \Closure
    {
        return function (?string $authControllerClass = null, ?callable $registerFn = null, ?callable $registerProtectedFn = null) {
            $authControllerClass ??= AuthController::class;
            /** @var Router $this */
            $this->group(['prefix' => 'auth'], function (Router $router) use ($authControllerClass, $registerFn, $registerProtectedFn) {
                // Public auth routes with throttle
                $router->group(['middleware' => [ThrottleRequests::class]], function (Router $router) use ($authControllerClass, $registerFn) {
                    $router->post('login', [$authControllerClass, 'login']);
                    $router->post('sign-up', [$authControllerClass, 'signUp']);
                    $router->post('logout', [$authControllerClass, 'logout']);
                    $router->post('get-magic-reset-link', [$authControllerClass, 'createPasswordReset']);
                    $router->post('reset-password', [$authControllerClass, 'resetPassword']);
                    $router->post('create-verification-session', [$authControllerClass, 'createVerificationSession']);
                    $router->post('validate-verification-session', [$authControllerClass, 'validateVerificationSession']);
                    $router->post('send-verification-email', [$authControllerClass, 'sendVerificationEmail']);
                    $router->post('verify-email', [$authControllerClass, 'verifyEmail']);
                    $router->get('validate-verification', [$authControllerClass, 'validateVerificationCode']);

                    if (is_callable($registerFn)) {
                        $registerFn($router);
                    }
                });

                // Protected auth routes
                $router->group(['middleware' => ['fleetbase.protected']], function (Router $router) use ($authControllerClass, $registerProtectedFn) {
                    $router->post('switch-organization', [$authControllerClass, 'switchOrganization']);
                    $router->post('join-organization', [$authControllerClass, 'joinOrganization']);
                    $router->post('create-organization', [$authControllerClass, 'createOrganization']);
                    $router->get('session', [$authControllerClass, 'session']);
                    $router->get('organizations', [$authControllerClass, 'getUserOrganizations']);
                    $router->get('services', [$authControllerClass, 'services']);

                    if (is_callable($registerProtectedFn)) {
                        $registerProtectedFn($router);
                    }
                });
            });
        };
    }

    public function registerFleetbaseOnboardRoutes()
    {
        return function () {
            return $this;
        };
    }
}
