<?php

namespace Fleetbase\Support {
    if (!function_exists(__NAMESPACE__ . '\\base_path')) {
        function base_path(string $path = ''): string
        {
            $base = sys_get_temp_dir() . '/fleetbase-core-api-seed-command';

            return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
        }
    }
}

namespace {
    use Fleetbase\Console\Commands\SeedDatabase;
    use Illuminate\Container\Container;
    use Illuminate\Support\Facades\Facade;
    use Symfony\Component\Console\Tester\CommandTester;

    class SeedDatabaseCommandContainer extends FleetbaseTestContainer
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    }

    class SeedDatabaseDefaultPathTestCommand extends SeedDatabase
    {
        public array $seeders = [];

        protected function runSeeder(string $class): int
        {
            $this->seeders[] = $class;

            return 0;
        }
    }

    class SeedDatabaseExplicitSeederTestCommand extends SeedDatabase
    {
        public array $calls = [];

        protected function runSeeder(string $class): int
        {
            $this->calls[] = $class;

            return 7;
        }
    }

    class SeedDatabaseRunSeederTestCommand extends SeedDatabase
    {
        public array $calls = [];

        public function call($command, array $arguments = [])
        {
            $this->calls[] = [$command, $arguments];

            return 9;
        }

        public function runSeederDirectly(string $class): int
        {
            return parent::runSeeder($class);
        }
    }

    function seed_database_command_base(): string
    {
        $base = \Fleetbase\Support\base_path();

        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }

        $composerLock = $base . '/composer.lock';
        if (!array_key_exists('seed_database_original_composer_lock', $GLOBALS)) {
            $GLOBALS['seed_database_original_composer_lock'] = is_file($composerLock) ? file_get_contents($composerLock) : null;
            $GLOBALS['seed_database_composer_lock_path']     = $composerLock;
        }

        $markerFile = sys_get_temp_dir() . '/fleetbase-core-api-extension-seeders-ran.log';
        if (is_file($markerFile)) {
            unlink($markerFile);
        }

        file_put_contents($composerLock, json_encode(['packages' => []]));

        return $base;
    }

    function seed_database_write_extension_seeder(string $packageName, string $className, string $namespace): string
    {
        $base            = seed_database_command_base();
        $seedersPath     = $base . '/vendor/' . $packageName . '/server/seeders';
        $seederFile      = $seedersPath . '/' . $className . '.php';
        $markerFile      = sys_get_temp_dir() . '/fleetbase-core-api-extension-seeders-ran.log';
        $markerFileValue = var_export($markerFile, true);

        if (!is_dir($seedersPath)) {
            mkdir($seedersPath, 0777, true);
        }

        $GLOBALS['seed_database_generated_files'][] = $seederFile;

        file_put_contents($base . '/composer.lock', json_encode([
            'packages' => [
                [
                    'name'     => $packageName,
                    'keywords' => ['fleetbase-extension'],
                    'autoload' => [
                        'psr-4' => [
                            $namespace . '\\' => 'server/seeders',
                        ],
                    ],
                ],
            ],
        ]));

        file_put_contents($seederFile, <<<PHP
<?php

namespace {$namespace};

class {$className}
{
    public function run(): void
    {
        file_put_contents({$markerFileValue}, static::class . PHP_EOL, FILE_APPEND);
    }
}
PHP);

        return $markerFile;
    }

    function seed_database_restore_generated_files(): void
    {
        foreach ($GLOBALS['seed_database_generated_files'] ?? [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $composerLock = $GLOBALS['seed_database_composer_lock_path'] ?? null;
        if ($composerLock) {
            if ($GLOBALS['seed_database_original_composer_lock'] === null) {
                if (is_file($composerLock)) {
                    unlink($composerLock);
                }
            } else {
                file_put_contents($composerLock, $GLOBALS['seed_database_original_composer_lock']);
            }
        }

        $markerFile = sys_get_temp_dir() . '/fleetbase-core-api-extension-seeders-ran.log';
        if (is_file($markerFile)) {
            unlink($markerFile);
        }

        unset(
            $GLOBALS['seed_database_generated_files'],
            $GLOBALS['seed_database_composer_lock_path'],
            $GLOBALS['seed_database_original_composer_lock']
        );
    }

    afterEach(function () {
        seed_database_restore_generated_files();
        Facade::clearResolvedInstances();
    });

    it('runs a single requested fleetbase seeder class with force enabled', function () {
        Container::setInstance(new SeedDatabaseCommandContainer());
        $container = bind_test_container();
        Facade::clearResolvedInstances();

        $command = new SeedDatabaseExplicitSeederTestCommand();
        $command->setLaravel($container);
        $tester = new CommandTester($command);

        expect($tester->execute(['--class' => 'RolesSeeder']))->toBe(7)
            ->and($command->calls)->toBe(['Fleetbase\\Seeders\\RolesSeeder'])
            ->and($tester->getDisplay())->not->toContain('Running Fleetbase core seeder');
    });

    it('delegates seeder execution to db seed with the selected class and force flag', function () {
        $command = new SeedDatabaseRunSeederTestCommand();

        expect($command->runSeederDirectly('Fleetbase\\Seeders\\RolesSeeder'))->toBe(9)
            ->and($command->calls)->toBe([
                [
                    'db:seed',
                    [
                        '--class' => 'Fleetbase\\Seeders\\RolesSeeder',
                        '--force' => true,
                    ],
                ],
            ]);
    });

    it('runs the core seeder and warns when no extension seeders are installed', function () {
        seed_database_command_base();
        Container::setInstance(new SeedDatabaseCommandContainer());
        $container = bind_test_container();
        Facade::clearResolvedInstances();

        $command = new SeedDatabaseDefaultPathTestCommand();
        $command->setLaravel($container);
        $tester  = new CommandTester($command);

        expect($tester->execute([]))->toBe(0)
            ->and($command->seeders)->toBe([
                'Fleetbase\\Seeders\\FleetbaseSeeder',
            ])
            ->and($tester->getDisplay())->toContain('Running Fleetbase core seeder')
            ->and($tester->getDisplay())->toContain('No extension seeders found.');
    });

    it('discovers and runs installed extension seeders after the core seeder', function () {
        $markerFile = seed_database_write_extension_seeder('fleetbase/testing-extension', 'TestingExtensionSeeder', 'Fleetbase\\TestingExtension\\Seeders');
        Container::setInstance(new SeedDatabaseCommandContainer());
        $container = bind_test_container();
        Facade::clearResolvedInstances();

        $command = new SeedDatabaseDefaultPathTestCommand();
        $command->setLaravel($container);
        $tester = new CommandTester($command);

        expect($tester->execute([]))->toBe(0)
            ->and($command->seeders)->toBe([
                'Fleetbase\\Seeders\\FleetbaseSeeder',
            ])
            ->and(file($markerFile, FILE_IGNORE_NEW_LINES))->toBe(['Fleetbase\\TestingExtension\\Seeders\\TestingExtensionSeeder'])
            ->and($tester->getDisplay())->toContain('Running Fleetbase core seeder')
            ->and($tester->getDisplay())->toContain('Running extension seeders:')
            ->and($tester->getDisplay())->toContain('TestingExtensionSeeder')
            ->and($tester->getDisplay())->toContain('All seeders completed.');
    });
}
