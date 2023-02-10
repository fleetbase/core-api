<?php

namespace Fleetbase\Support;

class InternalConfig
{
    private static function getConfigArray(): array
    {
        $config = include(__DIR__ . '/../../config/fleetbase.php');

        if (is_array($config)) {
            return $config;
        }

        return [];
    }

    public static function get($key, $default = null)
    {
        $config = static::getConfigArray();
        
        return Utils::get($config, $key, $default);
    }
}
