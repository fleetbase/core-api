<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\OAuthGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account → Connected apps: the apps a user has authorized, and removing them.
 */
class ConnectedAppController extends Controller
{
    /**
     * GET int/v1/oauth-server/connected-apps.
     */
    public function index(Request $request): JsonResponse
    {
        $grants = OAuthGrant::active()
            ->where('user_uuid', $request->user()->uuid)
            ->with(['client', 'company:uuid,public_id,name'])
            ->latest('updated_at')
            ->get()
            ->filter(fn (OAuthGrant $grant) => $grant->client !== null)
            ->map(fn (OAuthGrant $grant) => [
                'id'  => $grant->uuid,
                'app' => [
                    'name'        => $grant->client->name,
                    'logo_url'    => $grant->client->logo_url,
                    'website_url' => $grant->client->website_url,
                    'official'    => $grant->client->official,
                    'disabled'    => $grant->client->isDisabled(),
                ],
                'organization' => $grant->company ? ['id' => $grant->company->public_id, 'name' => $grant->company->name] : null,
                'scopes'       => OAuthScopes::describe($grant->scopes ?? [], $grant->company?->name),
                'created_at'   => $grant->created_at,
                'last_used_at' => $grant->last_used_at,
            ])
            ->values();

        return new JsonResponse(['connected_apps' => $grants]);
    }

    /**
     * DELETE int/v1/oauth-server/connected-apps/{uuid}.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $grant = OAuthGrant::where('uuid', $uuid)->where('user_uuid', $request->user()->uuid)->firstOrFail();
        $grant->revoke();

        return new JsonResponse(['status' => 'ok']);
    }
}
