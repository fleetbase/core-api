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
];
