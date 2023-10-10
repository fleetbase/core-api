<?php

namespace Fleetbase\Traits;

use Closure;
use Illuminate\Support\Facades\DB;

trait Expandable
{
    protected static array $added = [];

    public static function expand($name, \Closure $closure = null)
    {
        if ((is_object($name) || class_exists($name)) && $closure === null) {
            return static::expandFromClass($name);
        }

        $class = get_class(new static());

        if (!isset(static::$added[$class])) {
            static::$added[$class] = [];
        }

        static::$added[$class][$name] = $closure;

        // $methods = static::$added[$class];
        // $classes = array_keys(static::$added);

        // // dd($methods, $classes);

        // // if `isModel()` then bind closure to builder macro
        // if (static::isModel()) {
        //     Builder::macro($name, function (...$args) use ($name, $classes, $closure) {
        //         $invoker = get_class($this->getModel());

        //         if (!in_array($invoker, $classes)) {
        //             throw new \BadMethodCallException("Call to undefined method ${class}::${name}()");
        //         }

        //         return $closure->call($this->getModel(), ...$args);
        //     });
        // }
    }

    public static function expandFromClass($class): void
    {
        $methods = (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);

        foreach ($methods as $method) {
            $method->setAccessible(true);

            if (!static::isMethodExpandable($method, $class)) {
                continue;
            }

            static::expand($method->getName(), $method->invoke($class));
        }
    }

    public static function hasExpansion(string $name): bool
    {
        $class = get_class(new static());

        return isset(static::$added[$class][$name]);
    }

    public static function isExpansion(string $name): bool
    {
        $class = get_class(new static());

        return static::hasExpansion($name) && static::$added[$class][$name] instanceof \Closure;
    }

    public static function getExpansionClosure(string $name)
    {
        $class = get_class(new static());

        return static::$added[$class][$name];
    }

    public function __call($method, $parameters)
    {
        if (static::isExpansion($method)) {
            $closure = static::getExpansionClosure($method);

            return $closure->call($this, ...$parameters);
        }

        if (static::isModel()) {
            if (in_array($method, ['increment', 'decrement'])) {
                return $this->$method(...$parameters);
            }

            // only forward call if connection is working
            try {
                // Try to make a simple DB call
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                // Connection failed, or other error occurred
                return;
            }

            return $this->forwardCallTo($this->newQuery(), $method, $parameters);
        }

        return $this->$method(...$parameters);
    }

    private static function isMethodExpandable(\ReflectionMethod $method, $target)
    {
        $closure = $method->invoke($target);

        return $closure instanceof \Closure;
    }

    private static function isModel()
    {
        return (new static()) instanceof \Illuminate\Database\Eloquent\Model;
    }
}
