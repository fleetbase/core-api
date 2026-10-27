<?php

namespace Fleetbase\Http\Controllers\OAuthServer;

use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Auth\OAuthServer\Repositories\ClientRepository;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\OAuthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /oauth/register: dynamic client registration (RFC 7591), for software installed on many sites.
 *
 * A plugin installed on a merchant's own domain cannot share one registered redirect URI, so each
 * install registers a public PKCE client for its own. Only software listed under
 * `oauth-server.registration.software` may register, with the scopes listed there; the request
 * names the software and exactly one redirect URI, nothing else is taken from it. The client is
 * never official and is named after the redirect host, so the consent screen always shows which
 * site is asking. Registering again with the same software and redirect URI returns the same
 * client.
 */
class RegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if (!config('oauth-server.registration.enabled', true)) {
            return static::error('registration_disabled', 'Client registration is turned off on this server.', 404);
        }

        $softwareId = $request->input('software_id');
        $software   = is_string($softwareId) ? config('oauth-server.registration.software.' . $softwareId) : null;
        if (!is_array($software) || empty($software['name'])) {
            return static::error('invalid_client_metadata', 'Unknown `software_id`. Only software Fleetbase lists can register.');
        }

        $uris = $request->input('redirect_uris');
        if (!is_array($uris) || count($uris) !== 1 || !is_string($uris[0] ?? null)) {
            return static::error('invalid_redirect_uri', 'Register exactly one redirect URI.');
        }

        $uri      = $uris[0];
        $loopback = OAuthClient::isLoopbackUri($uri);
        if (!OAuthClient::isAcceptableRedirectUri($uri, $loopback)) {
            return static::error('invalid_redirect_uri', 'The redirect URI must use https, or http on a loopback address, and have no fragment.');
        }

        $key    = OAuthClient::registrationKey($softwareId, $uri);
        $client = OAuthClient::where('registration_key', $key)->first();
        if ($client?->isDisabled()) {
            return static::error('invalid_client_metadata', 'This site\'s registration was turned off. Contact Fleetbase support.');
        }

        $created = false;
        if (!$client) {
            $scopes = array_values(array_filter((array) ($software['scopes'] ?? []), fn ($scope) => is_string($scope) && OAuthScopes::has($scope)));
            $host   = (string) parse_url($uri, PHP_URL_HOST);

            $client = new OAuthClient([
                'name'             => Str::limit($software['name'] . ' (' . $host . ')', 250, ''),
                'description'      => $software['description'] ?? null,
                'redirect_uris'    => [$uri],
                'allowed_scopes'   => $scopes,
                'logo_url'         => $software['logo_url'] ?? null,
                'website_url'      => $software['website_url'] ?? null,
                'privacy_url'      => $software['privacy_url'] ?? null,
                'terms_url'        => $software['terms_url'] ?? null,
                'official'         => false,
                'development'      => $loopback,
                'software_id'      => $softwareId,
                'registration_key' => $key,
            ]);
            $client->save();
            $created = true;
        }

        return new JsonResponse(static::metadata($client), $created ? 201 : 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * The client information response (RFC 7591 §3.2.1).
     *
     * @return array<string, mixed>
     */
    public static function metadata(OAuthClient $client): array
    {
        return [
            'client_id'                  => $client->id,
            'client_id_issued_at'        => $client->created_at?->getTimestamp(),
            'client_name'                => $client->name,
            'software_id'                => $client->software_id,
            'redirect_uris'              => array_values($client->redirect_uris ?? []),
            'grant_types'                => ClientRepository::GRANT_TYPES,
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope'                      => implode(' ', $client->allowed_scopes ?? []),
        ];
    }

    protected static function error(string $code, string $description, int $status = 400): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'error_description' => $description], $status, ['Cache-Control' => 'no-store']);
    }
}
