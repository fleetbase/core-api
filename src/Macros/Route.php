<?php

namespace Fleetbase\Macros;

use Fleetbase\Routing\RESTRegistrar;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Support\Str;

class Route
{
    /**
     * Registers a REST complicit collection of routes.
     *
     * @return Closure
     */
    public function registerFleetbaseREST()
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

    /**
     * Return the facade to mixin macros to.
     *
     * @return void
     */
    public static function getFacade()
    {
        return \Illuminate\Support\Facades\Route::class;
    }
}
