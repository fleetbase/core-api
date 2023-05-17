<?php

namespace Fleetbase\Providers;

use Fleetbase\Support\Expansion;
use Fleetbase\Support\Utils;
use Laravel\Cashier\Cashier;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CoreServiceProvider
 */
class CoreServiceProvider extends ServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [
        \Fleetbase\Models\User::class => \Fleetbase\Observers\UserObserver::class,
        \Fleetbase\Models\ApiCredential::class => \Fleetbase\Observers\ApiCredentialObserver::class,
        \Spatie\Activitylog\Models\Activity::class => \Fleetbase\Observers\ActivityObserver::class,
    ];

    /**
     * The middleware groups registered with the service provider.
     *
     * @var array
     */
    public $middleware = [
        'fleetbase.protected' => [
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'auth:sanctum',
            \Fleetbase\Http\Middleware\SetupFleetbaseSession::class
        ]
    ];

    /**
     * The console commands registered with the service provider.
     *
     * @var array
     */
    public $commands = [
        \Fleetbase\Console\Commands\CreateDatabase::class,
        \Fleetbase\Console\Commands\MigrateSandbox::class,
        \Fleetbase\Console\Commands\InitializeSandboxKeyColumn::class,
        \Fleetbase\Console\Commands\SyncSandbox::class,
        \Fleetbase\Console\Commands\BackupDatabase\MysqlS3Backup::class
    ];

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        JsonResource::withoutWrapping();
        Cashier::ignoreMigrations();

        $this->registerCommands();
        $this->registerObservers();
        $this->registerExpansionsFrom();
        $this->registerMiddleware();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->mergeConfigFrom(__DIR__ . '/../../config/database.connections.php', 'database.connections');
        $this->mergeConfigFrom(__DIR__ . '/../../config/database.redis.php', 'database.redis');
        $this->mergeConfigFrom(__DIR__ . '/../../config/broadcasting.connections.php', 'broadcasting.connections');
        $this->mergeConfigFrom(__DIR__ . '/../../config/queue.connections.php', 'queue.connections');
        $this->mergeConfigFrom(__DIR__ . '/../../config/fleetbase.php', 'fleetbase');
        $this->mergeConfigFrom(__DIR__ . '/../../config/auth.php', 'auth');
        $this->mergeConfigFrom(__DIR__ . '/../../config/sanctum.php', 'sanctum');
        $this->mergeConfigFrom(__DIR__ . '/../../config/twilio.php', 'twilio');
        $this->mergeConfigFrom(__DIR__ . '/../../config/webhook-server.php', 'webhook-server');
        $this->mergeConfigFrom(__DIR__ . '/../../config/permission.php', 'permission');
        $this->mergeConfigFrom(__DIR__ . '/../../config/activitylog.php', 'activitylog');
        $this->mergeConfigFrom(__DIR__ . '/../../config/excel.php', 'excel');
    }

    /**
     * Registers all class extension macros from the specified path and namespace.
     *
     * @param string|null $from The path to load the macros from. If null, the default path is used.
     * @param string|null $namespace The namespace to load the macros from. If null, the default namespaces are used.
     *
     * @return void
     */
    public function registerExpansionsFrom($from = null, $namespace = null): void
    {
        if (is_array($from)) {
            foreach ($from as $frm) {
                $this->registerExpansionsFrom($frm);
            }

            return;
        }

        try {
            $macros = new \DirectoryIterator($from ?? __DIR__ . '/../Expansions');
        } catch (\UnexpectedValueException $e) {
            // no expansions
            return;
        }

        $packageNamespace = $this->findPackageNamespace($from);

        foreach ($macros as $macro) {
            if (!$macro->isFile()) {
                continue;
            }

            $className = $macro->getBasename('.php');

            if ($namespace === null) {
                // resolve namespace
                $namespaces = ['Fleetbase\\Expansions\\', 'Fleetbase\\Macros\\', 'Fleetbase\\Mixins\\'];

                if ($packageNamespace) {
                    $namespaces[] = $packageNamespace . '\\Expansions\\';
                    $namespaces[] = $packageNamespace . '\\Macros\\';
                    $namespaces[] = $packageNamespace . '\\Mixins\\';
                }

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

    /**
     * Register the middleware groups defined by the service provider.
     *
     * @return void
     */
    public function registerMiddleware(): void
    {
        foreach ($this->middleware as $group => $middlewares) {
            foreach ($middlewares as $middleware) {
                $this->app->router->pushMiddlewareToGroup($group, $middleware);
            }
        }
    }

    /**
     * Register the model observers defined by the service provider.
     *
     * @return void
     */
    public function registerObservers(): void
    {
        foreach ($this->observers as $model => $observer) {
            $model::observe($observer);
        }
    }

    /**
     * Load configuration files from the specified directory.
     *
     * @param  string  $path
     * @return void
     */
    protected function loadConfigFromDirectory($path)
    {
        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $this->mergeConfigFrom(
                $file,
                pathinfo($file, PATHINFO_FILENAME)
            );
        }
    }

    /**
     * Register the console commands defined by the service provider.
     *
     * @return void
     */
    public function registerCommands(): void
    {
        $this->commands($this->commands ?? []);
    }

    /**
     * Find the package namespace for a given path.
     *
     * @param string|null $path The path to search for the package namespace. If null, no namespace is returned.
     * @return string|null The package namespace, or null if the path is not valid.
     */
    private function findPackageNamespace($path = null): ?string
    {
        return Utils::findPackageNamespace($path);
    }
}
