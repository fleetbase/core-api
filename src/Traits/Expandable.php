<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\DB;

trait Expandable
{
    protected static array $added = [];

    public static function expand($name, ?\Closure $closure = null)
    {
        if ((is_object($name) || Utils::classExists($name)) && $closure === null) {
            return static::expandFromClass($name);
        }

        $class = get_class(app(static::class));

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
        $target  = null;
        if (method_exists($class, 'target')) {
            $target = app($class::target());
        }

        foreach ($methods as $method) {
            $method->setAccessible(true);

            if (!static::isMethodExpandable($method, $class)) {
                continue;
            }

            $closure = $method->invoke($target);
            static::expand($method->getName(), $closure);
        }
    }

    public static function hasExpansion(string $name): bool
    {
        $class = get_class(app(static::class));

        return isset(static::$added[$class][$name]);
    }

    public static function isExpansion(string $name): bool
    {
        $class = get_class(app(static::class));

        return static::hasExpansion($name) && static::$added[$class][$name] instanceof \Closure;
    }

    public static function getExpansionClosure(string $name)
    {
        $class = get_class(app(static::class));

        return static::$added[$class][$name];
    }

    public function __call($method, $parameters)
    {
        if (static::isExpansion($method)) {
            $closure = static::getExpansionClosure($method);

            // Ensure $closure is not static
            if (!($closure instanceof \Closure)) {
                throw new \RuntimeException('Invalid closure provided for expansion method `. $method .`');
            }

            // Handle static closures
            $reflection      = new \ReflectionFunction($closure);
            $isStaticClosure = $reflection->isStatic();
            if ($isStaticClosure) {
                return call_user_func($closure, ...$parameters);
            }

            return $closure->call($this, ...$parameters);
        }

        if (static::isModel()) {
            if (method_exists($this, $method)) {
                return $this->$method(...$parameters);
            }

            // only forward call if connection is working
            try {
                // Try to make a simple DB call
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                // Connection failed, or other error occurred
                return $this->$method(...$parameters);
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
