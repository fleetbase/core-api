<?php

namespace Fleetbase\Console\Commands;

use Illuminate\Console\Command;
use Fleetbase\Support\Utils;

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
     *
     * @return mixed
     */
    public function handle()
    {
        $refresh = Utils::castBoolean($this->option('refresh'));
        $seed = Utils::castBoolean($this->option('seed'));
        $force = Utils::castBoolean($this->option('force'));

        $command = $refresh ? 'migrate:refresh' : 'migrate';

        // only run core and fleetops migrations
        $paths = [
            'vendor/fleetbase/core-api/migrations',
            'vendor/fleetbase/fleetops-api/migrations'
        ];

        foreach ($paths as $path) {
            $this->call($command, [
                '--seed' => $seed,
                '--force' => $force,
                '--database' => config('fleetbase.connection.sandbox'),
                '--path' => $path
            ]);
        }
    }
}
