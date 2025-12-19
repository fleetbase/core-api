<?php

namespace Fleetbase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ValidateETag
{
    /**
     * Handle an incoming request and validate ETag for conditional requests.
     *
     * This middleware checks if the client sent an If-None-Match header with an ETag.
     * If the ETag matches the response ETag, it returns a 304 Not Modified response,
     * allowing the browser to use its cached version.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Handle the request and get response
        $response = $next($request);

        // Only process responses that have an ETag set
        $responseETag = $response->getEtag();
        if (!$responseETag) {
            return $response;
        }

        // Get client's If-None-Match header (can contain multiple ETags)
        $clientETags = $request->getETags();

        // Check if any of the client's ETags match the response ETag
        if (in_array($responseETag, $clientETags)) {
            // ETags match - return 304 Not Modified
            $response->setNotModified();
        }

        return $response;
    }
}
