<?php

namespace Fleetbase\Auth;

use Google_Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;

class GoogleVerifier
{
    public static function verifyIdToken(string $idToken, string $clientId): ?array
    {
        $client = static::createGoogleClient($clientId);

        if (config('app.debug') === true || app()->environment('development')) {
            $httpClient = new GuzzleClient([
                'verify' => false,
            ]);
            $client->setHttpClient($httpClient);
        }

        try {
            // Verify the ID token
            $payload = $client->verifyIdToken($idToken);

            if ($payload) {
                return $payload;
            }

            return null;
        } catch (\Exception $e) {
            logger()->error('Google ID Token verification failed: ' . $e->getMessage());

            return null;
        }
    }

    protected static function createGoogleClient(string $clientId): GoogleClient
    {
        return new GoogleClient(['client_id' => $clientId]);
    }
}
