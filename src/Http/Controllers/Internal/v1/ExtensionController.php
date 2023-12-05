<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Support\Verdaccio;
use Illuminate\Http\Request;

class ExtensionController extends Controller
{
    public function query(Request $request)
    {
        $extensions = Verdaccio::request('get', '/-/flb/extensions');

        return response()->json($extensions);
    }
}
