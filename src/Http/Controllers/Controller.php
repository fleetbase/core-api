<?php

namespace Fleetbase\Http\Controllers;

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
        return response()->json([
            'message' => 'Fleetbase API',
            'version' => '2.0.0',
        ]);
    }
    
    /**
     * Response time only
     */
    public function time()
    {
        return response()->json([
            'ms' => microtime(true) - LARAVEL_START,
        ]);
    }

    /**
     * Options for CORS requests
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function options()
    {
        return response()->json([]);
    }
}
