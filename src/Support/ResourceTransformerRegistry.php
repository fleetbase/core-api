<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ResourceTransformerRegistry
{
    /**
     * Array to store registered transformers.
     *
     * @var array
     */
    public static $transformers = [];

    public static function register($transformerClass, array $options = []): void
    {
        if (is_array($transformerClass)) {
            foreach ($transformerClass as $transformerClassElement) {
                if (is_array($transformerClassElement) && count($transformerClassElement) === 2) {
                    static::register($transformerClassElement[0], $transformerClassElement[1]);
                } elseif (is_string($transformerClassElement)) {
                    static::register($transformerClassElement);
                } else {
                    throw new \Exception('Attempted to register invalid notification.');
                }
            }

            return;
        }

        static::$transformers[] = [
            'definition'    => static::fixClassName($transformerClass),
            'target'        => static::fixClassName(static::getTransformerClassProperty($transformerClass, 'target', data_get($options, 'target', null))),
        ];
    }

    private static function getTransformerClassProperty(string $transformerClass, string $property, $defaultValue = null)
    {
        if (!Utils::classExists($transformerClass) || !property_exists($transformerClass, $property)) {
            return $defaultValue;
        }

        $properties = get_class_vars($transformerClass);

        return data_get($properties, $property, $defaultValue);
    }

    public static function resolveByTarget($targetClass)
    {
        foreach (static::$transformers as $transformer) {
            if (isset($transformer['target']) && static::fixClassName($transformer['target']) === static::fixClassName($targetClass)) {
                return $transformer['definition'];
            }
        }

        return null;
    }

    public static function transform(Model $model, array $data = []): array
    {
        $resourceClass = Find::httpResourceForModel($model);
        if ($resourceClass) {
            $transformerClass = static::resolveByTarget($resourceClass);
            if ($transformerClass && method_exists($transformerClass, 'output')) {
                return $transformerClass::output($model, $data);
            }
        }

        return $data;
    }

    public static function fixClassName($className)
    {
        if (is_string($className)) {
            if (Str::startsWith($className, '\\')) {
                return $className;
            }

            return '\\' . $className;
        }

        return $className;
    }
}
