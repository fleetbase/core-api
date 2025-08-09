<?php

namespace Fleetbase\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as ThrottleRequestsMiddleware;

class ThrottleRequests extends ThrottleRequestsMiddleware
{
    public function handle($request, \Closure $next, $maxAttempts = null, $decayMinutes = null, $prefix = '')
    {
        $maxAttempts  = config('api.throttle.max_attempts', 90);
        $decayMinutes = config('api.throttle.decay_minutes', 1);

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
