<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */

return [
    'api' => [
        'version' => 'v1',
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
    ],
    'version' => env('FLEETBASE_VERSION', '0.7.1'),
    'instance_id' => env('FLEETBASE_INSTANCE_ID') ?? (file_exists(base_path('.fleetbase-id')) ? trim(file_get_contents(base_path('.fleetbase-id'))) : null)
];
