<?php
// app/Http/Controllers/Api/DashboardWidgetController.php

namespace App\Http\Controllers\Api;

use App\Models\DashboardWidget;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardWidgetController extends Controller
{
    /**
     * Display a listing of the dashboard widgets.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $dashboardWidgets = DashboardWidget::all();
        return response()->json($dashboardWidgets);
    }

    /**
     * Display the specified dashboard widget.
     *
     * @param  DashboardWidget  $dashboardWidget
     * @return JsonResponse
     */
    public function show(DashboardWidget $dashboardWidget): JsonResponse
    {
        return response()->json($dashboardWidget);
    }

    /**
     * Store a newly created dashboard widget in storage.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'component' => 'required|string|max:255',
            'grid_options' => 'required|json',
            'options' => 'required|json',
            'dashboard_id' => 'required|exists:dashboards,id',
        ]);

        $dashboardWidget = DashboardWidget::create($request->all());
        return response()->json($dashboardWidget, 201);
    }

    /**
     * Update the specified dashboard widget in storage.
     *
     * @param  Request  $request
     * @param  DashboardWidget  $dashboardWidget
     * @return JsonResponse
     */
    public function update(Request $request, DashboardWidget $dashboardWidget): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'component' => 'string|max:255',
            'grid_options' => 'json',
            'options' => 'json',
            'dashboard_id' => 'required|exists:dashboards,id',
        ]);

        $dashboardWidget->update($request->all());
        return response()->json($dashboardWidget);
    }

    /**
     * Remove the specified dashboard widget from storage.
     *
     * @param  DashboardWidget  $dashboardWidget
     * @return JsonResponse
     */
    public function destroy(DashboardWidget $dashboardWidget): JsonResponse
    {
        $dashboardWidget->delete();
        return response()->json(['message' => 'Dashboard widget deleted successfully']);
    }
}
