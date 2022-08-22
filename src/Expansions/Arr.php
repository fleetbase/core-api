<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;

class Arr implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Illuminate\Support\Arr::class;
    }

    public function every()
    {
        return function ($array, $callback) {
            return  !in_array(false, array_map($callback, $array));
        };
    }
}
