<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOnceWithBasicAuth
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     */
    public function handle($request, \Closure $next)
    {
        $authenticationResponse = $this->authenticatedWithBasic($request);

        if ($authenticationResponse === true) {
            return $next($request);
        }

        if ($authenticationResponse instanceof Response) {
            return $authenticationResponse;
        }

        return response()->error('Oops! The API credentials provided were not valid', 401);
    }

    /**
     * Authenticate the request using basic authentication.
     *
     * @param string|null $connection
     */
    public function authenticatedWithBasic(Request $request, $connection = null)
    {
        // get secret key
        $token = $request->bearerToken();
        if (!$token) {
            return response()->error('Oops! No api credentials found with this request', 401);
        }

        // Check if sanctum token
        if ($sanctumToken = $this->getSanctumToken($token)) {
            return $this->authenticateSanctumToken($sanctumToken, $request);
        }

        // Check if secret key
        $isSecretKey = Str::startsWith($token, '$');

        // Depending on API key format set the connection to find credential on
        if (!$connection) {
            $connection = Str::startsWith($token, 'flb_test_') ? 'sandbox' : 'mysql';
        }

        // Find the API Credential record
        $findApKey = ApiCredential::on($connection)
            ->where('key', $token)
            ->with(['company.owner'])
            ->withoutGlobalScopes();

        // Only if User-Agent = "'@fleetbase/sdk;node" allow secret key to authenticate
        if ($request->userAgent() === '@fleetbase/sdk;node') {
            $findApKey = $findApKey->orWhere('secret', $token);
        }

        $apiCredential = $findApKey->first();

        // If secret key and no api credential found check sandbox connection
        if ($isSecretKey && !$apiCredential && $connection === 'mysql') {
            return $this->authenticatedWithBasic($request, 'sandbox');
        }

        // Credentials don't exist
        if (!$apiCredential || Utils::isEmpty($apiCredential, 'company.owner')) {
            return response()->error('Oops! The api credentials provided were not valid', 401);
        }

        // If OPTIONS set api key and continue
        if ($request->isMethod('OPTIONS')) {
            // Set api credential session
            Auth::setApiKey($apiCredential);

            return true;
        }

        // If credentials have expired
        if ($apiCredential->hasExpired()) {
            return response()->error('Oops! These api credentials have expired', 401);
        }

        // Login user
        Auth::setSession($apiCredential);

        // Bind the user resolver so $request->user() answers on the public API.
        //
        // Auth::setSession() writes session('user') but takes $login = false, so nothing
        // ever binds a resolver and the default guard is session-based with no login —
        // meaning $request->user() was null on EVERY public API request. Extensions that
        // reasonably read it got nothing: the ledger wallet routes answered 401 to every
        // credential, and fleetops' tokenless register-device answered 404, both because
        // the authenticated identity was invisible through the standard accessor.
        static::bindUserResolver($request, User::find($apiCredential->user_uuid));

        // Set sandbox session if applicable
        Auth::setSandboxSession($request, $apiCredential);

        // Set api credential session
        Auth::setApiKey($apiCredential);

        return true;
    }

    /**
     * Authenticate the request using Sanctum token.
     */
    private function authenticateSanctumToken(PersonalAccessToken $sanctumToken, ?Request $request = null)
    {
        if ($sanctumToken && $sanctumToken->tokenable instanceof User) {
            // Make sure company is set
            if (!Str::isUuid($sanctumToken->tokenable->company_uuid)) {
                return response()->error('Oops! The api credentials provided were not valid', 401);
            }

            // Set user to session
            Auth::setSession($sanctumToken->tokenable);

            // Same reasoning as above: a driver or customer authenticating with their own
            // token is exactly the case where $request->user() ought to answer.
            static::bindUserResolver($request, $sanctumToken->tokenable);

            // Get API Credential for User
            $apiCredential = ApiCredential::where('company_uuid', $sanctumToken->tokenable->company_uuid)->first();
            if ($apiCredential) {
                // Set api credential session
                Auth::setApiKey($apiCredential);
            } else {
                Auth::setApiKey($sanctumToken);
            }

            return true;
        }

        return response()->error('Oops! The api credentials provided were not valid', 401);
    }

    /**
     * Make the authenticated user visible through $request->user().
     *
     * Only sets a resolver when one is not already bound, so a guard that genuinely
     * authenticated the request (the internal session routes) always wins.
     */
    protected static function bindUserResolver(?Request $request, $user): void
    {
        if (!$request instanceof Request || !$user instanceof User) {
            return;
        }

        // getUserResolver() never returns null — Request falls back to a closure that
        // yields null — so the presence of a resolved user is the only usable signal.
        if ($request->user() instanceof User) {
            return;
        }

        $request->setUserResolver(static fn () => $user);
    }

    /**
     * Get an instance of the PersonalAccessToken if valid.
     */
    private function getSanctumToken(string $token): ?PersonalAccessToken
    {
        $sanctumToken = PersonalAccessToken::findToken($token);
        if ($sanctumToken instanceof PersonalAccessToken) {
            return $sanctumToken;
        }

        return null;
    }
}
