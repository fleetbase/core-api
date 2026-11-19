<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Support\Utils;
use Illuminate\Console\Command;

class MigrateSandbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sandbox:migrate {--refresh} {--seed} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs the migration script for test data';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $refresh = Utils::castBoolean($this->option('refresh'));
        $seed    = Utils::castBoolean($this->option('seed'));
        $force   = Utils::castBoolean($this->option('force'));

        $command = $refresh ? 'migrate:refresh' : 'migrate';

        // only run core and fleetops migrations
        $paths                = ['vendor/fleetbase/core-api/migrations'];
        $migrationDirectories = $this->getExtensionsMigrationPaths();

        if (is_array($migrationDirectories)) {
            $paths = array_merge($paths, $migrationDirectories);
        }

        foreach ($paths as $path) {
            $arguments = [
                '--seed'     => $seed,
                '--force'    => $force,
                '--database' => config('fleetbase.connection.sandbox'),
                '--path'     => $path,
            ];

            // A path outside the app (a path-linked package in development) can't be made relative to it.
            if (str_starts_with($path, '/')) {
                $arguments['--realpath'] = true;
            }

            $this->call($command, $arguments);
        }
    }

    /**
     * Returns the relative paths to the migration directories of all installed Fleetbase extensions.
     *
     * This function retrieves all installed Fleetbase extensions, and then for each extension,
     * it checks if sandbox migrations are disabled. If not, it gets the migration directory
     * for the extension. All collected paths are then converted to relative paths and returned.
     *
     * @return array an array of the relative paths to the migration directories of all installed Fleetbase extensions
     */
    protected function getExtensionsMigrationPaths(): array
    {
        $packages = $this->getInstalledFleetbaseExtensions();
        $paths    = [];

        foreach ($packages as $packageName => $package) {
            // check if migrations is disabled for sandbox
            $sandboxMigrations = $this->getFleetbaseExtensionProperty($packageName, 'sandbox-migrations');

            if ($sandboxMigrations === false || $sandboxMigrations === 'false' || $sandboxMigrations === 0 || $sandboxMigrations === '0') {
                continue;
            }

            $path = $this->getMigrationDirectoryForExtension($packageName);

            if ($path) {
                $paths[] = $path;
            }
        }

        return $this->makePathsRelative($paths);
    }

    /**
     * Converts absolute migration paths to paths relative to the application base path.
     *
     * Extensions may live in core's vendor directory or in the extensions project (api/extensions/vendor), so a
     * path is made relative by stripping the base path rather than by cutting at the first "vendor" segment, which
     * pointed extensions-project migrations at core's vendor directory. A path outside the base path is returned
     * absolute (and migrated with --realpath). Trailing slashes are removed.
     *
     * @param array|null $paths absolute migration paths
     *
     * @return array the normalized paths
     */
    protected function makePathsRelative(?array $paths = []): array
    {
        if (!is_array($paths)) {
            return [];
        }

        $base = rtrim($this->applicationBasePath(), '/') . '/';

        return array_map(function ($path) use ($base) {
            $path = rtrim($path, '/');

            return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
        }, $paths);
    }

    protected function applicationBasePath(): string
    {
        return base_path();
    }

    protected function getInstalledFleetbaseExtensions(): array
    {
        return Utils::getInstalledFleetbaseExtensions();
    }

    protected function getFleetbaseExtensionProperty(string $packageName, string $key)
    {
        return Utils::getFleetbaseExtensionProperty($packageName, $key);
    }

    protected function getMigrationDirectoryForExtension(string $packageName): ?string
    {
        return Utils::getMigrationDirectoryForExtension($packageName);
    }
}
