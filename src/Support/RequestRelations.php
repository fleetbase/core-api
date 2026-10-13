<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Restricts client-supplied relation names (`with`, `expand`, `with_count`, `count`) to real relations.
 *
 * Eloquent resolves an eager load or a count by calling `$model->newInstance()->{$name}()`, so an
 * unchecked name runs any public method that needs no arguments on a blank model: `with[]=saveInstance`
 * inserts an empty row, and fleetops' `Order::dispatch()` saves an order and queues its events.
 *
 * A name is accepted when the method (or the Expandable closure registered under that name) declares an
 * Eloquent Relation return type, or when the model lists it in a `$withable` array. Each segment of a
 * nested path is checked against the model the previous segment relates to, and the path is cut at the
 * first segment that fails. Nothing after a morphTo is kept, since its related model is only known per
 * result. Rejected names are dropped without an error.
 */
class RequestRelations
{
    /**
     * Most paths read from one request.
     */
    public const MAX_PATHS = 50;

    /**
     * Most segments kept in one nested path.
     */
    public const MAX_DEPTH = 5;

    /**
     * Get the eager load paths a request may apply to a model.
     *
     * @param mixed $relations a comma-separated string or a list of dot paths
     *
     * @return string[] the accepted paths, with each segment named as the model's method
     */
    public static function eagerLoads(Model $model, mixed $relations): array
    {
        $paths = [];
        foreach (static::split($relations) as $path) {
            $current  = $model;
            $segments = [];
            foreach (explode('.', $path) as $segment) {
                $method   = static::relationMethod($current, $segment);
                $relation = $method ? static::relation($current, $method) : null;
                if (!$relation) {
                    break;
                }

                $segments[] = $method;
                if ($relation instanceof MorphTo || count($segments) >= static::MAX_DEPTH) {
                    break;
                }

                $current = $relation->getRelated();
            }

            if ($segments) {
                $paths[] = implode('.', $segments);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Get the relation counts a request may apply to a model.
     *
     * @param mixed $relations a comma-separated string or a list of relation names
     *
     * @return string[] the accepted relation names, named as the model's methods
     */
    public static function counts(Model $model, mixed $relations): array
    {
        $names = [];
        foreach (static::split($relations) as $name) {
            $method = str_contains($name, '.') ? null : static::relationMethod($model, $name);
            if ($method === null) {
                continue;
            }

            $relation = static::relation($model, $method);
            if ($relation && !$relation instanceof MorphTo) {
                $names[] = $method;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Resolve a requested segment to the name of a relation method on the model.
     *
     * The segment is tried camel-cased, as given, then snake-cased. A method is returned as declared, so
     * `subfleets` resolves to `subFleets` and the relation is loaded under the key resources read.
     */
    public static function relationMethod(Model $model, string $segment): ?string
    {
        $segment = trim($segment);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
            return null;
        }

        $withable = array_map('strtolower', static::withable($model));
        foreach (array_unique([Str::camel($segment), $segment, Str::snake($segment)]) as $name) {
            $callable = static::callable($model, $name);
            if (!$callable) {
                continue;
            }

            $name = $callable instanceof \ReflectionMethod ? $callable->getName() : $name;
            if (in_array(strtolower($name), $withable, true) || static::declaresRelation($callable->getReturnType())) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Flatten every accepted input shape to a list of trimmed, non-empty paths.
     *
     * @return string[]
     */
    protected static function split(mixed $relations): array
    {
        if (is_string($relations)) {
            $relations = explode(',', $relations);
        }
        if (!is_array($relations)) {
            return [];
        }

        $paths = array_map('trim', array_filter($relations, 'is_string'));

        return array_slice(array_values(array_unique(array_filter($paths, fn (string $path) => $path !== ''))), 0, static::MAX_PATHS);
    }

    /**
     * Get the method, or the Expandable closure, a relation name calls, when it can be called with no arguments.
     */
    protected static function callable(Model $model, string $name): ?\ReflectionFunctionAbstract
    {
        if (method_exists($model, $name)) {
            $method = new \ReflectionMethod($model, $name);

            return $method->isPublic() && !$method->isStatic() && $method->getNumberOfRequiredParameters() === 0 ? $method : null;
        }

        if (method_exists($model, 'isExpansion') && $model::isExpansion($name)) {
            $closure = $model::getExpansionClosure($name);
            if (!$closure instanceof \Closure) {
                return null;
            }

            $closure = new \ReflectionFunction($closure);

            return $closure->getNumberOfRequiredParameters() === 0 ? $closure : null;
        }

        return null;
    }

    /**
     * Whether a declared return type names an Eloquent relation (`BelongsTo`, `?HasMany`, `BelongsTo|Builder`).
     */
    protected static function declaresRelation(?\ReflectionType $type): bool
    {
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $member) {
            if ($member instanceof \ReflectionNamedType && !$member->isBuiltin() && is_a($member->getName(), Relation::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the relation names a model allows beyond those with a declared Relation return type.
     *
     * @return string[]
     */
    protected static function withable(Model $model): array
    {
        $withable = (fn () => property_exists($this, 'withable') ? $this->withable : [])->call($model);

        return is_array($withable) ? array_values(array_filter($withable, 'is_string')) : [];
    }

    /**
     * Build the relation the same way Eloquent does for an eager load, or null when it is not one.
     */
    protected static function relation(Model $model, string $method): ?Relation
    {
        try {
            $relation = Relation::noConstraints(fn () => $model->newInstance()->{$method}());
        } catch (\Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation : null;
    }
}
