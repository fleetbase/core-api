<?php
namespace Fleetbase\Seeds;

use Illuminate\Database\Seeder;
use Fleetbase\Models\Dashboard;

class DashboardSeeder extends Seeder
{
    public function run()
    {
        $defaultDashboard = Dashboard::where('owner_uuid', "system")->first();

        if ($defaultDashboard) {
            return;
        }

        $defaultDashboard = Dashboard::create([
            'name' => 'Default Dashboard',
            'is_default' => false,
            'owner_uuid' => 'system'
        ]);


        $widgets = [
            [
                'name' => 'Fleetbase Blog',
                'component' => 'fleetbase-blog',
                'grid_options' => ['h' => 9, 'w' => 8],
                'options' => ['title' => 'Fleetbase Blog'],
            ],
            [
                'name' => 'Github Card',
                'component' => 'github-card',
                'grid_options' => ['h' => 8, 'w' => 4],
                'options' => ['title' => 'Github Card'],
            ],
            [
                'name' => 'Fleet-Ops Metrics',
                'component' => 'dashboard/metric',
                'grid_options' => ['h' => 12, 'w' => 12],
                'options' => ['title' => 'Fleet-Ops Metrics', 'endpoint' => 'int/v1/fleet-ops/dashboard'],
            ],
            [
                'name' => 'Identity & Access Management Metrics',
                'component' => 'dashboard/metric',
                'grid_options' => ['h' => 7, 'w' => 12],
                'options' => ['title' => 'Identity & Access Management Metrics', 'endpoint' => 'int/v1/metrics/iam-dashboard'],
            ],
            [
                'name' => 'Storefront Metrics',
                'component' => 'dashboard/metric',
                'grid_options' => ['h' => 8, 'w' => 12],
                'options' => ['title' => 'Storefront Metrics', 'endpoint' => 'storefront/int/v1/dashboard'],
            ],
        ];

        if ($defaultDashboard) {
            foreach ($widgets as $widget) {
                $defaultDashboard->widgets()->create($widget);
            }
        }
    }
}
