<?php

namespace Fleetbase\Http\Controllers\OAuthServer;

use Fleetbase\Auth\OAuthServer\OAuthClaims;
use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Middleware\AuthenticateOAuthToken;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Support\AppReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Endpoints an app calls with an access token from "Continue with Fleetbase".
 *
 * Each acts only on the organization the token is bound to, never on the user's current
 * organization in the console.
 */
class ResourceController extends Controller
{
    /**
     * GET|POST /oauth/userinfo (scope: openid).
     */
    public function userinfo(Request $request): JsonResponse
    {
        $token = AuthenticateOAuthToken::token($request);

        return new JsonResponse(OAuthClaims::forUser($token->user, $token->scopes ?? [], $token->company), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * GET /oauth/v1/organization (scope: organization:read).
     */
    public function organization(Request $request): JsonResponse
    {
        $company = AuthenticateOAuthToken::company($request);

        return new JsonResponse(['organization' => static::organizationPayload($company)], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * POST /oauth/v1/api-key (scope: api-key:create).
     *
     * The live Fleetbase API key (`flb_live_…`) for this app in the token's organization. Creates
     * one, named after the app, or returns the one this app created before; a key someone else
     * created is never returned, whatever its name. It is listed under Developers → API Keys, and
     * revoking the app's last grant in the organization expires it.
     *
     * An app that serves many installations from one client (one Shopify app, many shops) passes
     * `installation`, e.g. "store.myshopify.com": each installation gets its own key, named
     * "{app} ({installation})". Without it the app has one key per organization.
     */
    public function apiKey(Request $request): JsonResponse
    {
        $token        = AuthenticateOAuthToken::token($request);
        $company      = AuthenticateOAuthToken::company($request);
        $client       = $token->client;
        $installation = static::installation($request);
        if ($installation === false) {
            return static::invalidInstallation();
        }

        // ApiCredential decides test mode from this console header; a key minted here is always live.
        $request->headers->remove('Access-Console-Sandbox');

        $credential = static::mintedKeys($company->uuid, $client->id, $installation)->where('test_mode', false)->first();

        $created = false;
        if (!$credential) {
            $credential = ApiCredential::create([
                'company_uuid'       => $company->uuid,
                'user_uuid'          => $token->user_uuid,
                'name'               => $installation === null ? $client->name : Str::limit($client->name . ' (' . $installation . ')', 250, ''),
                'oauth_client_id'    => $client->id,
                'oauth_installation' => $installation,
                'test_mode'          => false,
            ]);
            $created = true;
        }

        $credential = $credential->fresh() ?? $credential;

        return new JsonResponse([
            'api_key' => [
                'id'           => $credential->public_id ?? $credential->uuid,
                'name'         => $credential->name,
                'key'          => $credential->key,
                'installation' => $installation,
                'created'      => $created,
                'created_at'   => $credential->created_at,
            ],
        ], $created ? 201 : 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * DELETE /oauth/v1/api-key (scope: api-key:create).
     *
     * Expires the key this app minted in the token's organization for one installation (or the
     * app-wide key when `installation` is omitted), without touching the grant or other
     * installations' keys. Idempotent: 204 whether or not there was a key.
     */
    public function revokeApiKey(Request $request): JsonResponse|Response
    {
        $token        = AuthenticateOAuthToken::token($request);
        $company      = AuthenticateOAuthToken::company($request);
        $installation = static::installation($request);
        if ($installation === false) {
            return static::invalidInstallation();
        }

        static::mintedKeys($company->uuid, $token->client->id, $installation)
            ->get()
            ->each(fn (ApiCredential $credential) => $credential->forceFill(['expires_at' => now()])->save());

        return new Response('', 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * The unexpired keys an app minted in an organization for one installation (null = app-wide).
     */
    protected static function mintedKeys(string $companyUuid, string $clientId, ?string $installation)
    {
        return ApiCredential::where('company_uuid', $companyUuid)
            ->where('oauth_client_id', $clientId)
            ->when(
                $installation === null,
                fn ($query) => $query->whereNull('oauth_installation'),
                fn ($query) => $query->where('oauth_installation', $installation)
            )
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * The optional `installation`: null when absent or blank, false when it isn't a usable string.
     */
    protected static function installation(Request $request): string|false|null
    {
        $installation = $request->input('installation');
        if ($installation === null) {
            return null;
        }
        if (!is_string($installation) || mb_strlen(trim($installation)) > 190 || preg_match('/[\x00-\x1F\x7F]/', $installation)) {
            return false;
        }

        $installation = trim($installation);

        return $installation === '' ? null : $installation;
    }

    protected static function invalidInstallation(): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_request', 'error_description' => '`installation` must be a string of at most 190 characters.'], 400, ['Cache-Control' => 'no-store']);
    }

    /**
     * GET|PUT|DELETE /oauth/v1/review-access/{type}/{id}.
     *
     * Turns App Review sign-in on or off for something the organization owns (a store, a network,
     * the Navigator app). The scope that covers the type is checked here, because extensions
     * register which scope covers which of their types.
     *
     * - GET: the status; never the code.
     * - PUT: turn on. The 6-digit code is in the response only when this call created it (the
     *   first time, or with `regenerate_code`); it can never be read back.
     * - DELETE: turn off. The code is kept, so turning on again restores it.
     */
    public function reviewAccess(Request $request, string $type, string $id): JsonResponse
    {
        $scope = OAuthScopes::scopeForReviewAccessType($type);
        $token = AuthenticateOAuthToken::token($request);
        if (!$scope || AppReview::definition($type) === null) {
            return new JsonResponse(['error' => 'not_found', 'error_description' => "Unknown review access type `{$type}`."], 404);
        }
        if (!$token->hasScope($scope) || !$token->client->allowsScope($scope)) {
            return (new \Fleetbase\Auth\OAuthServer\Exceptions\TokenError('insufficient_scope', "This request needs the `{$scope}` scope.", 403, $scope))->toResponse();
        }

        $subject = AppReview::resolveSubject($type, $id, $token->company_uuid);
        if (!$subject) {
            return new JsonResponse(['error' => 'not_found', 'error_description' => 'Nothing with that id in this organization.'], 404);
        }

        if ($request->isMethod('GET')) {
            return new JsonResponse(['review_access' => AppReview::status($subject)], 200, ['Cache-Control' => 'no-store']);
        }

        $enabled               = !$request->isMethod('DELETE');
        [, $plainCode]         = AppReview::update($subject, $enabled, $enabled && $request->boolean('regenerate_code'), $token->user_uuid);
        $status                = AppReview::status($subject);
        if ($plainCode !== null) {
            $status['code'] = $plainCode;
        }

        return new JsonResponse(['review_access' => $status], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function organizationPayload(Company $company): array
    {
        return [
            'id'          => $company->public_id,
            'name'        => $company->name,
            'logo_url'    => $company->logo_url,
            'currency'    => $company->currency,
            'country'     => $company->country,
            'timezone'    => $company->timezone,
            'website_url' => $company->website_url,
        ];
    }
}
