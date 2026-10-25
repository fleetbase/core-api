<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Auth\OAuthServer\Exceptions\AuthorizeError;
use Fleetbase\Auth\OAuthServer\OAuthOrganizations;
use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Auth\OAuthServer\OAuthServer;
use Fleetbase\Auth\OAuthServer\ValidatedAuthorization;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\OAuthGrant;
use Fleetbase\Models\User;
use Fleetbase\Notifications\OAuthAppAuthorized;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The console's consent screen for "Continue with Fleetbase".
 *
 * These run on the console session (`fleetbase.protected`), which only exists after password
 * and two-factor checks, so consent inherits the console's account rules. The authorization
 * request's parameters travel with every call and are checked again each time; nothing about
 * a pending request is stored on the server until the user approves it.
 */
class OAuthConsentController extends Controller
{
    public function __construct(protected OAuthServer $server)
    {
    }

    /**
     * GET int/v1/oauth-server/authorization?{authorization request}.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $authorization = $this->server->validateAuthorizationRequest($this->authorizationParams($request->query()));
        } catch (AuthorizeError $e) {
            return $this->error($e);
        }

        if ($this->server->needsReauthentication($authorization, $this->authTime($request))) {
            return new JsonResponse(['reauthenticate' => true]);
        }

        $client        = $authorization->client;
        $organizations = $authorization->requiresOrganization() ? OAuthOrganizations::forPicker($user) : collect();
        $previous      = OAuthGrant::active()->where('user_uuid', $user->uuid)->where('client_id', $client->id)->whereNotNull('company_uuid')->latest('updated_at')->first();
        $selected      = $organizations->firstWhere('uuid', $previous?->company_uuid) ?? $organizations->firstWhere('uuid', $user->company_uuid);

        return new JsonResponse([
            'client' => [
                'id'            => $client->id,
                'name'          => $client->name,
                'description'   => $client->description,
                'logo_url'      => $client->logo_url,
                'website_url'   => $client->website_url,
                'privacy_url'   => $client->privacy_url,
                'terms_url'     => $client->terms_url,
                'official'      => $client->official,
                'redirect_host' => parse_url($authorization->redirectUri, PHP_URL_HOST),
            ],
            'user' => [
                'name'       => $user->name,
                'email'      => $user->email,
                'avatar_url' => $user->avatar_url,
            ],
            'scopes'                => $this->scopeTemplates($authorization),
            'requires_organization' => $authorization->requiresOrganization(),
            'organizations'         => $organizations->map(fn ($organization) => array_diff_key($organization, ['uuid' => true]))->values(),
            'selected_organization' => $selected && $selected['can_share'] ? $selected['id'] : null,
            'skip_consent'          => $this->server->canSkipConsent($authorization, $user),
        ]);
    }

    /**
     * POST int/v1/oauth-server/authorization {...authorization request, organization}.
     */
    public function approve(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $authorization = $this->server->validateAuthorizationRequest($this->authorizationParams($request->except(['organization'])));
            if ($this->server->needsReauthentication($authorization, $this->authTime($request))) {
                return new JsonResponse(['reauthenticate' => true], 409);
            }

            $company = $authorization->requiresOrganization() ? OAuthOrganizations::find($user, $request->input('organization')) : null;
            $result  = $this->server->approve($authorization, $user, $company, $this->authTime($request));
        } catch (AuthorizeError $e) {
            return $this->error($e, 422);
        }

        if ($result['created']) {
            $this->notify($user, $authorization, $company?->name);
        }

        return new JsonResponse(['redirect' => $result['redirect']]);
    }

    /**
     * DELETE int/v1/oauth-server/authorization {...authorization request}.
     */
    public function deny(Request $request): JsonResponse
    {
        try {
            $authorization = $this->server->validateAuthorizationRequest($this->authorizationParams($request->all()));
        } catch (AuthorizeError $e) {
            return $this->error($e);
        }

        return new JsonResponse(['redirect' => $this->server->deny($authorization)]);
    }

    /**
     * Only the authorization request's own parameters, whichever way the console sent them.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    protected function authorizationParams(array $input): array
    {
        return array_intersect_key($input, array_flip([
            'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'nonce',
            'code_challenge', 'code_challenge_method', 'prompt', 'max_age', 'login_hint', 'ui_locales',
        ]));
    }

    /**
     * When the console session was signed in: its Sanctum token was created after the password
     * and two-factor checks passed.
     */
    protected function authTime(Request $request): ?\DateTimeInterface
    {
        $token = method_exists($request->user(), 'currentAccessToken') ? $request->user()->currentAccessToken() : null;

        return $token?->created_at ?? null;
    }

    /**
     * Consent lines with `{organization}` left in, so the console can fill in the one picked.
     *
     * @return array<int, array{scope: string, description: string, organization: bool}>
     */
    protected function scopeTemplates(ValidatedAuthorization $authorization): array
    {
        return array_map(fn ($scope) => ['scope' => $scope] + array_intersect_key(OAuthScopes::get($scope), ['description' => true, 'organization' => true]), $authorization->scopes);
    }

    protected function error(AuthorizeError $e, int $status = 400): JsonResponse
    {
        return new JsonResponse([
            'error'             => $e->error,
            'error_description' => $e->description,
            'redirect'          => $e->redirectUrl($this->server->issuer()),
        ], $status);
    }

    protected function notify(User $user, ValidatedAuthorization $authorization, ?string $organizationName): void
    {
        try {
            $descriptions = array_column(OAuthScopes::describe($authorization->scopes, $organizationName), 'description');
            $user->notify(new OAuthAppAuthorized($authorization->client->name, $organizationName, $descriptions));
        } catch (\Throwable $e) {
            Log::warning('[Fleetbase] Could not send the app authorized notice.', ['error' => $e->getMessage()]);
        }
    }
}
