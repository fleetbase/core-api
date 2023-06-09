<?php

namespace Fleetbase\Providers;

use Fleetbase\Models\Setting;
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
        \Fleetbase\Console\Commands\SeedDatabase::class,
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
        $this->mergeConfigFrom(__DIR__ . '/../../config/fleetbase.php', 'fleetbase');
        $this->mergeConfigFrom(__DIR__ . '/../../config/auth.php', 'auth');
        $this->mergeConfigFrom(__DIR__ . '/../../config/sanctum.php', 'sanctum');
        $this->mergeConfigFrom(__DIR__ . '/../../config/twilio.php', 'twilio');
        $this->mergeConfigFrom(__DIR__ . '/../../config/webhook-server.php', 'webhook-server');
        $this->mergeConfigFrom(__DIR__ . '/../../config/permission.php', 'permission');
        $this->mergeConfigFrom(__DIR__ . '/../../config/activitylog.php', 'activitylog');
        $this->mergeConfigFrom(__DIR__ . '/../../config/excel.php', 'excel');
        $this->mergeConfigFromSettings();
        $this->addServerIpAsAllowedOrigin();
    }

    /**
     * Merge configuration values from application settings.
     *
     * This function iterates through a predefined list of settings keys,
     * retrieves their values from the system settings, and updates the
     * Laravel configuration values accordingly. For some settings, it
     * also updates corresponding environment variables.
     *
     * The settings keys and the corresponding config keys are defined
     * in the $settings array. The $putsenv array defines the settings
     * keys that also need to update environment variables and maps each
     * settings key to the environment variables that need to be updated.
     *
     * @return void
     */
    public function mergeConfigFromSettings()
    {
        $putsenv = [
            'services.aws' => ['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY', 'region' => 'AWS_DEFAULT_REGION'],
            'services.google_maps' => ['api_key' => 'GOOGLE_MAPS_API_KEY', 'locale' => 'GOOGLE_MAPS_LOCALE'],
            'services.twilio' => ['sid' => 'TWILIO_SID', 'token' => 'TWILIO_TOKEN', 'from' => 'TWILIO_FROM']
        ];

        $settings = [
            ['settingsKey' => 'filesystem.driver', 'configKey' => 'filesystems.default'],
            ['settingsKey' => 'filesystem.s3', 'configKey' => 'filesystems.disks.s3'],
            ['settingsKey' => 'mail.mailer', 'configKey' => 'mail.default'],
            ['settingsKey' => 'mail.from', 'configKey' => 'mail.from'],
            ['settingsKey' => 'mail.smtp', 'configKey' => 'mail.mailers.smtp'],
            ['settingsKey' => 'queue.driver', 'configKey' => 'queue.default'],
            ['settingsKey' => 'queue.sqs', 'configKey' => 'queue.connections.sqs'],
            ['settingsKey' => 'queue.beanstalkd', 'configKey' => 'queue.connections.beanstalkd'],
            ['settingsKey' => 'services.aws', 'configKey' => 'services.aws'],
            ['settingsKey' => 'services.aws.key', 'configKey' => 'queue.connections.sqs.key'],
            ['settingsKey' => 'services.aws.secret', 'configKey' => 'queue.connections.sqs.secret'],
            ['settingsKey' => 'services.aws.region', 'configKey' => 'queue.connections.sqs.region'],
            ['settingsKey' => 'services.aws.key', 'configKey' => 'cache.stores.dynamodb.key'],
            ['settingsKey' => 'services.aws.secret', 'configKey' => 'cache.stores.dynamodb.secret'],
            ['settingsKey' => 'services.aws.region', 'configKey' => 'cache.stores.dynamodb.region'],
            ['settingsKey' => 'services.aws.key', 'configKey' => 'filesystems.disks.s3.key'],
            ['settingsKey' => 'services.aws.secret', 'configKey' => 'filesystems.disks.s3.secret'],
            ['settingsKey' => 'services.aws.region', 'configKey' => 'filesystems.disks.s3.region'],
            ['settingsKey' => 'services.aws.key', 'configKey' => 'mail.mailers.ses.key'],
            ['settingsKey' => 'services.aws.secret', 'configKey' => 'mail.mailers.ses.secret'],
            ['settingsKey' => 'services.aws.region', 'configKey' => 'mail.mailers.ses.region'],
            ['settingsKey' => 'services.aws.key', 'configKey' => 'services.ses.key'],
            ['settingsKey' => 'services.aws.secret', 'configKey' => 'services.ses.secret'],
            ['settingsKey' => 'services.aws.region', 'configKey' => 'services.ses.region'],
            ['settingsKey' => 'services.google_maps', 'configKey' => 'services.google_maps'],
            ['settingsKey' => 'services.twilio', 'configKey' => 'services.twilio'],
            ['settingsKey' => 'services.twilio', 'configKey' => 'twilio.connections.twilio'],
            ['settingsKey' => 'services.ipinfo', 'configKey' => 'services.ipinfo'],
            ['settingsKey' => 'services.ipinfo', 'configKey' => 'fleetbase.services.ipinfo'],
        ];

        foreach ($settings as $setting) {
            $settingsKey = $setting['settingsKey'];
            $configKey = $setting['configKey'];
            $value = Setting::system($settingsKey);

            if ($value) {
                // some settings should set env variables to be accessed throughout entire application
                if (in_array($settingsKey, array_keys($putsenv))) {
                    $environmentVariables = $putsenv[$settingsKey];

                    foreach ($environmentVariables as $configEnvKey => $envKey) {
                        putenv($envKey . '="' . data_get($value, $configEnvKey) . '"');
                    }
                }

                // Fetch the current config array
                $config = config()->all();

                // Update the specific value in the config array
                Arr::set($config, $configKey, $value);

                // Set the entire config array
                config($config);
            }
        }
    }

    /**
     * Add the server's IP address to the CORS allowed origins.
     *
     * This function retrieves the server's IP address and adds it to the
     * list of CORS allowed origins in the Laravel configuration. If the
     * server's IP address is already in the list, the function doesn't
     * add it again.
     *
     * @return void
     */
    public function addServerIpAsAllowedOrigin()
    {
        $serverIp = gethostbyname(gethostname());
        $allowedOrigins = config('cors.allowed_origins', []);
        $serverIpOrigin = "http://{$serverIp}:4200";

        if (!in_array($serverIpOrigin, $allowedOrigins, true)) {
            $allowedOrigins[] = $serverIpOrigin;
        }

        config(['cors.allowed_origins' => $allowedOrigins]);
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
