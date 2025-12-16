<?php

namespace Fleetbase\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as ThrottleRequestsMiddleware;
use Illuminate\Support\Facades\Log;

class ThrottleRequests extends ThrottleRequestsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * This middleware supports multiple bypass mechanisms:
     * 1. Global disable via THROTTLE_ENABLED=false (for development/testing)
     * 2. Unlimited API keys via THROTTLE_UNLIMITED_API_KEYS (for production testing)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int|string  $maxAttempts
     * @param  float|int  $decayMinutes
     * @param  string  $prefix
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, \Closure $next, $maxAttempts = null, $decayMinutes = null, $prefix = '')
    {
        // Option 1: Check if throttling is globally disabled via configuration
        if (config('api.throttle.enabled', true) === false) {
            // Log when throttling is disabled (for security monitoring)
            if (app()->environment('production')) {
                Log::warning('API throttling is DISABLED globally', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]);
            }
            
            return $next($request);
        }

        // Option 3: Check if request is using an unlimited/test API key
        $apiKey = $this->extractApiKey($request);
        if ($apiKey && $this->isUnlimitedApiKey($apiKey)) {
            // Log usage of unlimited API key (for auditing)
            Log::info('Request using unlimited API key', [
                'api_key_prefix' => substr($apiKey, 0, 20) . '...',
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);
            
            return $next($request);
        }

        // Normal throttling: Get limits from configuration
        $maxAttempts  = config('api.throttle.max_attempts', 90);
        $decayMinutes = config('api.throttle.decay_minutes', 1);

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }

    /**
     * Extract API key from the request.
     *
     * Supports multiple authentication methods:
     * - Authorization header (Bearer token)
     * - Basic auth
     * - Query parameter
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function extractApiKey($request)
    {
        // Try Authorization header (Bearer token)
        $authorization = $request->header('Authorization');
        if ($authorization) {
            return $authorization;
        }

        // Try Basic Auth
        $user = $request->getUser();
        if ($user) {
            return 'Basic:' . $user;
        }

        // Try query parameter (less secure, but supported)
        $apiKey = $request->query('api_key');
        if ($apiKey) {
            return 'Query:' . $apiKey;
        }

        return null;
    }

    /**
     * Check if the given API key is in the unlimited keys list.
     *
     * @param  string  $apiKey
     * @return bool
     */
    protected function isUnlimitedApiKey($apiKey)
    {
        $unlimitedKeys = config('api.throttle.unlimited_keys', []);
        
        if (empty($unlimitedKeys)) {
            return false;
        }

        // Check for exact match
        if (in_array($apiKey, $unlimitedKeys)) {
            return true;
        }

        // Check for Bearer token match (with or without "Bearer " prefix)
        $cleanKey = str_replace('Bearer ', '', $apiKey);
        foreach ($unlimitedKeys as $unlimitedKey) {
            $cleanUnlimitedKey = str_replace('Bearer ', '', $unlimitedKey);
            if ($cleanKey === $cleanUnlimitedKey) {
                return true;
            }
        }

        return false;
    }
}
