<?php

namespace Fleetbase\Support {
    if (!function_exists(__NAMESPACE__ . '\\base_path')) {
        function base_path(string $path = ''): string
        {
            return $path ? getcwd() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : getcwd();
        }
    }
}

namespace {
    use Fleetbase\Console\Commands\MigrateSandbox;
    use Fleetbase\Support\Utils;
    use Illuminate\Support\Facades\Facade;

    class MigrateSandboxCommandSpy extends MigrateSandbox
    {
        public array $calls                = [];
        public array $installedExtensions  = [];
        public array $extensionProperties  = [];
        public array $migrationDirectories = [];

        public function __construct(private array $options = [])
        {
            parent::__construct();
        }

        public function option($key = null)
        {
            return $key === null ? $this->options : ($this->options[$key] ?? null);
        }

        public function call($command, array $arguments = [])
        {
            $this->calls[] = [$command, $arguments];

            return 0;
        }

        public function exposeRelativePaths(?array $paths): array
        {
            return $this->makePathsRelative($paths);
        }

        protected function getInstalledFleetbaseExtensions(): array
        {
            return $this->installedExtensions;
        }

        protected function getFleetbaseExtensionProperty(string $packageName, string $key)
        {
            return $this->extensionProperties[$packageName][$key] ?? null;
        }

        protected function getMigrationDirectoryForExtension(string $packageName): ?string
        {
            return $this->migrationDirectories[$packageName] ?? null;
        }
    }

    class MigrateSandboxCommandProbe extends MigrateSandbox
    {
        public function installedExtensions(): array
        {
            return $this->getInstalledFleetbaseExtensions();
        }

        public function extensionProperty(string $packageName, string $key)
        {
            return $this->getFleetbaseExtensionProperty($packageName, $key);
        }

        public function migrationDirectory(string $packageName): ?string
        {
            return $this->getMigrationDirectoryForExtension($packageName);
        }
    }

    function migrate_sandbox_command(array $options = []): MigrateSandboxCommandSpy
    {
        bind_test_container([
            'fleetbase.connection.sandbox' => 'sandbox',
        ]);
        Facade::clearResolvedInstances();

        return new MigrateSandboxCommandSpy($options);
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
    });

    it('runs core sandbox migrations against the configured sandbox connection', function () {
        $command = migrate_sandbox_command([
            'refresh' => false,
            'seed'    => false,
            'force'   => true,
        ]);

        expect($command->handle())->toBeNull()
            ->and($command->calls[0])->toBe([
                'migrate',
                [
                    '--seed'     => false,
                    '--force'    => true,
                    '--database' => 'sandbox',
                    '--path'     => 'vendor/fleetbase/core-api/migrations',
                ],
            ]);
    });

    it('switches to migrate refresh and casts string options for sandbox migrations', function () {
        $command = migrate_sandbox_command([
            'refresh' => 'true',
            'seed'    => '1',
            'force'   => 'false',
        ]);

        $command->handle();

        expect($command->calls[0])->toBe([
            'migrate:refresh',
            [
                '--seed'     => true,
                '--force'    => false,
                '--database' => 'sandbox',
                '--path'     => 'vendor/fleetbase/core-api/migrations',
            ],
        ]);
    });

    it('includes enabled extension sandbox migrations and skips disabled or missing migration directories', function () {
        $command = migrate_sandbox_command([
            'refresh' => false,
            'seed'    => true,
            'force'   => true,
        ]);
        $command->installedExtensions = [
            'fleetbase/fleetops'   => ['name' => 'fleetbase/fleetops'],
            'fleetbase/storefront' => ['name' => 'fleetbase/storefront'],
            'fleetbase/ledger'     => ['name' => 'fleetbase/ledger'],
            'fleetbase/ai'         => ['name' => 'fleetbase/ai'],
            'fleetbase/missing'    => ['name' => 'fleetbase/missing'],
        ];
        $command->extensionProperties = [
            'fleetbase/storefront' => ['sandbox-migrations' => 'false'],
            'fleetbase/ledger'     => ['sandbox-migrations' => 0],
            'fleetbase/ai'         => ['sandbox-migrations' => '0'],
        ];
        $command->migrationDirectories = [
            'fleetbase/fleetops' => '/srv/app/vendor/fleetbase/fleetops/migrations/sandbox/',
        ];

        $command->handle();

        expect($command->calls)->toHaveCount(2)
            ->and($command->calls[0][1]['--path'])->toBe('vendor/fleetbase/core-api/migrations')
            ->and($command->calls[1])->toBe([
                'migrate',
                [
                    '--seed'     => true,
                    '--force'    => true,
                    '--database' => 'sandbox',
                    '--path'     => 'vendor/fleetbase/fleetops/migrations/sandbox',
                ],
            ]);
    });

    it('normalizes migration paths defensively', function () {
        $command = migrate_sandbox_command();

        expect($command->exposeRelativePaths(null))->toBe([])
            ->and($command->exposeRelativePaths([
                '/srv/app/vendor/fleetbase/core-api/migrations/',
                '/srv/app/vendor/fleetbase/fleetops/migrations/sandbox',
            ]))->toBe([
                'vendor/fleetbase/core-api/migrations',
                'vendor/fleetbase/fleetops/migrations/sandbox',
            ]);
    });

    it('delegates extension metadata lookups to support utilities', function () {
        $command = new MigrateSandboxCommandProbe();

        expect($command->installedExtensions())->toBe(Utils::getInstalledFleetbaseExtensions())
            ->and($command->extensionProperty('fleetbase/missing-extension', 'sandbox-migrations'))->toBeNull()
            ->and($command->migrationDirectory('fleetbase/missing-extension'))->toBeNull();
    });
}
