<?php

namespace Fleetbase\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    /**
     * Welcome message only.
     */
    public function hello(Request $request)
    {
        return response()->json(
            [
                'message'   => 'Fleetbase API',
                'version'   => config('fleetbase.api.version'),
                'fleetbase' => config('fleetbase.version'),
                'ms'        => microtime(true) - $request->attributes->get('request_start_time'),
            ]
        );
    }

    /**
     * Response time only.
     */
    public function time(Request $request)
    {
        return response()->json(
            [
                'ms' => microtime(true) - $request->attributes->get('request_start_time'),
            ]
        );
    }

    /**
     * Use this route for arbitrary testing.
     */
    public function test(Request $request)
    {
        return response()->json([
            'status' => 'ok',
            'ms'     => microtime(true) - $request->attributes->get('request_start_time'),
        ]);
    }
}
