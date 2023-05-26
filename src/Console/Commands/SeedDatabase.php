<?php

namespace Fleetbase\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SeedDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetbase:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the Fleetbase seeder';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Artisan::call(
            'db:seed',
            [
                '--class' => 'Fleetbase\\Seeds\\ExtensionSeeder',
            ]
        );

        $this->info('Fleetbase seeds were run successfully.');
    }
}
