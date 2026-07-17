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
    use Illuminate\Support\Facades\Facade;

    class MigrateSandboxCommandSpy extends MigrateSandbox
    {
        public array $calls = [];

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
}
