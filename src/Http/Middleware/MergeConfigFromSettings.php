<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\EnvironmentMapper;
use Illuminate\Http\Request;

class MergeConfigFromSettings
{
    public function handle(Request $request, \Closure $next)
    {
        EnvironmentMapper::mergeConfigFromSettingsOptimized();

        return $next($request);
    }
}
