<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\ApiModelCache;
use Illuminate\Http\Request;

/**
 * Attach Cache Headers Middleware.
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
     */
    public function handle(Request $request, \Closure $next)
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
        $cacheKey    = ApiModelCache::getCacheKey();

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
     * Uses Fleetbase's Http helper to determine if the request is either:
     * - Internal request (int/v1/... - used by Fleetbase applications)
     * - Public request (v1/... - used by end users for integrations/dev)
     */
    protected function isApiRequest(Request $request): bool
    {
        // Check if it's an internal request (int/v1/...)
        if (\Fleetbase\Support\Http::isInternalRequest($request)) {
            return true;
        }

        // Check if it's a public API request (v1/...)
        if (\Fleetbase\Support\Http::isPublicRequest($request)) {
            return true;
        }

        // Fallback: check if request expects JSON
        if ($request->expectsJson()) {
            return true;
        }

        return false;
    }

    /**
     * Check if debug mode is enabled.
     */
    protected function isDebugMode(): bool
    {
        return config('app.debug', false) || config('api.cache.debug', false);
    }
}
