<?php

namespace Fleetbase\Providers;

use Fleetbase\Models\Setting;
use Fleetbase\Support\NotificationRegistry;
use Fleetbase\Support\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * CoreServiceProvider.
 */
class CoreServiceProvider extends ServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [
        \Fleetbase\Models\User::class              => \Fleetbase\Observers\UserObserver::class,
        \Fleetbase\Models\ApiCredential::class     => \Fleetbase\Observers\ApiCredentialObserver::class,
        \Fleetbase\Models\Notification::class      => \Fleetbase\Observers\NotificationObserver::class,
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
            \Fleetbase\Http\Middleware\SetupFleetbaseSession::class,
        ],
        'fleetbase.api' => [
            'throttle:60,1',
            \Illuminate\Session\Middleware\StartSession::class,
            \Fleetbase\Http\Middleware\AuthenticateOnceWithBasicAuth::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Fleetbase\Http\Middleware\LogApiRequests::class,
        ],
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
        \Fleetbase\Console\Commands\BackupDatabase\MysqlS3Backup::class,
    ];

    /**
     * Register any application services.
     *
     * Within the register method, you should only bind things into the
     * service container. You should never attempt to register any event
     * listeners, routes, or any other piece of functionality within the
     * register method.
     *
     * More information on this can be found in the Laravel documentation:
     * https://laravel.com/docs/8.x/providers
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/database.connections.php', 'database.connections');
        $this->mergeConfigFrom(__DIR__ . '/../../config/database.redis.php', 'database.redis');
        $this->mergeConfigFrom(__DIR__ . '/../../config/broadcasting.connections.php', 'broadcasting.connections');
        $this->mergeConfigFrom(__DIR__ . '/../../config/fleetbase.php', 'fleetbase');
        $this->mergeConfigFrom(__DIR__ . '/../../config/auth.php', 'auth');
        $this->mergeConfigFrom(__DIR__ . '/../../config/sanctum.php', 'sanctum');
        $this->mergeConfigFrom(__DIR__ . '/../../config/twilio.php', 'twilio');
        $this->mergeConfigFrom(__DIR__ . '/../../config/twilio-notification-channel.php', 'twilio-notification-channel');
        $this->mergeConfigFrom(__DIR__ . '/../../config/firebase.php', 'firebase');
        $this->mergeConfigFrom(__DIR__ . '/../../config/webhook-server.php', 'webhook-server');
        $this->mergeConfigFrom(__DIR__ . '/../../config/permission.php', 'permission');
        $this->mergeConfigFrom(__DIR__ . '/../../config/activitylog.php', 'activitylog');
        $this->mergeConfigFrom(__DIR__ . '/../../config/excel.php', 'excel');
        $this->mergeConfigFrom(__DIR__ . '/../../config/sentry.php', 'sentry');
        $this->mergeConfigFrom(__DIR__ . '/../../config/laravel-mysql-s3-backup.php', 'laravel-mysql-s3-backup');
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        JsonResource::withoutWrapping();

        $this->__hotfixCommonmarkDeprecation();
        $this->registerCommands();
        $this->scheduleCommands(function ($schedule) {
            $schedule->command('cache:prune-stale-tags')->hourly();
        });
        $this->registerObservers();
        $this->registerExpansionsFrom();
        $this->registerMiddleware();
        $this->registerNotifications();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->loadViewsFrom(__DIR__ . '/../../views', 'fleetbase');
        $this->registerCustomBladeComponents();
        $this->mergeConfigFromSettings();
    }

    /**
     * Registers Fleetbase provided blade components.
     *
     * @return void
     */
    public function registerCustomBladeComponents()
    {
        Blade::component('fleetbase::layout.mail', 'mail-layout');
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
        try {
            // Try to make a simple DB call
            DB::connection()->getPdo();

            // Check if the settings table exists
            if (!Schema::hasTable('settings')) {
                return;
            }
        } catch (\Exception $e) {
            // Connection failed, or other error occurred
            return;
        }

        $putsenv = [
            'services.aws'         => ['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY', 'region' => 'AWS_DEFAULT_REGION'],
            'services.google_maps' => ['api_key' => 'GOOGLE_MAPS_API_KEY', 'locale' => 'GOOGLE_MAPS_LOCALE'],
            'services.twilio'      => ['sid' => 'TWILIO_SID', 'token' => 'TWILIO_TOKEN', 'from' => 'TWILIO_FROM'],
            'services.sentry'      => ['dsn' => 'SENTRY_DSN'],
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
            ['settingsKey' => 'services.twilio', 'configKey' => 'twilio.twilio.connections.twilio'],
            ['settingsKey' => 'services.ipinfo', 'configKey' => 'services.ipinfo'],
            ['settingsKey' => 'services.ipinfo', 'configKey' => 'fleetbase.services.ipinfo'],
            ['settingsKey' => 'services.sentry.dsn', 'configKey' => 'sentry.dsn'],
            ['settingsKey' => 'broadcasting.apn', 'configKey' => 'broadcasting.connections.apn'],
            ['settingsKey' => 'firebase.app', 'configKey' => 'firebase.projects.app'],
        ];

        $priorityEnvs = [
            'AWS_ACCESS_KEY_ID'     => ['services.aws.key'],
            'AWS_SECRET_ACCESS_KEY' => ['services.aws.secret', 'filesystems.disks.s3.secret', 'cache.stores.dynamodb.secret', 'queue.connections.sqs.secret', 'mail.mailers.ses.secret'],
            'AWS_DEFAULT_REGION'    => ['services.aws.region', 'filesystems.disks.s3.region', 'cache.stores.dynamodb.region', 'queue.connections.sqs.region', 'mail.mailers.ses.region'],
            'AWS_BUCKET'            => ['filesystems.disks.s3'],
            'FILESYSTEM_DRIVER'     => ['filesystems.default'],
            'MAIL_MAILER'           => ['mail.default'],
            'QUEUE_CONNECTION'      => ['queue.default'],
            'SQS_PREFIX'            => ['queue.connections.sqs'],
            'MAIL_FROM_ADDRESS'     => ['mail.from'],
            'MAIL_HOST'             => ['mail.mailers.smtp'],
        ];

        foreach ($settings as $setting) {
            $settingsKey = $setting['settingsKey'];
            $configKey   = $setting['configKey'];

            // Check if the setting should be skipped based on priorityEnvs
            $shouldSkip = false;
            foreach ($priorityEnvs as $envKey => $settingKeys) {
                if (env($envKey) && in_array($configKey, $settingKeys)) {
                    $shouldSkip = true;
                    break;
                }
            }

            if ($shouldSkip) {
                continue;
            }

            $value = Setting::system($settingsKey);
            if ($value) {
                // some settings should set env variables to be accessed throughout entire application
                if (in_array($settingsKey, array_keys($putsenv))) {
                    $environmentVariables = $putsenv[$settingsKey];

                    foreach ($environmentVariables as $configEnvKey => $envKey) {
                        // hack fix for aws set envs
                        $hasDefaultRegion = !empty(env('AWS_DEFAULT_REGION'));
                        if ($hasDefaultRegion && \Illuminate\Support\Str::startsWith($envKey, 'AWS_')) {
                            continue;
                        }

                        $envValue         = data_get($value, $configEnvKey);
                        $doesntHaveEnvSet = empty(env($envKey));
                        $hasValue         = !empty($envValue) && !is_array($envValue);

                        // only set if env variable is not set already
                        if ($doesntHaveEnvSet && $hasValue) {
                            putenv($envKey . '="' . data_get($value, $configEnvKey) . '"');
                        }
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

        // we need a mail from set
        if (empty(config('mail.from.address'))) {
            config()->set('mail.from.address', Utils::getDefaultMailFromAddress());
        }
    }

    /**
     * Registers all class extension macros from the specified path and namespace.
     *
     * @param string|null $from      The path to load the macros from. If null, the default path is used.
     * @param string|null $namespace The namespace to load the macros from. If null, the default namespaces are used.
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
                        return Utils::classExists($ns . $className);
                    }
                );

                if (!$namespace) {
                    continue;
                }
            }

            $class  = $namespace . $className;
            $target = $class::target();

            if (!Utils::classExists($target)) {
                continue;
            }

            try {
                $target::expand(new $class());
            } catch (\Throwable $e) {
                try {
                    $target::mixin(new $class());
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private function registerNotifications()
    {
        NotificationRegistry::register([
            \Fleetbase\Notifications\UserCreated::class,
            \Fleetbase\Notifications\UserAcceptedCompanyInvite::class,
        ]);
    }

    /**
     * Register the middleware groups defined by the service provider.
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
     * @param string $path
     *
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
     */
    public function registerCommands(): void
    {
        $this->commands($this->commands ?? []);
    }

    /**
     * Schedule commands within the service provider.
     *
     * This method allows child service providers to easily schedule their commands
     * by providing a callback that receives the Laravel scheduler instance.
     *
     * @param callable|null $callback A callback function that receives the Laravel scheduler instance.
     *                                The callback is used to define the scheduling of commands.
     *                                If no callback is provided, no scheduling will occur.
     *
     * @example
     * $this->scheduleCommands(function ($schedule) {
     *     $schedule->command('your-package:command')->daily();
     * });
     */
    public function scheduleCommands(?callable $callback = null): void
    {
        $this->app->booted(function () use ($callback) {
            $schedule = $this->app->make(Schedule::class);

            if (is_callable($callback)) {
                $callback($schedule);
            }
        });
    }

    /**
     * Find the package namespace for a given path.
     *
     * @param string|null $path The path to search for the package namespace. If null, no namespace is returned.
     *
     * @return string|null the package namespace, or null if the path is not valid
     */
    private function findPackageNamespace($path = null): ?string
    {
        return Utils::findPackageNamespace($path);
    }

    /**
     * Apply a hotfix for a deprecation issue in the league/commonmark package.
     *
     * The league/commonmark package triggers deprecation notices using the `trigger_deprecation` function,
     * which interferes with the normal application flow. This hotfix introduces a custom implementation
     * of `trigger_deprecation` that specifically skips triggering deprecations for the league/commonmark package.
     * This allows the application to continue running without being affected by the league/commonmark deprecations.
     */
    private function __hotfixCommonmarkDeprecation(): void
    {
        if (!function_exists('trigger_deprecation')) {
            /**
             * Custom implementation of trigger_deprecation.
             *
             * @param string $package The name of the Composer package
             * @param string $version The version of the package
             * @param string $message The message of the deprecation
             * @param mixed  ...$args Values to insert in the message using printf() formatting
             */
            function trigger_deprecation(string $package, string $version, string $message, mixed ...$args): void
            {
                // Check if the package is "league/commonmark" and skip triggering the deprecation
                if ($package === 'league/commonmark') {
                    return;
                }

                // Otherwise, trigger the deprecation as usual
                @trigger_error(($package || $version ? "Since $package $version: " : '') . ($args ? vsprintf($message, $args) : $message), \E_USER_DEPRECATED);
            }
        }
    }
}
