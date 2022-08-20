<?php

namespace Fleetbase\Providers;

use Fleetbase\Support\Blade;
use Fleetbase\Support\Expansion;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Http\Resources\Json\JsonResource;
use DirectoryIterator;

/**
 * CoreServiceProvider
 */
class CoreServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        JsonResource::withoutWrapping();

        $this->registerExpansionsFrom();
        $this->registerBladeDirectives();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->mergeConfigFrom(__DIR__ . '/../../config/fleetbase.php', 'fleetbase');
    }

    /**
     * Registers all class extension macros.
     *
     * @return void
     */
    public function registerExpansionsFrom($from = null, $namespace = null)
    {
        if (is_array($from)) {
            foreach ($from as $frm) {
                $this->registerExpansionsFrom($frm);
            }

            return;
        }

        $macros = new DirectoryIterator($from ?? __DIR__ . '/../Expansions');

        foreach ($macros as $macro) {
            if (!$macro->isFile()) {
                continue;
            }

            $className = $macro->getBasename('.php');

            if ($namespace === null) {
                // resolve namespace
                $namespaces = ['Fleetbase\\Expansions\\', 'Fleetbase\\Macros\\', 'Fleetbase\\Mixins\\'];
                $namespace = Arr::first(
                    $namespaces,
                    function ($ns) use ($className) {
                        return class_exists($ns . $className);
                    }
                );

                if (!$namespace) {
                    continue;
                }
            }

            $class = $namespace . $className;
            $target = $class::target();

            if (!class_exists($target)) {
                continue;
            }

            if (Expansion::isExpandable($target)) {
                $target::expand(new $class);
            } else {
                $target::mixin(new $class);
            }
        }
    }

    /**
     * Registers all blade directives.
     *
     * @return void
     */
    public function registerBladeDirectives()
    {
        Blade::registerBladeDirectives();
    }
}
