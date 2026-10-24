<?php

namespace Fleetbase\Http\Controllers\OAuthServer;

use Fleetbase\Auth\OAuthServer\Exceptions\AuthorizeError;
use Fleetbase\Auth\OAuthServer\OAuthServer;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Support\Utils;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /oauth/authorize: where an app sends the user to "Continue with Fleetbase".
 *
 * The request is checked here, then the user goes to the consent screen in the console, which
 * runs on the console's signed-in session (and so its two-factor rules). Errors about the app
 * itself (unknown client, unregistered redirect URI) are shown on the console page and never sent
 * to the redirect URI; any other error goes back to the app.
 */
class AuthorizationController extends Controller
{
    public function authorize(Request $request, OAuthServer $server): RedirectResponse
    {
        $consentPath = config('oauth-server.console_authorize_path', '/oauth/authorize');

        try {
            $server->validateAuthorizationRequest($request->query());
        } catch (AuthorizeError $e) {
            if ($e->canRedirect()) {
                return redirect()->away($e->redirectUrl($server->issuer()));
            }

            return redirect()->away(Utils::consoleUrl($consentPath, ['error' => $e->error, 'error_description' => $e->description]));
        }

        return redirect()->away(Utils::consoleUrl($consentPath, $request->query()));
    }
}
