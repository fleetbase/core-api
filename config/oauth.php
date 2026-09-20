<?php

use Fleetbase\Auth\OAuth\Drivers\AppleDriver;
use Fleetbase\Auth\OAuth\Drivers\GithubDriver;
use Fleetbase\Auth\OAuth\Drivers\GoogleDriver;
use Fleetbase\Auth\OAuth\Drivers\MicrosoftDriver;

/*
|--------------------------------------------------------------------------
| OAuth / OIDC sign-in
|--------------------------------------------------------------------------
|
| Fleetbase resolves every value here from the database first (the admin
| settings UI writes `system.oauth`) and falls back to these env-backed
| defaults. That ordering is what lets a self-hosted operator configure
| providers entirely through the environment, while a Fleetbase Cloud admin
| configures them through the console.
|
| Secrets set through the admin UI are stored encrypted. Secrets set through
| the environment are read as-is — the environment is already trusted.
|
| These values are read exclusively through OAuthConfigRepository, never with
| a bare env() call: `config:cache` is in active use, and env() returns null
| under a cached config.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Global switches
    |--------------------------------------------------------------------------
    |
    | `enabled` is the kill switch for the whole feature. `allow_registration`
    | separately controls whether an unrecognised provider identity may start a
    | Fleetbase signup, so an operator can offer OAuth sign-in to existing users
    | without opening self-service registration.
    |
    */
    'enabled'            => env('OAUTH_ENABLED', true),
    'allow_registration' => env('OAUTH_ALLOW_REGISTRATION', true),

    /*
    |--------------------------------------------------------------------------
    | Console landing path
    |--------------------------------------------------------------------------
    |
    | Where the callback sends the browser once the provider handshake is done.
    | The host is always taken from the console configuration — never from the
    | request — so this is a path, not a URL.
    |
    */
    'console_callback_path' => env('OAUTH_CONSOLE_CALLBACK_PATH', '/auth/oauth/callback'),

    /*
    |--------------------------------------------------------------------------
    | Redirect base
    |--------------------------------------------------------------------------
    |
    | The public origin of this API, used to build the redirect_uri handed to
    | providers. Defaults to app.url. It must match what is registered in each
    | provider's console byte for byte.
    |
    */
    'redirect_base' => env('OAUTH_REDIRECT_BASE'),

    /*
    |--------------------------------------------------------------------------
    | Strict IP binding
    |--------------------------------------------------------------------------
    |
    | Off by default: a phone that moves between wifi and cellular mid-flow
    | legitimately changes address, and failing those users closed costs more
    | than this binding is worth. Operators who can guarantee stable addressing
    | can turn it into a hard failure.
    |
    */
    'strict_ip_binding' => env('OAUTH_STRICT_IP_BINDING', false),

    /*
    |--------------------------------------------------------------------------
    | Token lifetimes (seconds)
    |--------------------------------------------------------------------------
    |
    | authorization — the provider round trip. Generous: a user may have to
    |                 complete MFA at the provider.
    | handoff       — callback to console exchange. Deliberately tight; the
    |                 browser redeems it immediately.
    | registration_intent — how long a verified identity may sit unused while
    |                 the user fills in the signup wizard.
    |
    */
    'ttl' => [
        'authorization'       => (int) env('OAUTH_TTL_AUTHORIZATION', 600),
        'handoff'             => (int) env('OAUTH_TTL_HANDOFF', 120),
        'registration_intent' => (int) env('OAUTH_TTL_REGISTRATION_INTENT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Adding a provider later means: one class under Auth/OAuth/Drivers, one
    | Socialite subclass if Socialite core does not ship the protocol, and one
    | entry here. No route, controller, migration or console change — the
    | registry, the {provider} route parameter and the admin UI schema are all
    | driven off this map and the driver's configSchema().
    |
    */
    'providers' => [
        'google' => [
            'driver'        => GoogleDriver::class,
            'enabled'       => env('OAUTH_GOOGLE_ENABLED', false),
            'client_id'     => env('OAUTH_GOOGLE_CLIENT_ID'),
            'client_secret' => env('OAUTH_GOOGLE_CLIENT_SECRET'),
            // Restrict sign-in to a Google Workspace domain. Enforced server side
            // against the verified `hd` claim, not just sent as a request hint.
            'hosted_domain' => env('OAUTH_GOOGLE_HOSTED_DOMAIN'),
        ],

        'microsoft' => [
            'driver'        => MicrosoftDriver::class,
            'enabled'       => env('OAUTH_MICROSOFT_ENABLED', false),
            'client_id'     => env('OAUTH_MICROSOFT_CLIENT_ID'),
            'client_secret' => env('OAUTH_MICROSOFT_CLIENT_SECRET'),
            // 'common' accepts both work/school and personal accounts. A tenant
            // id or domain restricts sign-in to that tenant — and is what makes
            // the provider's email assertion trustworthy. See MicrosoftDriver.
            'tenant'        => env('OAUTH_MICROSOFT_TENANT', 'common'),
        ],

        'github' => [
            'driver'        => GithubDriver::class,
            'enabled'       => env('OAUTH_GITHUB_ENABLED', false),
            'client_id'     => env('OAUTH_GITHUB_CLIENT_ID'),
            'client_secret' => env('OAUTH_GITHUB_CLIENT_SECRET'),
        ],

        'apple' => [
            'driver'  => AppleDriver::class,
            'enabled' => env('OAUTH_APPLE_ENABLED', false),
            // The Services ID, e.g. io.fleetbase.console — not the app bundle id.
            'client_id'   => env('OAUTH_APPLE_CLIENT_ID'),
            'team_id'     => env('OAUTH_APPLE_TEAM_ID'),
            'key_id'      => env('OAUTH_APPLE_KEY_ID'),
            // The contents of the .p8 signing key. Apple has no static client
            // secret; one is minted as a short-lived ES256 JWT from this key.
            'private_key' => env('OAUTH_APPLE_PRIVATE_KEY'),
        ],
    ],
];
