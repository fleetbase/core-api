<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\SocketCluster\SocketSignature;
use Fleetbase\Support\SocketCluster\SocketToken;
use Illuminate\Http\Request;

/**
 * Admits only requests the socket server signed with the derived authorize key.
 *
 * Used instead of session or token auth on the channel authorize endpoint, which only the
 * socket server calls.
 */
class VerifySocketSignature
{
    public function handle(Request $request, \Closure $next)
    {
        if (!SocketToken::enabled()) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        $valid = SocketSignature::verify(
            SocketSignature::AUTHORIZE,
            $request->header(SocketSignature::HEADER_TIMESTAMP),
            $request->header(SocketSignature::HEADER_SIGNATURE),
            $request->getContent()
        );

        if (!$valid) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        return $next($request);
    }
}
