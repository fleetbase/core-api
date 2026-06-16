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
    | Supported: "twilio", "callpro", "vonage", "messagebird", "aws_sns",
    |            "smpp", "custom_http"
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

        'vonage' => [
            'enabled'    => env('VONAGE_SMS_ENABLED', false),
            'api_key'    => env('VONAGE_API_KEY', ''),
            'api_secret' => env('VONAGE_API_SECRET', ''),
            'from'       => env('VONAGE_SMS_FROM', ''),
            'base_url'   => env('VONAGE_SMS_BASE_URL', 'https://rest.nexmo.com/sms/json'),
        ],

        'messagebird' => [
            'enabled'    => env('MESSAGEBIRD_SMS_ENABLED', false),
            'access_key' => env('MESSAGEBIRD_ACCESS_KEY', ''),
            'originator' => env('MESSAGEBIRD_ORIGINATOR', ''),
            'base_url'   => env('MESSAGEBIRD_SMS_BASE_URL', 'https://rest.messagebird.com/messages'),
        ],

        'aws_sns' => [
            'enabled'   => env('AWS_SNS_SMS_ENABLED', false),
            'key'       => env('AWS_SNS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret'    => env('AWS_SNS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region'    => env('AWS_SNS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'sender_id' => env('AWS_SNS_SMS_SENDER_ID', ''),
            'sms_type'  => env('AWS_SNS_SMS_TYPE', 'Transactional'),
        ],

        'smpp' => [
            'enabled'           => env('SMPP_SMS_ENABLED', false),
            'host'              => env('SMPP_HOST', ''),
            'port'              => env('SMPP_PORT', 2775),
            'tls'               => env('SMPP_TLS', false),
            'system_id'         => env('SMPP_SYSTEM_ID', ''),
            'password'          => env('SMPP_PASSWORD', ''),
            'system_type'       => env('SMPP_SYSTEM_TYPE', ''),
            'source_addr'       => env('SMPP_SOURCE_ADDR', ''),
            'bind_type'         => env('SMPP_BIND_TYPE', 'transceiver'),
            'interface_version' => env('SMPP_INTERFACE_VERSION', 0x34),
            'timeout'           => env('SMPP_TIMEOUT', 10),
        ],

        'custom_http' => [
            'enabled'     => env('CUSTOM_HTTP_SMS_ENABLED', false),
            'url'         => env('CUSTOM_HTTP_SMS_URL', ''),
            'from'        => env('CUSTOM_HTTP_SMS_FROM', ''),
            'auth_header' => env('CUSTOM_HTTP_SMS_AUTH_HEADER', ''),
            'auth_token'  => env('CUSTOM_HTTP_SMS_AUTH_TOKEN', ''),
            'headers'     => [],
            'body'        => [
                'to'   => '{{to}}',
                'text' => '{{text}}',
                'from' => '{{from}}',
            ],
        ],
    ],
];
