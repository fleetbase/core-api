<?php

namespace Fleetbase\Providers;

use Fleetbase\Support\Blade;
use Illuminate\Support\ServiceProvider;
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

        $this->registerMacros();
        $this->registerBladeDirectives();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->mergeConfigFrom(__DIR__ . '/../../config/fleetbase.php', 'fleetbase');
    }

    /**
     * Registers all class extension macros.
     *
     * @return void
     */
    public function registerMacros()
    {
        $macros = new DirectoryIterator(__DIR__ . '/../Macros');

        foreach ($macros as $macro) {
            $class = 'Fleetbase\\Macros\\' . $macro->getBasename('.php');

            if (class_exists($class) && method_exists($class, 'getFacade')) {
                $target = $class::getFacade();

                if (class_exists($target)) {
                    $target::mixin(new $class);
                }
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
