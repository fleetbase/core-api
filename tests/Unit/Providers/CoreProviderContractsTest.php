<?php

namespace PhpOption {
    if (!class_exists(Option::class)) {
        class Option
        {
            public function __construct(private mixed $value)
            {
            }

            public static function fromValue(mixed $value): self
            {
                return new self($value);
            }

            public function map(callable $callback): self
            {
                if ($this->value === null) {
                    return $this;
                }

                return new self($callback($this->value));
            }

            public function getOrCall(callable $callback): mixed
            {
                return $this->value ?? $callback();
            }
        }
    }
}

namespace Dotenv\Repository {
    if (!class_exists(RepositoryBuilder::class)) {
        class RepositoryBuilder
        {
            public static function createWithDefaultAdapters(): self
            {
                return new self();
            }

            public function addAdapter(string $adapter): self
            {
                return $this;
            }

            public function immutable(): self
            {
                return $this;
            }

            public function make(): object
            {
                return new class {
                    public function get(string $key): mixed
                    {
                        $value = getenv($key);

                        return $value === false ? null : $value;
                    }
                };
            }
        }
    }
}

namespace Dotenv\Repository\Adapter {
    if (!class_exists(PutenvAdapter::class)) {
        class PutenvAdapter
        {
        }
    }
}

namespace Illuminate\Foundation\Support\Providers {
    if (!class_exists(EventServiceProvider::class)) {
        class EventServiceProvider extends \Illuminate\Support\ServiceProvider
        {
        }
    }
}

namespace {
    use Fleetbase\Events\AccountCreated;
    use Fleetbase\Events\ResourceLifecycleEvent;
    use Fleetbase\Http\Middleware\AuthenticatePlatformApiToken;
    use Fleetbase\Http\Middleware\AuthorizationGuard;
    use Fleetbase\Http\Middleware\LogApiRequests;
    use Fleetbase\Http\Middleware\RequestTimer;
    use Fleetbase\Http\Middleware\SetupFleetbaseSession;
    use Fleetbase\Listeners\LogFailedWebhook;
    use Fleetbase\Listeners\LogFinalWebhookAttempt;
    use Fleetbase\Listeners\LogSuccessfulWebhook;
    use Fleetbase\Listeners\SendResourceLifecycleWebhook;
    use Fleetbase\Listeners\TriggerPublicNotificationBroadcast;
    use Fleetbase\Models\ApiCredential;
    use Fleetbase\Models\ChatParticipant;
    use Fleetbase\Models\Company;
    use Fleetbase\Models\Notification;
    use Fleetbase\Models\User;
    use Fleetbase\Observers\ApiCredentialObserver;
    use Fleetbase\Observers\ChatParticipantObserver;
    use Fleetbase\Observers\CompanyObserver;
    use Fleetbase\Observers\NotificationObserver;
    use Fleetbase\Observers\UserObserver;
    use Fleetbase\Providers\CoreServiceProvider;
    use Fleetbase\Providers\EventServiceProvider;
    use Fleetbase\Providers\SocketClusterServiceProvider;
    use Fleetbase\Services\FileResolverService;
    use Fleetbase\Services\TemplateRenderService;
    use Fleetbase\Support\NotificationRegistry;
    use Fleetbase\Support\Reporting\ReportSchemaRegistry;
    use Fleetbase\Support\SocketCluster\SocketClusterBroadcaster;
    use Fleetbase\Webhook\Events\FinalWebhookCallFailedEvent;
    use Fleetbase\Webhook\Events\WebhookCallFailedEvent;
    use Fleetbase\Webhook\Events\WebhookCallSucceededEvent;
    use Fleetbase\Webhook\WebhookServerServiceProvider;
    use Illuminate\Contracts\Http\Kernel;
    use Illuminate\Notifications\Events\BroadcastNotificationCreated;
    use Illuminate\Support\Facades\Blade;
    use Illuminate\Support\Facades\Broadcast;
    use Illuminate\Support\Facades\Facade;
    use Spatie\LaravelPackageTools\Package;
    use Symfony\Component\HttpFoundation\Response;

    if (!function_exists('base_path')) {
        function base_path(string $path = ''): string
        {
            $base = dirname(__DIR__, 3);

            return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
        }
    }

    class CoreProviderContractsKernelFake implements Kernel
    {
        public array $middlewares = [];

        public function bootstrap()
        {
        }

        public function handle($request)
        {
            return new Response();
        }

        public function terminate($request, $response)
        {
        }

        public function getApplication()
        {
            return app();
        }

