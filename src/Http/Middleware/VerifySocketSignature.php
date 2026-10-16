<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\SocketCluster\SocketSignature;
use Fleetbase\Support\SocketCluster\SocketToken;
use Illuminate\Http\Request;

/**
 * Admits only requests the socket server signed with the derived authorize key.
 *
 * Used instead of session or token auth on the endpoints only the socket server calls. They
 * answer 404 while socket authentication is off; with the `key-only` parameter
 * (`VerifySocketSignature:key-only`) a configured key is enough, whatever the switch.
 */
class VerifySocketSignature
{
    public const KEY_ONLY = 'key-only';

    public function handle(Request $request, \Closure $next, ?string $requirement = null)
    {
        $requireSwitch = $requirement !== self::KEY_ONLY;
        $configured    = $requireSwitch ? SocketToken::enabled() : SocketToken::key() !== null;

        if (!$configured) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        $valid = SocketSignature::verify(
            SocketSignature::AUTHORIZE,
            $request->header(SocketSignature::HEADER_TIMESTAMP),
            $request->header(SocketSignature::HEADER_SIGNATURE),
            $request->getContent(),
            $requireSwitch
        );

        if (!$valid) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        return $next($request);
    }
}
