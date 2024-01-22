<?php

namespace Fleetbase\Casts;

use Fleetbase\Support\Utils;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Str;

class Money implements CastsAttributes
{
    /**
     * Cast the given value.
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
        if (is_float($value) || Str::contains($value, '.')) {
            $value = number_format((float) $value, 2, '.', '');
        }

        return Utils::numbersOnly($value);
    }
}
