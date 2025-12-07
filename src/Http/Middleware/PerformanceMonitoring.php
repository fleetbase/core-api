<?php

namespace Fleetbase\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PerformanceMonitoring
{
    /**
     * Handle an incoming request and log performance metrics.
     */
    public function handle(Request $request, \Closure $next)
    {
        $startTime   = microtime(true);
        $startMemory = memory_get_usage();

        // Process the request
        $response = $next($request);

        // Calculate metrics
        $duration    = round((microtime(true) - $startTime) * 1000, 2); // ms
        $memoryUsage = round((memory_get_usage() - $startMemory) / 1024 / 1024, 2); // MB

        // Add performance headers
        $response->headers->set('X-Response-Time', $duration . 'ms');
        $response->headers->set('X-Memory-Usage', $memoryUsage . 'MB');

        // Log slow requests (> 1 second)
        if ($duration > 1000) {
            Log::warning('[Performance] Slow request detected', [
                'method'   => $request->method(),
                'url'      => $request->fullUrl(),
                'duration' => $duration . 'ms',
                'memory'   => $memoryUsage . 'MB',
                'user'     => $request->user()?->uuid ?? 'guest',
            ]);
        }

        // Log to debug in development
        if (config('app.debug')) {
            Log::debug('[Performance]', [
                'method'   => $request->method(),
                'url'      => $request->path(),
                'duration' => $duration . 'ms',
                'memory'   => $memoryUsage . 'MB',
            ]);
        }

        return $response;
    }
}
