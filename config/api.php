<?php

return [
    'throttle' => [
        // Option 1: Global enable/disable toggle
        // Set to false to disable throttling completely (useful for performance testing)
        // Default: true (enabled)
        // Example: THROTTLE_ENABLED=false
        'enabled' => env('THROTTLE_ENABLED', true),
        
        // Maximum number of requests allowed per decay period
        // Default: 120 requests per minute
        // Example: THROTTLE_REQUESTS_PER_MINUTE=120
        'max_attempts' => env('THROTTLE_REQUESTS_PER_MINUTE', 120),
        
        // Time window in minutes for throttle decay
        // Default: 1 minute
        // Example: THROTTLE_DECAY_MINUTES=1
        'decay_minutes' => env('THROTTLE_DECAY_MINUTES', 1),
        
        // Option 3: Unlimited API keys (for production testing)
        // Comma-separated list of API keys that bypass throttling
        // These keys can be used for performance testing in production
        // Example: THROTTLE_UNLIMITED_API_KEYS=Bearer test_key_123,Bearer load_test_456
        'unlimited_keys' => array_filter(explode(',', env('THROTTLE_UNLIMITED_API_KEYS', ''))),
    ],

    'cache' => [
        // Enable/disable API model caching
        // Set to true to enable caching for API queries and models
        // Default: false (disabled)
        // Example: API_CACHE_ENABLED=true
        'enabled' => env('API_CACHE_ENABLED', false),

        // Cache TTL (Time To Live) in seconds
        'ttl' => [
            // Query result caching (list endpoints)
            // Default: 300 seconds (5 minutes)
            // Example: API_CACHE_QUERY_TTL=300
            'query' => env('API_CACHE_QUERY_TTL', 300),

            // Model instance caching (single record endpoints)
            // Default: 3600 seconds (1 hour)
            // Example: API_CACHE_MODEL_TTL=3600
            'model' => env('API_CACHE_MODEL_TTL', 3600),

            // Relationship caching
            // Default: 1800 seconds (30 minutes)
            // Example: API_CACHE_RELATIONSHIP_TTL=1800
            'relationship' => env('API_CACHE_RELATIONSHIP_TTL', 1800),
        ],

        // Cache driver (uses Laravel's cache configuration)
        // Options: redis, memcached, database, file
        // Default: uses config('cache.default')
        'driver' => env('API_CACHE_DRIVER', config('cache.default')),

        // Cache key prefix
        // Default: 'fleetbase_api'
        'prefix' => env('API_CACHE_PREFIX', 'fleetbase_api'),
    ],
];
