<?php

namespace Fleetbase\Http\Controllers;

use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Fleetbase\Support\SocketCluster\ChannelAuthorizer;
use Fleetbase\Support\SocketCluster\ChannelDecision;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Fleetbase\Support\SocketCluster\SocketToken;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Socket token minting for console, API and platform clients, and the channel authorize
 * endpoint the socket server calls. Every action answers 404 while SOCKETCLUSTER_AUTH_KEY
 * is unset, which clients read as "connect anonymously".
 */
class SocketAuthController extends Controller
{
    /**
     * POST int/v1/socket/token: a user token for the signed-in console session.
     */
    public function token(Request $request)
    {
        if (!SocketToken::enabled()) {
            return static::disabled();
        }

        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $principal = SocketPrincipal::forUser($user);

        // The console's sandbox toggle reads and writes the sandbox database.
        if (Utils::isTrue($request->header('Access-Console-Sandbox'))) {
            $principal = $principal->with(['env' => 'test']);
        }

        return response()->json(SocketToken::issue($principal));
    }

    /**
     * POST v1/socket/token: an api token for an API credential; for a Sanctum user token,
     * the principal a registered resolver claims (a driver, for FleetOps) or else a user token.
     */
    public function apiToken(Request $request)
    {
        if (!SocketToken::enabled()) {
            return static::disabled();
        }

        $bearer              = $request->bearerToken();
        $personalAccessToken = $bearer ? PersonalAccessToken::findToken($bearer) : null;

        if ($personalAccessToken !== null && $personalAccessToken->tokenable instanceof User) {
            $user      = $personalAccessToken->tokenable;
            $principal = app(SocketChannelRegistry::class)->resolvePrincipal($request, $user) ?? SocketPrincipal::forUser($user, $user->company_uuid);

            return response()->json(SocketToken::issue($principal));
        }

        $credential = Auth::getApiKey();

        if ($credential === null) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        return response()->json(SocketToken::issue(SocketPrincipal::forApiCredential($credential)));
    }

    /**
     * A system token for a platform API caller.
     */
    public function systemToken(Request $request)
    {
        if (!SocketToken::enabled()) {
            return static::disabled();
        }

        return response()->json(SocketToken::issue(SocketPrincipal::system()));
    }

    /**
     * POST int/v1/socket/authorize: the socket server asks whether a token may subscribe to a channel.
     *
     * The token is verified here again; nothing the socket server derived from it is trusted.
     */
    public function authorizeChannel(Request $request)
    {
        $token     = $request->input('token');
        $channel   = $request->input('channel');
        $hasToken  = is_string($token) && $token !== '';
        $principal = $hasToken ? SocketToken::verify($token) : null;
        $decision  = app(ChannelAuthorizer::class)->authorize($principal, is_string($channel) ? $channel : '');

        if ($hasToken && $principal === null && !$decision->allow) {
            $decision = ChannelDecision::denied('invalid_token');
        }

        return response()->json($decision->toArray());
    }

    protected static function disabled()
    {
        return response()->json(['error' => 'Not Found'], 404);
    }
}
