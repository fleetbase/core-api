<?php

namespace Fleetbase\Http\Controllers;

use Fleetbase\Support\SocketClusterService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Welcome message only
     */
    public function hello(Request $request)
    {
        return response()->json(
            [
                'message' => 'Fleetbase API',
                'version' => config('fleetbase.api.version'),
            ]
        );
    }

    /**
     * Response time only
     */
    public function time()
    {
        return response()->json(
            [
                'ms' => microtime(true) - LARAVEL_START,
            ]
        );
    }
}
