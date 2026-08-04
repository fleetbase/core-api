<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;
use Illuminate\Routing\Router;

class PendingResourceRegistration implements Expansion
{
    private static ?\WeakMap $routers = null;

    public static function target()
    {
        return \Illuminate\Routing\PendingResourceRegistration::class;
    }

    private static function routers(): \WeakMap
    {
        return self::$routers ??= new \WeakMap();
    }

    public function setRouter()
    {
        $routers = self::routers();

        return function (Router $router) use ($routers) {
            /* @var \Illuminate\Routing\PendingResourceRegistration $this */
            $routers[$this] = $router;

            return $this;
        };
    }

    public function extend()
    {
        $routers = self::routers();

        return function (?\Closure $callback = null) use ($routers) {
            /** @var \Illuminate\Routing\PendingResourceRegistration $this */
            $router = $routers[$this] ?? null;

            if ($router instanceof Router && is_callable($callback)) {
                $callback($router);
            }

            return $this;
        };
    }
}
