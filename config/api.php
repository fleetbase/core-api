<?php

return [
    'throttle' => [
        'max_attempts' => env('THROTTLE_REQUESTS_PER_MINUTE', 120),
        'decay_minutes' => env('THROTTLE_DECAY_MINUTES', 1),
    ],
];
