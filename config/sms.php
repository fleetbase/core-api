<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default SMS Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default SMS provider that will be used to send
    | messages. You may set this to any of the providers defined below.
    |
    | Supported: "twilio", "callpro"
    |
    */

    'default_provider' => env('SMS_DEFAULT_PROVIDER', 'twilio'),

    /*
    |--------------------------------------------------------------------------
    | SMS Provider Routing Rules
    |--------------------------------------------------------------------------
    |
    | Define routing rules based on phone number prefixes. When a phone number
    | matches a prefix, it will be routed to the specified provider.
    |
    | Format: 'prefix' => 'provider'
    |
    | Example:
    | '+976' => 'callpro',  // Mongolia numbers route to CallPro
    | '+1' => 'twilio',     // USA/Canada numbers route to Twilio
    |
    */

    'routing_rules' => [
        '+976' => 'callpro',  // Mongolia
    ],

    /*
    |--------------------------------------------------------------------------
    | Throw On Error
    |--------------------------------------------------------------------------
    |
    | When set to true, SMS sending failures will throw exceptions. When false,
    | errors will be logged but the application will continue execution.
    |
    | This is useful for non-critical SMS notifications where you don't want
    | to interrupt the user flow if SMS delivery fails.
    |
    */

    'throw_on_error' => env('SMS_THROW_ON_ERROR', false),

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure the SMS providers used by your application.
    | Each provider has its own configuration options.
    |
    */

    'providers' => [
        'twilio' => [
            'enabled' => env('TWILIO_ENABLED', true),
        ],

        'callpro' => [
            'enabled' => env('CALLPRO_ENABLED', true),
        ],
    ],
];
