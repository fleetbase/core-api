<?php

namespace Fleetbase\Observers;

use Fleetbase\Models\DashboardWidget;
use Fleetbase\Models\Dashboard;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;

class DashboardWidgetObserver
{
    private function isSystemDashboard($dashboard)
    {
        $systemDashboard = Dashboard::where('owner_uuid', 'system')->value('uuid');

        return $dashboard === $systemDashboard;
    }

    private function createErrorResponse($message, $statusCode = 400)
    {
        $errors = [$message];

        info('Widget change debug information', ['message' => $message]);

        $response = response()->json(['errors' => $errors], $statusCode);

        throw new HttpResponseException($response);
    }

    public function creating(DashboardWidget $widget)
    {
        if ($this->isSystemDashboard($widget->dashboard_uuid)) {
            abort(422, 'Cannot add widget to a system dashboard.');
        }
    }

    public function updating(DashboardWidget $widget)
    {
        if ($this->isSystemDashboard($widget->dashboard_uuid)) {
            $this->createErrorResponse('Cannot update widget of a system dashboard.');
        }
    }

    public function deleting(DashboardWidget $widget)
    {
        if ($this->isSystemDashboard($widget->dashboard_uuid)) {
            $this->createErrorResponse('Cannot remove widgets from a system dashboard.');
        }
    }

}
