<?php

namespace Fleetbase\Http\Controllers\OAuthServer;

use Fleetbase\Auth\OAuthServer\OAuthServer;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /.well-known/openid-configuration (and the RFC 8414 alias), GET /oauth/jwks.
 */
class DiscoveryController extends Controller
{
    public function configuration(OAuthServer $server): JsonResponse
    {
        return new JsonResponse($server->discovery(), 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function jwks(OAuthServer $server): JsonResponse
    {
        return new JsonResponse(['keys' => [$server->keys()->jwk()]], 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
