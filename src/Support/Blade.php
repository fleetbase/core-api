<?php

namespace Fleetbase\Support;

use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\Blade as Facade;
use Illuminate\Support\Carbon;

class Blade
{
    public function assetFromS3()
    {
        return function ($path) {
            return Utils::assetFromS3($path);
        };
    }

    public function fontFromS3()
    {
        return function ($path) {
            return Utils::assetFromS3('fonts/' . $path);
        };
    }

    public function toTimeString()
    {
        return function ($dateString) {
            return Carbon::parse($dateString)->toTimeString();
        };
    }

    public function toDateTimeString()
    {
        return function ($dateString) {
            return Carbon::parse($dateString)->toDateTimeString();
        };
    }

    public function formatFromCarbon()
    {
        return function ($args) {
            list($dateString, $format) = static::getBladeArgs($args);

            // default format
            $format = $format ?? 'jS \o\f F, Y g:i:s a';

            return '<?= \Illuminate\Support\Carbon::parse(' . $dateString . ')->format(' . $format . ') ?>';
        };
    }

    public function getFromCarbonParse()
    {
        return function ($args) {
            list($dateString, $property) = static::getBladeArgs($args);

            // default property
            $property = $property ?? 'timestamp';

            return '<?= \Illuminate\Support\Carbon::parse(' . $dateString . ')->{' . $property . '} ?>';
        };
    }

    public static function registerBladeDirectives()
    {
        $instance = new static();
        $directives = array_diff(get_class_methods($instance), [__FUNCTION__, 'getBladeArgs']);

        foreach ($directives as $directive) {
            Facade::directive($directive, $instance->{$directive}());
        }
    }

    public static function getBladeArgs($expression)
    {
        return array_map(function ($item) {
            return trim($item);
        }, is_array($expression) ? $expression : explode(',', $expression));
    }
}
