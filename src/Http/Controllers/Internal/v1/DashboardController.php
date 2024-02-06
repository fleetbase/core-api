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
        $ownerId = session('user');
        $dashboardId = $request->input('dashboard_uuid');

        Dashboard::where('owner_uuid', $ownerId)->update(['is_default' => false]);

        $selectedDashboard = Dashboard::where('owner_uuid', $ownerId)
            ->where('uuid', $dashboardId)
            ->first();

        if ($selectedDashboard) {
            $selectedDashboard->is_default = true;
            $selectedDashboard->save();
            return response()->json($selectedDashboard);
        } 

        return response()->error('Dashboard not found.', 404);
    }
}
