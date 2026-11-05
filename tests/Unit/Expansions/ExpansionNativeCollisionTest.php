<?php

use Fleetbase\Build\Expansion;

/*
 * A native method always wins over a macro of the same name, and nothing reports it. A framework
 * upgrade that adds a method can silently turn off an expansion, which is how Laravel 11's
 * Request::array() broke `?sort=a,b`. Rename the expansion rather than adding it to this list.
 */
test('no expansion is shadowed by a native method on its target', function () {
    $roots = [
        Illuminate\Support\Facades\Request::class  => Illuminate\Http\Request::class,
        Illuminate\Support\Facades\Response::class => Illuminate\Routing\ResponseFactory::class,
        Illuminate\Support\Facades\Route::class    => Illuminate\Routing\Router::class,
        Illuminate\Support\Facades\Blade::class    => Illuminate\View\Compilers\BladeCompiler::class,
    ];

    // Already shadowed, with no callers relying on the expansion's own behaviour.
    $known = [
        'Illuminate\Http\Request::integer',
        'Illuminate\Support\Arr::every',
        'Illuminate\Support\Arr::map',
    ];

    $checked  = 0;
    $shadowed = [];

    foreach (glob(dirname(__DIR__, 3) . '/src/Expansions/*.php') as $file) {
        $class = 'Fleetbase\\Expansions\\' . basename($file, '.php');

        if (!is_subclass_of($class, Expansion::class)) {
            continue;
        }

        $target = $roots[$class::target()] ?? $class::target();

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->name === 'target' || $method->isStatic()) {
                continue;
            }

            $checked++;

            if (method_exists($target, $method->name) && !in_array($target . '::' . $method->name, $known, true)) {
                $shadowed[] = $target . '::' . $method->name;
            }
        }
    }

    expect($checked)->toBeGreaterThan(20)
        ->and($shadowed)->toBe([]);
});
