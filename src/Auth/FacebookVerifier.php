<?php

namespace Fleetbase\Auth;

use GuzzleHttp\Client as GuzzleClient;

/**
 * Server-side verifier for Facebook Login access tokens.
 *
 * The Facebook JavaScript / native SDKs hand a short-lived `accessToken`
 * to the client after a successful login. The server MUST validate that
 * token before trusting any identity claim — Facebook's official guidance
 * is to call the Graph API `/debug_token` endpoint authenticated with
 * an app access token (`{app_id}|{app_secret}`) and confirm:
 *
 *   1. The returned `data.app_id` matches our configured `services.facebook.app_id`
 *   2. The token is `data.is_valid === true`
 *   3. The `data.user_id` is non-empty
 *
 * Then a follow-up GET to `/me?fields=id,email,name` returns the profile
 * fields needed for find-or-create user logic.
 *
 * The previous storefront implementation (`CustomerController::loginWithFacebook`)
 * accepted `facebookUserId` from the request body without server-side
 * verification — anyone could POST a forged identifier and impersonate
 * another Facebook user. This class closes that gap for the Console OAuth
 * flow and should be backported to the storefront controller separately.
 *
 * Config keys required:
 *   - services.facebook.app_id      — public, also accepted as request param
 *   - services.facebook.app_secret  — server-side only
 */
class FacebookVerifier
{
    private const GRAPH_API_BASE = 'https://graph.facebook.com/v18.0';

    /**
     * Verify a Facebook access token and return the verified profile.
     *
     * @return array|null `['user_id', 'email', 'name']` when valid, `null` otherwise
     */
    public static function verifyAccessToken(string $accessToken, ?string $clientAppId = null): ?array
    {
        $configuredAppId = config('services.facebook.app_id');
        $appSecret       = config('services.facebook.app_secret');

        if (!$configuredAppId || !$appSecret) {
            logger()->error('Facebook OAuth not configured — services.facebook.app_id / app_secret missing');

            return null;
        }

        // Defence-in-depth: if the client supplied an app_id, refuse to verify
        // a token claiming a different app. Stops a token issued for another
        // Facebook app from logging into this one even if Graph API debug_token
        // were ever to misreport.
        if ($clientAppId !== null && $clientAppId !== $configuredAppId) {
            logger()->warning('Facebook OAuth client app_id mismatch', [
                'expected' => $configuredAppId,
                'received' => $clientAppId,
            ]);

            return null;
        }

        $http      = self::httpClient();
        $appToken  = $configuredAppId . '|' . $appSecret;

        try {
            // Step 1: debug the user's access token using the app token.
            $debugResp = $http->get(self::GRAPH_API_BASE . '/debug_token', [
                'query' => [
                    'input_token'  => $accessToken,
                    'access_token' => $appToken,
                ],
            ]);
            $debugBody = json_decode((string) $debugResp->getBody(), true);
            $data      = data_get($debugBody, 'data');

            if (!is_array($data)) {
                logger()->warning('Facebook debug_token returned no data', ['body' => $debugBody]);

                return null;
            }
            if (data_get($data, 'is_valid') !== true) {
                logger()->info('Facebook access token reported invalid', ['data' => $data]);

                return null;
            }
            if (data_get($data, 'app_id') !== $configuredAppId) {
                logger()->warning('Facebook token issued for a different app', [
                    'expected' => $configuredAppId,
                    'actual'   => data_get($data, 'app_id'),
                ]);

                return null;
            }
            $userId = data_get($data, 'user_id');
            if (!$userId) {
                return null;
            }

            // Step 2: pull the profile (email, name) using the user's access token.
            $meResp = $http->get(self::GRAPH_API_BASE . '/me', [
                'query' => [
                    'fields'       => 'id,email,name',
                    'access_token' => $accessToken,
                ],
            ]);
            $me = json_decode((string) $meResp->getBody(), true);

            return [
                'user_id' => (string) $userId,
                'email'   => data_get($me, 'email'),
                'name'    => data_get($me, 'name'),
            ];
        } catch (\Throwable $e) {
            logger()->error('Facebook token verification failed: ' . $e->getMessage());

            return null;
        }
    }

    private static function httpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'timeout'         => 8.0,
            'connect_timeout' => 4.0,
            // In local development we routinely run behind self-signed certs
            // (mirrors the GoogleVerifier::verifyIdToken pattern).
            'verify'          => config('app.debug') !== true && app()->environment('production'),
        ]);
    }
}
