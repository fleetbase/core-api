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
    ],
];
