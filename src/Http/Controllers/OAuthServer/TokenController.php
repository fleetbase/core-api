<?php

namespace Fleetbase\Http\Controllers\OAuthServer;

use Fleetbase\Auth\OAuthServer\OAuthServer;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * POST /oauth/token and POST /oauth/revoke.
 */
class TokenController extends Controller
{
    public function token(Request $request, OAuthServer $server)
    {
        return $server->respondToTokenRequest($request);
    }

    public function revoke(Request $request, OAuthServer $server)
    {
        return $server->revoke($request);
    }
}
