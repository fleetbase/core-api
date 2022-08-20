<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\Routing\RESTRegistrar;
use Illuminate\Routing\PendingResourceRegistration;
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
     * @return Closure
     */
    public function registerFleetbaseRest()
    {
        /**
         * Registers a REST complicit collection of routes.
         *
         * @param string $name
         * @param string|null $controller
         * @param array $options
         * @return PendingResourceRegistration
         */
        return function (string $name, ?string $controller = null, array $options = []) {
            if ($controller === null) {
                $controller = Str::studly(Str::singular($name)) . 'Controller';
            }

            /** @var \Illuminate\Routing\Router $this */
            if ($this->container && $this->container->bound(RESTRegistrar::class)) {
                $registrar = $this->container->make(RESTRegistrar::class);
            } else {
                $registrar = new RESTRegistrar($this);
            }

            return new PendingResourceRegistration($registrar, $name, $controller, $options);
        };
    }

    public function registerFleetbaseAuthRoutes()
    {
        return function () {
            return $this->group(['prefix' => 'auth'], function () {
                $this->post('/login', 'AuthController@login');
                $this->post('/sign-up', 'AuthController@signUp');
                $this->post('/logout', 'AuthController@logout');
                $this->post('/get-magic-reset-link', 'AuthController@createPasswordReset');
                $this->post('/reset-password', 'AuthController@resetPassword');
                $this->post('/switch-organization', 'AuthController@switchOrganization')->middleware('auth:sanctum');
                $this->post('/join-organization', 'AuthController@joinOrganization')->middleware('auth:sanctum');
                $this->post('/create-organization', 'AuthController@createOrganization')->middleware('auth:sanctum');
                $this->get('/session', 'AuthController@session')->middleware('auth:sanctum');
                $this->get('/organizations', 'AuthController@getUserOrganizations')->middleware('auth:sanctum');
                $this->options('/{action}', 'AuthController@options')->where('action', '[A-Za-z-]+');
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
