<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '1.0.0',
        'routing' => [
            'prefix' => env('API_PREFIX'),
            'internal_prefix' => env('INTERNAL_API_PREFIX', 'int')
        ]
    ],
    'console' => [
        'path' => env('CONSOLE_PATH', '/fleetbase/console'),
        'host' => env('CONSOLE_HOST', 'fleetbase.io'),
        'subdomain' => env('CONSOLE_SUBDOMAIN'),
        'secure' => env('CONSOLE_SECURE', !app()->environment(['development', 'local']))
    ],
    'services' => [
        'ipinfo' => [
            'api_key' => env('IPINFO_API_KEY')
        ]
    ],
    'connection' => [
        'db' => env('DB_CONNECTION', 'mysql'),
        'sandbox' => env('SANDBOX_DB_CONNECTION', 'sandbox')
    ],
    'branding' => [
        'logo_url' => 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/fleetbase-logo.png',
        'icon_url' => 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/fleetbase-icon.png'
    ]
];
