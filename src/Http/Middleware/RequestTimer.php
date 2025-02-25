<?php

namespace Fleetbase\Http\Middleware;

class RequestTimer
{
    public function handle($request, \Closure $next)
    {
        $request->attributes->set('request_start_time', microtime(true));

        return $next($request);
    }
}
