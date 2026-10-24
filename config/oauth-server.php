<?php

/*
|--------------------------------------------------------------------------
| Fleetbase as an OAuth 2.0 / OpenID Connect provider
|--------------------------------------------------------------------------
|
| "Continue with Fleetbase": registered apps sign people in with their
| Fleetbase account and, with consent, act on one of their organizations.
| Not to be confused with config/oauth.php, which is Fleetbase signing
| people in WITH Google, Microsoft, GitHub and Apple.
|
*/

return [
    'enabled' => env('OAUTH_SERVER_ENABLED', true),

    /*
     * The issuer identifier (`iss`), and the base of every endpoint in the discovery document.
     * Defaults to APP_URL, e.g. https://api.fleetbase.io.
     */
    'issuer' => env('OAUTH_SERVER_ISSUER'),

    /*
     * PEM key pair for signing access tokens and ID tokens. When unset, the keys are read from
     * storage/oauth-private.key and storage/oauth-public.key (`php artisan oauth-server:keys`).
     */
    'private_key' => env('OAUTH_SERVER_PRIVATE_KEY'),
    'public_key'  => env('OAUTH_SERVER_PUBLIC_KEY'),

    /*
     * Lifetimes, as ISO 8601 durations.
     */
    'ttl' => [
        'auth_code'     => env('OAUTH_SERVER_AUTH_CODE_TTL', 'PT10M'),
        'access_token'  => env('OAUTH_SERVER_ACCESS_TOKEN_TTL', 'PT1H'),
        'refresh_token' => env('OAUTH_SERVER_REFRESH_TOKEN_TTL', 'P30D'),
        'id_token'      => env('OAUTH_SERVER_ID_TOKEN_TTL', 'PT1H'),
    ],

    /*
     * `prompt=login` re-authenticates a console session older than this many seconds.
     */
    'reauthenticate_after' => (int) env('OAUTH_SERVER_REAUTHENTICATE_AFTER', 300),

    /*
     * The console page that shows the consent screen.
     */
    'console_authorize_path' => '/oauth/authorize',
];
