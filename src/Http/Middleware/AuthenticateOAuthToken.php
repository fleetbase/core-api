<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Auth\OAuthServer\Exceptions\TokenError;
use Fleetbase\Auth\OAuthServer\OAuthServer;
use Fleetbase\Models\Company;
use Fleetbase\Models\OAuthAccessToken;
use Illuminate\Http\Request;

/**
 * Protects an endpoint with an access token from "Continue with Fleetbase".
 *
 *     ->middleware(AuthenticateOAuthToken::class . ':storefront:read')
 *
 * Every listed scope is required. The token, its user and its organization are put on the
 * request; controllers read them with AuthenticateOAuthToken::token() / ::company(). Nothing is
 * put in the session: these requests never act as the user's console session.
 */
class AuthenticateOAuthToken
{
    public const ATTRIBUTE = 'oauth_access_token';

    public function __construct(protected OAuthServer $server)
    {
    }

    public function handle(Request $request, \Closure $next, string ...$scopes)
    {
        try {
            $token = $this->server->authenticate($request, $scopes);
        } catch (TokenError $e) {
            return $e->toResponse();
        }

        $request->attributes->set(static::ATTRIBUTE, $token);
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }

    public static function token(Request $request): OAuthAccessToken
    {
        $token = $request->attributes->get(static::ATTRIBUTE);
        if (!$token instanceof OAuthAccessToken) {
            throw new \LogicException('This route is missing the AuthenticateOAuthToken middleware.');
        }

        return $token;
    }

    /**
     * The organization the token is bound to. Only call it on routes that require an
     * organization scope, which guarantees there is one.
     */
    public static function company(Request $request): Company
    {
        $company = static::token($request)->company;
        if (!$company) {
            throw new \LogicException('This token is not bound to an organization; require an organization scope on the route.');
        }

        return $company;
    }
}
