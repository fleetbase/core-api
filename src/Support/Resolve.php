<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use ReflectionClass;

class Resolve
{
    public static function httpResourceForModel($model, string $namespace = null, ?int $version = 1)
    {
        if (is_string($model) && class_exists($model)) {
            $model = static::instance($model);
        }

        if (!$model instanceof Model) {
            throw new \Exception('Invalid model to resolve resource for!');
        }

        $resourceNamespace = Find::httpResourceForModel($model, $namespace, $version);

        return new $resourceNamespace($model);
    }

    public static function httpRequestForModel($model, string $namespace = null, ?int $version = 1)
    {
        if (is_string($model) && class_exists($model)) {
            $model = static::instance($model);
        }

        if (!$model instanceof Model) {
            throw new \Exception('Invalid model to resolve request for!');
        }

        $requestNamespace = Find::httpRequestForModel($model, $namespace, $version);

        return new $requestNamespace();
    }

    public static function httpFilterForModel(Model $model, Request $request, ?int $version = 1)
    {
        $filterNamespace = Find::httpFilterForModel($model);

        if ($filterNamespace) {
            return new $filterNamespace($request);
        }

        return null;
    }

    public static function resourceForMorph($type, $id, $resourceClass = null)
    {
        if (empty($type) || empty($id)) {
            return null;
        }

        $instance = null;

        if (class_exists($type)) {
            $instance = static::instance($type);

            if ($instance instanceof Model) {
                $instance = $instance->where($instance->getQualifiedKeyName(), $id)->first();
            }
        }

        if ($instance) {
            if (class_exists($resourceClass)) {
                $resource = new $resourceClass($instance);
            } else {
                $resource = Find::httpResourceForModel($instance);
            }

            return new $resource($instance);
        }

        return null;
    }

    /**
     * Creates a new instance from a ReflectionClass.
     *
     * @param string $class
     */
    public static function instance($class, $args = [])
    {
        if (is_object($class) === false && is_string($class) === false) {
            return null;
        }

        $instance = null;

        try {
            $instance = (new \ReflectionClass($class))->newInstance(...$args);
        } catch (\ReflectionException $e) {
            $instance = app($class);
        }

        return $instance;
    }
}
