<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\OAuthClient;
use Fleetbase\Models\OAuthGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Auth Config → OAuth Apps: the apps that can offer "Continue with Fleetbase".
 *
 * System admins only (AdminRequest). A client secret appears in exactly two responses, the one
 * that registered the app and the one that rotated the secret; it is stored only as a hash.
 */
class OAuthAppController extends Controller
{
    /**
     * GET int/v1/oauth-server/apps.
     */
    public function index(AdminRequest $request): JsonResponse
    {
        $apps = OAuthClient::withCount(['grants as active_grants_count' => fn ($query) => $query->whereNull('revoked_at')])
            ->orderBy('name')
            ->get();

        return new JsonResponse(['apps' => $apps, 'scopes' => $this->scopes()]);
    }

    /**
     * GET int/v1/oauth-server/apps/{id}.
     */
    public function show(AdminRequest $request, string $id): JsonResponse
    {
        return new JsonResponse(['app' => OAuthClient::findOrFail($id), 'scopes' => $this->scopes()]);
    }

    /**
     * POST int/v1/oauth-server/apps.
     */
    public function store(AdminRequest $request): JsonResponse
    {
        $attributes = $this->validated($request->all());
        $client     = new OAuthClient($attributes + ['created_by_uuid' => $request->user()->uuid]);
        $secret     = ($attributes['confidential'] ?? true) ? $client->issueSecret() : null;
        $client->save();

        return new JsonResponse(['app' => $client->fresh(), 'client_secret' => $secret], 201);
    }

    /**
     * PUT int/v1/oauth-server/apps/{id}.
     */
    public function update(AdminRequest $request, string $id): JsonResponse
    {
        $client     = OAuthClient::findOrFail($id);
        $attributes = $this->validated($request->all(), $client);
        unset($attributes['confidential']);
        $client->fill($attributes)->save();

        return new JsonResponse(['app' => $client->fresh()]);
    }

    /**
     * POST int/v1/oauth-server/apps/{id}/rotate-secret. The old secret stops working at once.
     */
    public function rotateSecret(AdminRequest $request, string $id): JsonResponse
    {
        $client = OAuthClient::findOrFail($id);
        if (!$client->isConfidential()) {
            return new JsonResponse(['errors' => ['A public app has no secret to rotate.']], 422);
        }

        $secret = $client->issueSecret();
        $client->save();

        return new JsonResponse(['app' => $client->fresh(), 'client_secret' => $secret]);
    }

    /**
     * POST int/v1/oauth-server/apps/{id}/disable. Turns the app off and revokes every grant.
     */
    public function disable(AdminRequest $request, string $id): JsonResponse
    {
        $client = OAuthClient::findOrFail($id);
        $client->forceFill(['disabled_at' => now()])->save();

        $grants = OAuthGrant::active()->where('client_id', $client->id)->pluck('uuid')->all();
        OAuthGrant::whereIn('uuid', $grants)->update(['revoked_at' => now()]);
        OAuthGrant::revokeTokensFor($grants);
        OAuthGrant::expireMintedKeysFor($grants);

        return new JsonResponse(['app' => $client->fresh()]);
    }

    /**
     * POST int/v1/oauth-server/apps/{id}/enable. Users have to authorize it again.
     */
    public function enable(AdminRequest $request, string $id): JsonResponse
    {
        $client = OAuthClient::findOrFail($id);
        $client->forceFill(['disabled_at' => null])->save();

        return new JsonResponse(['app' => $client->fresh()]);
    }

    /**
     * GET int/v1/oauth-server/apps/{id}/grants.
     */
    public function grants(AdminRequest $request, string $id): JsonResponse
    {
        $client = OAuthClient::findOrFail($id);
        $grants = OAuthGrant::active()
            ->where('client_id', $client->id)
            ->with(['user:uuid,public_id,name,email', 'company:uuid,public_id,name'])
            ->latest('updated_at')
            ->limit(500)
            ->get()
            ->map(fn (OAuthGrant $grant) => [
                'id'           => $grant->uuid,
                'user'         => $grant->user ? ['id' => $grant->user->public_id, 'name' => $grant->user->name, 'email' => $grant->user->email] : null,
                'organization' => $grant->company ? ['id' => $grant->company->public_id, 'name' => $grant->company->name] : null,
                'scopes'       => $grant->scopes,
                'created_at'   => $grant->created_at,
                'last_used_at' => $grant->last_used_at,
            ]);

        return new JsonResponse(['grants' => $grants]);
    }

    /**
     * DELETE int/v1/oauth-server/grants/{uuid}.
     */
    public function revokeGrant(AdminRequest $request, string $uuid): JsonResponse
    {
        OAuthGrant::findOrFail($uuid)->revoke();

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * @return array<int, array{scope: string, description: string, organization: bool}>
     */
    protected function scopes(): array
    {
        return OAuthScopes::describe(array_keys(OAuthScopes::all()));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validated(array $input, ?OAuthClient $client = null): array
    {
        $required  = $client ? 'sometimes' : 'required';
        $validator = Validator::make($input, [
            'name'             => [$required, 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:500'],
            'redirect_uris'    => [$required, 'array', 'min:1', 'max:20'],
            'redirect_uris.*'  => ['string', 'max:2000'],
            'allowed_scopes'   => [$required, 'array', 'min:1'],
            'allowed_scopes.*' => ['string'],
            'logo_url'         => ['nullable', 'url', 'max:2000'],
            'website_url'      => ['nullable', 'url', 'max:2000'],
            'privacy_url'      => ['nullable', 'url', 'max:2000'],
            'terms_url'        => ['nullable', 'url', 'max:2000'],
            'official'         => ['sometimes', 'boolean'],
            'development'      => ['sometimes', 'boolean'],
            'confidential'     => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($input, $client) {
            $development = (bool) ($input['development'] ?? $client?->development ?? false);
            foreach ((array) ($input['redirect_uris'] ?? []) as $uri) {
                if (!is_string($uri) || !OAuthClient::isAcceptableRedirectUri($uri, $development)) {
                    $validator->errors()->add('redirect_uris', "`{$uri}` must be an https URL without a fragment (http is allowed only for 127.0.0.1 or [::1] on a development app).");
                }
            }
            foreach ((array) ($input['allowed_scopes'] ?? []) as $scope) {
                if (!is_string($scope) || !OAuthScopes::has($scope)) {
                    $validator->errors()->add('allowed_scopes', "`{$scope}` is not a registered scope.");
                }
            }
        });

        $attributes = $validator->validate();
        if (isset($attributes['redirect_uris'])) {
            $attributes['redirect_uris'] = array_values(array_unique($attributes['redirect_uris']));
        }
        if (isset($attributes['allowed_scopes'])) {
            $attributes['allowed_scopes'] = array_values(array_unique($attributes['allowed_scopes']));
        }

        return $attributes;
    }
}
