<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\ApiConsumerMetrics;
use Fleetbase\Support\ApiRateLimits;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests as ThrottleRequestsMiddleware;
use Illuminate\Support\Facades\Log;

class ThrottleRequests extends ThrottleRequestsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Limits come from ApiRateLimits: the environment (config/api.php) supplies the
     * defaults and a system administrator may override them, per organization too, from
     * the console. Two bypasses remain for operators:
     * 1. Global disable via THROTTLE_ENABLED=false or the admin setting
     * 2. Unlimited API keys via THROTTLE_UNLIMITED_API_KEYS (for production testing)
     *
     * Every request is also counted per consumer (ApiConsumerMetrics) so administrators
     * can see who is driving traffic and who is being throttled.
     *
     * @param \Illuminate\Http\Request $request
     * @param int|string               $maxAttempts
     * @param float|int                $decayMinutes
     * @param string                   $prefix
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, \Closure $next, $maxAttempts = null, $decayMinutes = null, $prefix = '')
    {
        $settings = ApiRateLimits::settings();

        // Check if throttling is globally disabled
        if ($settings['enabled'] === false) {
            // Log when throttling is disabled (for security monitoring)
            if (app()->environment('production')) {
                Log::warning('API throttling is DISABLED globally', [
                    'ip'         => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'path'       => $request->path(),
                    'method'     => $request->method(),
                ]);
            }

            return $this->passThrough($request, $next, $settings, null);
        }

        // Check if request is using an unlimited/test API key
        $apiKey = $this->extractApiKey($request);
        if ($apiKey && $this->isUnlimitedApiKey($apiKey)) {
            // Log usage of unlimited API key (for auditing)
            Log::info('Request using unlimited API key', [
                'api_key_prefix' => substr($apiKey, 0, 20) . '...',
                'ip'             => $request->ip(),
                'path'           => $request->path(),
                'method'         => $request->method(),
            ]);

            return $this->passThrough($request, $next, $settings, null);
        }

        // Organization overrides need to know whose credential this is; skip the lookup
        // when there are none and consumers are not being tracked.
        $consumer = ($settings['overrides'] || $settings['track_consumers']) ? $this->describeConsumer($request) : [];
        $limit    = ApiRateLimits::limitFor($consumer['company_uuid'] ?? null, $settings);

        if ($limit === null) {
            return $this->passThrough($request, $next, $settings, null, $consumer);
        }

        try {
            $response = parent::handle($request, $next, $limit, $settings['decay_minutes'], $prefix);
        } catch (ThrottleRequestsException $exception) {
            $this->recordConsumer($request, $settings, $limit, $consumer, true);

            throw $exception;
        }

        $this->recordConsumer($request, $settings, $limit, $consumer);

        return $response;
    }

    /**
     * Let a request through unthrottled, still counting it for the consumer view.
     */
    protected function passThrough($request, \Closure $next, array $settings, ?int $limit, ?array $consumer = null)
    {
        $response = $next($request);

        $this->recordConsumer($request, $settings, $limit, $consumer);

        return $response;
    }

    /**
     * Count the request against its consumer when consumer tracking is on.
     */
    protected function recordConsumer($request, array $settings, ?int $limit, ?array $consumer = null, bool $throttled = false): void
    {
        if (!$settings['track_consumers']) {
            return;
        }

        $consumer = $consumer ?: $this->describeConsumer($request);

        ApiConsumerMetrics::record($this->resolveRequestSignature($request), array_merge($consumer, [
            'scope' => $request->segment(1) ?? '',
            'ip'    => $request->ip(),
            'limit' => $limit,
        ]), $throttled);
    }

    /**
     * Who is making this request, for overrides and the consumer view.
     */
    protected function describeConsumer($request): array
    {
        if ($credential = $this->extractApiKey($request)) {
            return ApiRateLimits::identify($credential);
        }

        if ($user = $request->user()) {
            return [
                'type'         => 'user',
                'label'        => $user->name ?? $user->email ?? (string) $user->getAuthIdentifier(),
                'company_uuid' => $user->company_uuid ?? null,
            ];
        }

        return ['type' => 'ip', 'label' => $request->ip(), 'company_uuid' => null];
    }

    /**
     * Resolve the limiter key from the consumer rather than the connection.
     *
     * This middleware runs ahead of API authentication, so Laravel's default signature
     * (the authenticated user, else route domain + client IP) always fell through to the
     * IP. Behind a load balancer or reverse proxy that IP is the proxy's, and no route has
     * a domain, so every tenant, API key and console visitor shared one bucket: a single
     * busy integration returned 429 to the entire platform.
     *
     * The presented credential identifies the consumer without a database lookup, so the
     * key is the hashed credential. Only requests carrying no credential fall back to the
     * user, then the IP. The first path segment ("v1", "int", ...) keeps the public API and
     * the console's public routes in separate buckets.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $scope = 'fleetbase-throttle|' . ($request->segment(1) ?? '');

        if ($credential = $this->extractApiKey($request)) {
            return sha1($scope . '|credential|' . $credential);
        }

        if ($user = $request->user()) {
            return sha1($scope . '|user|' . $user->getAuthIdentifier());
        }

        return sha1($scope . '|ip|' . $request->ip());
    }

    /**
     * Extract API key from the request.
     *
     * Supports multiple authentication methods:
     * - Authorization header (Bearer token)
     * - Basic auth
     * - Query parameter
     *
     * @param \Illuminate\Http\Request $request
     *
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
     * @param string $apiKey
     *
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
