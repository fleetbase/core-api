<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Illuminate\Http\Request;
use Fleetbase\Models\Dashboard;

class DashboardController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'dashboard';

    /**
    * Switch the default dashboard.
    *
    * @param \Illuminate\Http\Request $request
    * @return \Illuminate\Http\Response
    */
    public function switchDashboard(Request $request)
    {
        $dashboardId = $request->input('dashboard_uuid');

        Dashboard::where('user_uuid', session('user'))->update(['is_default' => false]);

        $selectedDashboard = Dashboard::where('user_uuid', session('user'))
            ->where('uuid', $dashboardId)
            ->first();

        if ($selectedDashboard) {
            $selectedDashboard->is_default = true;
            $selectedDashboard->save();
            return response()->json(['dashboard' => $selectedDashboard]);
        }

        return response()->error('Dashboard not found.', 404);
    }

    /**
     * Resets all the dashboards default status.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function resetDefaultDashboard()
    {
        Dashboard::where('user_uuid', session('user'))->update(['is_default' => false]);

        return response()->json(['status' => 'ok']);
    }
}
