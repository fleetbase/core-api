<?php

namespace Fleetbase\Casts;

use Fleetbase\Support\Utils;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class PolymorphicType implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return mixed
     */
    public function get($model, $key, $value, $attributes)
    {
        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return mixed
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
