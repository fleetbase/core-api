<?php

namespace Fleetbase\Macros;

use Illuminate\Support\Str;

class Carbon {
    public function fromString()
    {
        return function ($string) {
            if (Str::contains($string, 'day of quarter')) {
                if (Str::contains($string, 'day of quarter')) {
                    return Str::startsWith($string, 'first') ? static::now()->firstOfQuarter() : static::now()->lastOfQuarter();
                }
            }

            if (Str::contains($string, 'of decade')) {
                if (Str::contains($string, 'of decade')) {
                    return Str::startsWith($string, 'start') ? static::now()->startOfDecade() : static::now()->endOfDecade();
                }
            }

            return static::parse($string);
        };
    }

    public static function getFacade()
    {
        return \Illuminate\Support\Carbon::class;
    }
}