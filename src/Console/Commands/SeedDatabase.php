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
    protected $signature = 'fleetbase:seed {--class=FleetbaseSeeder}';

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
        $class = $this->option('class');

        if ($class) {
            Artisan::call(
                'db:seed',
                [
                    '--class' => 'Fleetbase\\Seeds\\' . $class,
                ]
            );
            $this->info('Fleetbase ' . $class . ' Seeder was run Successfully!');
        } else {
            Artisan::call(
                'db:seed',
                [
                    '--class' => 'Fleetbase\\Seeds\\FleetbaseSeeder',
                ]
            );
            $this->info('Fleetbase Seeders were run Successfully!');
        }
    }
}
