<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\PlatformApi;
use Illuminate\Http\Request;

/**
 * For routes an app calls before anyone is signed in to it (sign-in, password reset).
 *
 * An app built for one organization presents that organization's API key. An app built
 * for the whole instance, such as Navigator, has no organization until its user signs in,
 * so it presents the instance's platform token instead. Either is accepted here, as is a
 * signed-in user's access token.
 *
 * A request that authenticated with the platform token has no organization and no user:
 * `PlatformApi::authenticated($request)` tells a controller which kind it is handling.
 * The platform token ships inside apps, so it must only ever open routes that are safe
 * for anyone to call.
 */
class AuthenticateApiOrPlatformToken
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     */
    public function handle($request, \Closure $next)
    {
        $token = $request->bearerToken();

        if (!PlatformApi::isPlatformToken($token)) {
            return app(AuthenticateOnceWithBasicAuth::class)->handle($request, $next);
        }

        if (!PlatformApi::validateToken($token)) {
            return response()->error('Invalid platform API token.', 401);
        }

        PlatformApi::markUsed();
        PlatformApi::markAuthenticated($request);

        return $next($request);
    }
}