        public function pushMiddleware($middleware): void
        {
            $this->middlewares[] = $middleware;
        }
    }

    class CoreProviderContractsRouterFake
    {
        public array $groups = [];

        public function pushMiddlewareToGroup(string $group, string $middleware): void
        {
            $this->groups[$group][] = $middleware;
        }
    }

    class CoreProviderContractsBroadcastFake
    {
        public array $extensions = [];

        public function extend(string $driver, callable $callback): void
        {
            $this->extensions[$driver] = $callback;
        }
    }

    class CoreProviderContractsBladeFake
    {
        public array $components = [];

        public function component(string $view, string $alias): void
        {
            $this->components[$alias] = $view;
        }
    }

    class CoreProviderContractsCommandProbe extends CoreServiceProvider
    {
        public array $registeredCommands = [];

        public function commands($commands)
        {
            $this->registeredCommands = is_array($commands) ? $commands : func_get_args();
        }
    }

    class CoreProviderContractsExpansionTarget
    {
        public static array $expanded = [];
        public static array $mixed    = [];

        public static function expand(object $macro): void
        {
            static::$expanded[] = $macro::class;
        }

        public static function mixin(object $macro): void
        {
            static::$mixed[] = $macro::class;
        }
    }

    class CoreProviderContractsMixinTarget
    {
        public static array $expanded = [];
        public static array $mixed    = [];

        public static function expand(object $macro): void
        {
            static::$expanded[] = $macro::class;

            throw new RuntimeException('expansion failed');
        }

        public static function mixin(object $macro): void
        {
            static::$mixed[] = $macro::class;
        }
    }

    class CoreProviderContractsExpansionMacro
    {
        public static function target(): string
        {
            return CoreProviderContractsExpansionTarget::class;
        }
    }

    class CoreProviderContractsPackageExpansionMacro
    {
        public static function target(): string
        {
            return CoreProviderContractsExpansionTarget::class;
        }
    }

    class CoreProviderContractsMixinMacro
    {
        public static function target(): string
        {
            return CoreProviderContractsMixinTarget::class;
        }
    }

    class CoreProviderContractsMissingExpansion
    {
        public static function target(): string
        {
            return 'CoreProviderContractsMissingTarget';
        }
    }

    function core_provider(): CoreServiceProvider
    {
        $container = bind_test_container(['app.env' => 'testing']);

        return new CoreServiceProvider($container);
    }

    test('core service provider exposes critical observer middleware and command contracts', function () {
        $provider = core_provider();

        expect($provider->observers)->toMatchArray([
            Company::class         => CompanyObserver::class,
            User::class            => UserObserver::class,
            ApiCredential::class   => ApiCredentialObserver::class,
            Notification::class    => NotificationObserver::class,
            ChatParticipant::class => ChatParticipantObserver::class,
        ])
            ->and($provider->globalMiddlewares)->toContain(RequestTimer::class)
            ->and($provider->middleware['fleetbase.protected'])->toContain(
                'auth:sanctum',
                SetupFleetbaseSession::class,
                AuthorizationGuard::class
            )
            ->and($provider->middleware['fleetbase.api'])->toContain(LogApiRequests::class)
            ->and($provider->middleware['fleetbase.platform-api'])->toContain(AuthenticatePlatformApiToken::class)
            ->and($provider->commands)->toContain(
                Fleetbase\Console\Commands\Recovery::class,
                Fleetbase\Console\Commands\ForceResetDatabase::class,
                Fleetbase\Console\Commands\PurgeApiLogs::class,
                Fleetbase\Console\Commands\PurgeWebhookLogs::class,
                Fleetbase\Console\Commands\TelemetryPing::class
            );
    });

    test('core service provider registers package singletons and merges key configuration', function () {
        $provider = core_provider();

        $provider->register();

        expect(app()->make(ReportSchemaRegistry::class))->toBeInstanceOf(ReportSchemaRegistry::class)
            ->and(app()->make(ReportSchemaRegistry::class))->toBe(app()->make(ReportSchemaRegistry::class))
            ->and(app()->make(FileResolverService::class))->toBeInstanceOf(FileResolverService::class)
            ->and(app()->make(FileResolverService::class))->toBe(app()->make(FileResolverService::class))
            ->and(app()->make(TemplateRenderService::class))->toBeInstanceOf(TemplateRenderService::class)
            ->and(app()->make(TemplateRenderService::class))->toBe(app()->make(TemplateRenderService::class))
            ->and(config('api.throttle.enabled'))->toBeTrue()
            ->and(config('fleetbase.api.version'))->toBe('v1')
            ->and(config('fleetbase.connection.db'))->not->toBeNull()
            ->and(config('webhook-server.signer'))->not->toBeNull();
    });

