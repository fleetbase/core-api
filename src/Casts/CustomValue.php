<?php

namespace Fleetbase\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class CustomValue implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $attributes
     *
     * @return array
     */
    public function get($model, $key, $value, $attributes)
    {
        if (in_array($model->value_type, ['object', 'array'])) {
            return Json::decode($value);
        }

        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $value
     * @param array                               $attributes
     *
     * @return string
     */
    public function set($model, $key, $value, $attributes)
    {
        if (in_array($model->value_type, ['object', 'array'])) {
            return json_encode($value);
        }

        return $value;
    }
}
