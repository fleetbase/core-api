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

    /*
     * Dynamic client registration (RFC 7591), for software installed on many sites.
     *
     * A plugin such as Fleetbase for WooCommerce runs on every merchant's own domain, so it cannot
     * share one registered redirect URI. Each install registers its own public PKCE client at
     * POST /oauth/register instead, naming the software it is and its one redirect URI.
     *
     * Only the software listed here can register, and only with the scopes listed for it. The
     * client is named "{name} ({redirect host})" and is never marked official, so the consent
     * screen always shows which site is asking. Registering again with the same software and
     * redirect URI returns the same client. Clients that never get a grant are pruned after
     * `unused_client_hours`.
     */
    'registration' => [
        'enabled'             => env('OAUTH_SERVER_REGISTRATION_ENABLED', true),
        'unused_client_hours' => (int) env('OAUTH_SERVER_REGISTRATION_UNUSED_HOURS', 24),
        'software'            => [
            'fleetbase-woocommerce' => [
                'name'        => 'Fleetbase for WooCommerce',
                'description' => 'Live delivery quotes, order sync and tracking for a WooCommerce store.',
                'website_url' => 'https://github.com/fleetbase/woocommerce',
                'logo_url'    => env('OAUTH_SERVER_WOOCOMMERCE_LOGO_URL'),
                'scopes'      => ['organization:read', 'api-key:create'],
            ],
        ],
    ],
];
