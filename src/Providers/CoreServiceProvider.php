<?php

namespace Fleetbase\Providers;

use Fleetbase\Support\Expansion;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CoreServiceProvider
 */
class CoreServiceProvider extends ServiceProvider
{
    public $middleware = [
        'fleetbase.protected' => [
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            \Fleetbase\Http\Middleware\SetupFleetbaseSession::class
        ]
    ];

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        JsonResource::withoutWrapping();

        $this->registerCommands();
        $this->registerExpansionsFrom();
        $this->registerMiddleware();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->mergeConfigFrom(__DIR__ . '/../../config/queue.php', 'queue.connections');
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

        $macros = new \DirectoryIterator($from ?? __DIR__ . '/../Expansions');

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

            $method = $class::$method ?? Expansion::isExpandable($target) ? 'expand' : 'mixin';
            $target::$method(new $class);
        }
    }

    public function registerMiddleware()
    {
        foreach ($this->middleware as $group => $middlewares) {
            foreach ($middlewares as $middleware) {
                $this->app->router->pushMiddlewareToGroup($group, $middleware);
            }
        }
    }

    public function registerCommands()
    {
        $this->commands(
            [
                \Fleetbase\Console\Commands\CreateDatabase::class,
                \Fleetbase\Console\Commands\BackupDatabase\MysqlS3Backup::class
            ]
        );
    }
}
