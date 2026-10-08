<?php

use Fleetbase\Support\Utils;

/*
|--------------------------------------------------------------------------
| Broadcast Connections
|--------------------------------------------------------------------------
|
| Here you may define all of the broadcast connections that will be used
| to broadcast events to other systems or over websockets. Samples of
| each available type of connection are provided inside this array.
|
*/

return [
    'socketcluster' => [
        'driver' => 'socketcluster',
        'options' => [
            'secure' => Utils::castBoolean(env('SOCKETCLUSTER_SECURE', false)),
            'host' => env('SOCKETCLUSTER_HOST', 'socket'),
            'port' => env('SOCKETCLUSTER_PORT', 8000),
            'path' => env('SOCKETCLUSTER_PATH', '/socketcluster/'),
            'query' => [],
            // The websocket publisher sends no Origin of its own, and a socket server whose
            // `origins` are restricted (as scripts/docker-install.sh sets them) rejects a
            // handshake without one. Set SOCKETCLUSTER_ORIGIN to an allowed origin, e.g. the
            // console URL.
            'headers' => array_filter(['Origin' => env('SOCKETCLUSTER_ORIGIN')]),
        ],

        // Realtime channel authentication. It is off unless SOCKETCLUSTER_AUTH_ENABLED is true
        // and SOCKETCLUSTER_AUTH_KEY is set: until then no socket tokens are minted and
        // broadcasts use the websocket publisher, so existing socket clients keep working.
        // Turn it on once every client fetches socket tokens.
        'auth_enabled' => Utils::castBoolean(env('SOCKETCLUSTER_AUTH_ENABLED', false)),
        'auth_key'    => env('SOCKETCLUSTER_AUTH_KEY'),
        'publish_url' => env('SOCKETCLUSTER_PUBLISH_URL', 'http://' . env('SOCKETCLUSTER_HOST', 'socket') . ':8001'),
        'token_ttl'   => (int) env('SOCKETCLUSTER_TOKEN_TTL', 900),
    ],

    // for apple apn
    'apn' => [
        'key_id' => env('APN_KEY_ID'),
        'team_id' => env('APN_TEAM_ID'),
        'app_bundle_id' => env('APN_BUNDLE_ID'),
        'private_key_content' => env('APN_PRIVATE_KEY'),
        'production' => env('APN_PRODUCTION', true),
    ],
];
