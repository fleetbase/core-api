<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\Auth;

class SetupFleetbaseSession
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function handle($request, \Closure $next)
    {
        Auth::setSession($request->user());
        Auth::setSandboxSession($request);

        return $next($request);
    }
}