    test('core service provider registers blade component aliases and command classes', function () {
        $blade = new CoreProviderContractsBladeFake();
        Blade::swap($blade);

        $provider = new CoreProviderContractsCommandProbe(bind_test_container());

        $provider->registerCustomBladeComponents();
        $provider->registerCommands();

        expect($blade->components)->toBe([
            'mail-layout' => 'fleetbase::layout.mail',
        ])
            ->and($provider->registeredCommands)->toBe($provider->commands);

        Facade::clearResolvedInstance('blade.compiler');
    });

    test('core service provider can merge config files from a directory', function () {
        $provider = core_provider();
        $path     = sys_get_temp_dir() . '/fleetbase-core-provider-config';

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path . '/provider-test.php', "<?php\n\nreturn ['feature' => ['enabled' => true], 'limit' => 25];\n");

        $method = new ReflectionMethod($provider, 'loadConfigFromDirectory');
        $method->setAccessible(true);
        $method->invoke($provider, $path);

        expect(config('provider-test.feature.enabled'))->toBeTrue()
            ->and(config('provider-test.limit'))->toBe(25);
    });

    test('core service provider registers core notification definitions', function () {
        $previousNotifications = NotificationRegistry::$notifications;

        try {
            NotificationRegistry::$notifications = [];
            $provider                            = core_provider();
            $method                              = new ReflectionMethod($provider, 'registerNotifications');
            $method->setAccessible(true);
            $method->invoke($provider);

            expect(NotificationRegistry::findNotificationRegistrationByDefinition(Fleetbase\Notifications\UserCreated::class))->not->toBeNull()
                ->and(NotificationRegistry::findNotificationRegistrationByDefinition(Fleetbase\Notifications\UserAcceptedCompanyInvite::class))->not->toBeNull()
                ->and(NotificationRegistry::getNotificationsByPackage('core'))->not->toBeEmpty();
        } finally {
            NotificationRegistry::$notifications = $previousNotifications;
        }
    });

    test('core service provider registers explicit expansion namespaces and ignores missing targets', function () {
        CoreProviderContractsExpansionTarget::$expanded = [];
        CoreProviderContractsExpansionTarget::$mixed    = [];

        $base = sys_get_temp_dir() . '/fleetbase-core-provider-expansions';
        $path = $base . '/src/Expansions';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($base . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Fleetbase\\ProviderTest\\' => 'src/',
                ],
            ],
        ]));

        file_put_contents($path . '/CoreProviderContractsExpansionMacro.php', "<?php\n");
        file_put_contents($path . '/CoreProviderContractsMissingExpansion.php', "<?php\n");

        $provider = core_provider();
        $provider->registerExpansionsFrom($path, '');
        $provider->registerExpansionsFrom($path . '/missing-directory', '');

        expect(CoreProviderContractsExpansionTarget::$expanded)->toBe([
            CoreProviderContractsExpansionMacro::class,
        ])
            ->and(CoreProviderContractsExpansionTarget::$mixed)->toBe([]);
    });

    test('core service provider discovers package expansion namespaces and registers multiple paths', function () {
        CoreProviderContractsExpansionTarget::$expanded = [];
        CoreProviderContractsExpansionTarget::$mixed    = [];

        $baseOne = sys_get_temp_dir() . '/fleetbase-core-provider-package-expansions-one';
        $pathOne = $baseOne . '/src/Expansions';
        $baseTwo = sys_get_temp_dir() . '/fleetbase-core-provider-package-expansions-two';
        $pathTwo = $baseTwo . '/src/Expansions';

        foreach ([$pathOne, $pathTwo] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        file_put_contents($baseOne . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Fleetbase\\ProviderTest\\' => 'src/',
                ],
            ],
        ]));
        file_put_contents($baseTwo . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Fleetbase\\ProviderOtherTest\\' => 'src/',
                ],
            ],
        ]));
        file_put_contents($pathOne . '/CoreProviderContractsPackageExpansionMacro.php', "<?php\n");
        file_put_contents($pathTwo . '/CoreProviderContractsMissingExpansion.php', "<?php\n");

        if (!class_exists('Fleetbase\\ProviderTest\\Expansions\\CoreProviderContractsPackageExpansionMacro', false)) {
            class_alias(CoreProviderContractsPackageExpansionMacro::class, 'Fleetbase\\ProviderTest\\Expansions\\CoreProviderContractsPackageExpansionMacro');
        }

        $provider = core_provider();
        $provider->registerExpansionsFrom([$pathOne, $pathTwo]);

        expect(class_exists('Fleetbase\\ProviderTest\\Expansions\\CoreProviderContractsPackageExpansionMacro'))->toBeTrue()
            ->and(CoreProviderContractsExpansionTarget::$expanded)->toBe([
                CoreProviderContractsPackageExpansionMacro::class,
            ])
            ->and(CoreProviderContractsExpansionTarget::$mixed)->toBe([]);
    });

    test('core service provider falls back to mixin when target expansion fails', function () {
        CoreProviderContractsMixinTarget::$expanded = [];
        CoreProviderContractsMixinTarget::$mixed    = [];

        $base = sys_get_temp_dir() . '/fleetbase-core-provider-mixin-expansions';
        $path = $base . '/src/Mixins';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($base . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Fleetbase\\ProviderMixinTest\\' => 'src/',
                ],
            ],
        ]));
        file_put_contents($path . '/CoreProviderContractsMixinMacro.php', "<?php\n");

        if (!class_exists('Fleetbase\\ProviderMixinTest\\Mixins\\CoreProviderContractsMixinMacro', false)) {
            class_alias(CoreProviderContractsMixinMacro::class, 'Fleetbase\\ProviderMixinTest\\Mixins\\CoreProviderContractsMixinMacro');
        }

        $provider = core_provider();
        $provider->registerExpansionsFrom($path);

        expect(class_exists('Fleetbase\\ProviderMixinTest\\Mixins\\CoreProviderContractsMixinMacro'))->toBeTrue()
            ->and(CoreProviderContractsMixinTarget::$expanded)->toBe([
                CoreProviderContractsMixinMacro::class,
            ])
            ->and(CoreProviderContractsMixinTarget::$mixed)->toBe([
                CoreProviderContractsMixinMacro::class,
            ]);
    });

    test('core service provider registers global and grouped middleware with the kernel and router', function () {
        $provider     = core_provider();
        $kernel       = new CoreProviderContractsKernelFake();
        $router       = new CoreProviderContractsRouterFake();
        app()->router = $router;
        app()->instance(Kernel::class, $kernel);

        $provider->registerMiddleware();

        expect($kernel->middlewares)->toBe($provider->globalMiddlewares)
            ->and($router->groups['fleetbase.protected'])->toBe($provider->middleware['fleetbase.protected'])
            ->and($router->groups['fleetbase.api'])->toBe($provider->middleware['fleetbase.api'])
            ->and($router->groups['fleetbase.platform-api'])->toBe($provider->middleware['fleetbase.platform-api']);
    });

    test('event service provider maps lifecycle framework and webhook events to their handlers', function () {
        $provider = new EventServiceProvider(bind_test_container());
        $listen   = (new ReflectionClass($provider))->getProperty('listen');
        $listen->setAccessible(true);
        $events = $listen->getValue($provider);

        expect($events[ResourceLifecycleEvent::class])->toBe([SendResourceLifecycleWebhook::class])
            ->and($events[AccountCreated::class])->toBe([Fleetbase\Listeners\HandleAccountCreated::class])
            ->and($events[BroadcastNotificationCreated::class])->toBe([TriggerPublicNotificationBroadcast::class])
            ->and($events[WebhookCallSucceededEvent::class])->toBe([LogSuccessfulWebhook::class])
            ->and($events[WebhookCallFailedEvent::class])->toBe([LogFailedWebhook::class])
            ->and($events[FinalWebhookCallFailedEvent::class])->toBe([LogFinalWebhookAttempt::class]);
    });

    test('core service provider skips scheduler callbacks in testing environments', function () {
        $provider = core_provider();
        $called   = false;

        $provider->scheduleCommands(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
    });

    test('socket cluster provider registers the socketcluster broadcaster driver', function () {
        $broadcast = new CoreProviderContractsBroadcastFake();
        Broadcast::swap($broadcast);

        (new SocketClusterServiceProvider(bind_test_container()))->boot();

        $broadcaster = $broadcast->extensions['socketcluster'](null, []);

        expect($broadcast->extensions)->toHaveKey('socketcluster')
            ->and($broadcaster)->toBeInstanceOf(SocketClusterBroadcaster::class);

        Facade::clearResolvedInstance('Broadcast');
    });

    test('webhook server provider configures package name and config file', function () {
        $package = new Package();

        (new WebhookServerServiceProvider(bind_test_container()))->configurePackage($package);

        expect($package->name)->toBe('laravel-webhook-server')
            ->and($package->configFileNames)->toBe(['webhook-server']);
    });
}
