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

    function seed_database_command_base(): string
    {
        $base = sys_get_temp_dir() . '/fleetbase-core-api-seed-command';

        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }

        file_put_contents($base . '/composer.lock', json_encode(['packages' => []]));

        return $base;
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
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
}
