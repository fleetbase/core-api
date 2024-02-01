<?php

namespace Fleetbase\Seeds;

use Illuminate\Database\Seeder;

class FleetbaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(ExtensionSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(DashboardSeeder::class);
    }
}
