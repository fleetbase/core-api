<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\PlatformApi;
use Illuminate\Http\Request;

class AuthenticatePlatformApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     */
    public function handle($request, \Closure $next)
    {
        if (!PlatformApi::validateToken($request->bearerToken())) {
            return response()->error('Invalid platform API token.', 401);
        }

        PlatformApi::markUsed();

        return $next($request);
    }
}
