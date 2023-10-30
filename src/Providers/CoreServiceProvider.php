<?php

namespace Fleetbase\Providers;

use Fleetbase\Models\Setting;
use Fleetbase\Support\Expansion;
use Fleetbase\Support\NotificationRegistry;
use Fleetbase\Support\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
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
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        JsonResource::withoutWrapping();

        $this->registerCommands();
        $this->registerObservers();
        $this->registerExpansionsFrom();
        $this->registerMiddleware();
        $this->registerNotifications();
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
        $this->mergeConfigFrom(__DIR__ . '/../../config/sentry.php', 'sentry');
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
                        $hasValue         = !empty($envValue);

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
        $cacheKey               = 'server_public_ip';
        $cacheExpirationMinutes = 60 * 60 * 24 * 30;

        // Check the cache first
        $serverIp = Cache::get($cacheKey);

        // If not cached, fetch the IP and store it in the cache
        if (!$serverIp) {
            $serverIp = trim(shell_exec('dig +short myip.opendns.com @resolver1.opendns.com'));

            if (!$serverIp) {
                return;
            }

            Cache::put($cacheKey, $serverIp, $cacheExpirationMinutes);
        }

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
                        return class_exists($ns . $className);
                    }
                );

                if (!$namespace) {
                    continue;
                }
            }

            $class  = $namespace . $className;
            $target = $class::target();

            if (!class_exists($target)) {
                continue;
            }

            $method = $class::$method ?? Expansion::isExpandable($target) ? 'expand' : 'mixin';
            $target::$method(new $class());
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
    public function scheduleCommands(callable $callback = null): void
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
}
