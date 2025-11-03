<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Jobs\LogApiRequest;
use Fleetbase\Support\Http;
use Fleetbase\Traits\CustomMiddleware;
use Illuminate\Support\Facades\Log;

class LogApiRequests
{
    use CustomMiddleware;

    /**
     * @var bool whether logging is globally enabled
     */
    protected bool $enabled = true;

    /**
     * @var bool whether to use queued async dispatching for logs
     */
    protected bool $asyncLogging = true;

    /**
     * @var string[] list of URIs to exclude from logging
     */
    protected array $excludeUris = [
        'v1/drivers/*/track',
        'v1/vehicles/*/track',
    ];

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function handle($request, \Closure $next)
    {
        // If disabled or a read-only request
        if (!$this->enabled || $this->isReading($request)) {
            return $next($request);
        }

        // Get the response
        $response = $next($request);

        // Determine if this request should be logged
        if ($this->shouldLog($request)) {
            $this->logApiRequest($request, $response);
        }

        return $response;
    }

    /**
     * Determine if the current request should be logged.
     *
     * @param \Illuminate\Http\Request $request
     */
    protected function shouldLog($request): bool
    {
        if (!Http::isPublicRequest($request)) {
            return false;
        }

        if ($this->isExcludedUri($request)) {
            return false;
        }

        // Only log modification methods
        return in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    /**
     * Check if the current request URI should be excluded from logging.
     *
     * @param \Illuminate\Http\Request $request
     */
    protected function isExcludedUri($request): bool
    {
        $path = '/' . ltrim($request->path(), '/');

        // Normalize dynamic segments like /v1/drivers/{id}/track patterns
        foreach ($this->excludeUris as $pattern) {
            // Make pattern regex-compatible: replace wildcards and escape path separators
            $regex          = '/^' . str_replace(['*'], ['[^\/]+'], str_replace('/', '\/', trim($pattern, '/'))) . '$/i';
            $normalizedPath = trim($path, '/');

            if (preg_match($regex, $normalizedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log the API request payload asynchronously or synchronously.
     *
     * @param \Illuminate\Http\Request                                             $request
     * @param \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response $response
     */
    protected function logApiRequest($request, $response): void
    {
        try {
            $payload = LogApiRequest::getPayload($request, $response);
            $session = LogApiRequest::getSession();

            if ($this->asyncLogging) {
                // Asynchronous dispatch using Laravel's helper
                \Illuminate\Support\Facades\Bus::dispatch(new LogApiRequest($payload, $session));
            } else {
                // Synchronous execution within this process
                (new LogApiRequest($payload, $session))->handle();
            }
        } catch (\Aws\Sqs\Exception\SqsException $e) {
            Log::error('SQS dispatch failed: ' . $e->getMessage(), ['exception' => $e]);
        } catch (\Exception $e) {
            Log::error('API request logging failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
