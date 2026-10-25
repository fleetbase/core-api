<?php

namespace Fleetbase\Http\Controllers\OAuthServer;

use Fleetbase\Auth\OAuthServer\OAuthClaims;
use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Middleware\AuthenticateOAuthToken;
use Fleetbase\Models\Company;
use Fleetbase\Support\AppReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
