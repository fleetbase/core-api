<?php

namespace Fleetbase\Http\Middleware;

use Closure;
use Fleetbase\Support\ApiModelCache;
use Illuminate\Http\Request;

/**
 * Attach Cache Headers Middleware
 * 
 * Adds cache status headers to API responses for debugging and monitoring.
 * 
 * Headers added:
 * - X-Cache-Status: HIT, MISS, ERROR, or DISABLED
 * - X-Cache-Key: The cache key used (only in debug mode)
 */
class AttachCacheHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only add headers for API requests
        if (!$this->isApiRequest($request)) {
            return $response;
        }

        // Check if caching is enabled
        if (!ApiModelCache::isCachingEnabled()) {
            $response->headers->set('X-Cache-Status', 'DISABLED');
            return $response;
        }

        // Get cache status from ApiModelCache
        $cacheStatus = ApiModelCache::getCacheStatus();
        $cacheKey = ApiModelCache::getCacheKey();

        // Add cache status header
        if ($cacheStatus) {
            $response->headers->set('X-Cache-Status', $cacheStatus);
        } else {
            // No cache operation occurred (e.g., POST/PUT/DELETE requests)
            $response->headers->set('X-Cache-Status', 'BYPASS');
        }

        // Add cache key header in debug mode
        if ($this->isDebugMode() && $cacheKey) {
            $response->headers->set('X-Cache-Key', $cacheKey);
        }

        // Add cache info header
        if ($cacheStatus === 'HIT' || $cacheStatus === 'MISS') {
            $response->headers->set('X-Cache-Driver', config('cache.default'));
        }

        // Reset cache status for next request
        ApiModelCache::resetCacheStatus();

        return $response;
    }

    /**
     * Check if the request is an API request.
     *
     * @param Request $request
     * @return bool
     */
    protected function isApiRequest(Request $request): bool
    {
        // Check if request path starts with /api/
        if (str_starts_with($request->path(), 'api/')) {
            return true;
        }

        // Check if request expects JSON
        if ($request->expectsJson()) {
            return true;
        }

        // Check Accept header
        if ($request->header('Accept') === 'application/json') {
            return true;
        }

        return false;
    }

    /**
     * Check if debug mode is enabled.
     *
     * @return bool
     */
    protected function isDebugMode(): bool
    {
        return config('app.debug', false) || config('api.cache.debug', false);
    }
}
