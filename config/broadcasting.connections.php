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
        ],

        // Realtime channel authentication. Leaving SOCKETCLUSTER_AUTH_KEY unset keeps the
        // feature off: no socket tokens are minted and broadcasts use the websocket publisher.
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
