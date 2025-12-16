<?php

namespace Fleetbase\Casts;

use Fleetbase\Support\Utils;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class PolymorphicType implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * Converts full class names back to short types:
     * - Fleetbase\Models\Client -> client
     * - Fleetbase\Fliit\Models\Client -> fliit:client
     * - Fleetbase\FleetOps\Models\Customer -> fleet-ops:customer
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $attributes
     */
    public function get($model, $key, $value, $attributes)
    {
        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $attributes
     */
    public function set($model, $key, $value, $attributes)
    {
        // default $className is null
        $className = null;
        if ($value) {
            $className = Utils::getMutationType($value);
        }

        return $className;
    }
}
