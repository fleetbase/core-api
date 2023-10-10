<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;

class MetricController extends Controller
{
    /**
     * Get all relevant IAM metrics.
     *
     * @return \Illuminate\Http\Response
     */
    public function iam()
    {
        $metrics = [];
        // get number of users
        $metrics['users_count'] = \Fleetbase\Models\CompanyUser::where('company_uuid', session('company'))->whereNull('deleted_at')->whereHas('user')->count();
        // get number of groups
        $metrics['groups_count'] = \Fleetbase\Models\Group::where('company_uuid', session('company'))->count();
        // get number of iams
        $metrics['roles_count'] = \Fleetbase\Models\Role::where('company_uuid', session('company'))->count();
        // get number of roles
        $metrics['policy_count'] = \Fleetbase\Models\Policy::where('company_uuid', session('company'))->count();

        return response()->json($metrics);
    }

    /**
     * Dashboard configuration for IAM.
     *
     * @return \Illuminate\Http\Response
     */
    public function iamDashboard()
    {
        $metrics = [];
        // get number of users
        $metrics['users_count'] = \Fleetbase\Models\CompanyUser::where('company_uuid', session('company'))->whereNull('deleted_at')->whereHas('user')->count();
        // get number of groups
        $metrics['groups_count'] = \Fleetbase\Models\Group::where('company_uuid', session('company'))->count();
        // get number of iams
        $metrics['roles_count'] = \Fleetbase\Models\Role::where('company_uuid', session('company'))->count();
        // get number of roles
        $metrics['policy_count'] = \Fleetbase\Models\Policy::where('company_uuid', session('company'))->count();

        // dashboard config
        $dashboardConfig = [
            [
                'size'        => 12,
                'title'       => 'Identity & Access Management Metrics',
                'classList'   => [],
                'component'   => null,
                'queryParams' => [],
                'widgets'     => collect($metrics)
                    ->map(function ($value, $key) {
                        return [
                            'component' => 'count',
                            'options'   => [
                                'format' => null,
                                'title'  => str_replace('_', ' ', \Illuminate\Support\Str::title($key)),
                                'value'  => $value,
                            ],
                        ];
                    })
                    ->values()
                    ->toArray(),
            ],
        ];

        return response()->json(array_values($dashboardConfig));
    }
}
