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
            'enabled'             => env('SMPP_SMS_ENABLED', false),
            'host'                => env('SMPP_HOST', ''),
            'port'                => env('SMPP_PORT', 2775),
            'tls'                 => env('SMPP_TLS', false),
            'system_id'           => env('SMPP_SYSTEM_ID', ''),
            'password'            => env('SMPP_PASSWORD', ''),
            'system_type'         => env('SMPP_SYSTEM_TYPE', ''),
            'source_addr'         => env('SMPP_SOURCE_ADDR', ''),
            'bind_type'           => env('SMPP_BIND_TYPE', 'transceiver'),
            'interface_version'   => env('SMPP_INTERFACE_VERSION', 0x34),
            'timeout'             => env('SMPP_TIMEOUT', 10),
            'addr_ton'            => env('SMPP_ADDR_TON', 0),
            'addr_npi'            => env('SMPP_ADDR_NPI', 0),
            'address_range'       => env('SMPP_ADDRESS_RANGE', ''),
            'source_addr_ton'     => env('SMPP_SOURCE_ADDR_TON', 5),
            'source_addr_npi'     => env('SMPP_SOURCE_ADDR_NPI', 0),
            'dest_addr_ton'       => env('SMPP_DEST_ADDR_TON', 1),
            'dest_addr_npi'       => env('SMPP_DEST_ADDR_NPI', 1),
            'registered_delivery' => env('SMPP_REGISTERED_DELIVERY', 1),
            'data_coding'         => env('SMPP_DATA_CODING', 0),
            'service_type'        => env('SMPP_SERVICE_TYPE', ''),
            'sm_default_msg_id'   => env('SMPP_DEFAULT_MESSAGE_ID', 0),
        ],

        'custom_http' => [
            'enabled'     => env('CUSTOM_HTTP_SMS_ENABLED', false),
            'method'      => env('CUSTOM_HTTP_SMS_METHOD', 'POST'),
            'url'         => env('CUSTOM_HTTP_SMS_URL', ''),
            'from'        => env('CUSTOM_HTTP_SMS_FROM', ''),
            'auth_header' => env('CUSTOM_HTTP_SMS_AUTH_HEADER', ''),
            'auth_token'  => env('CUSTOM_HTTP_SMS_AUTH_TOKEN', ''),
            'headers'     => [],
            'query_params' => [],
            'body'        => [
                'to'   => '{{to}}',
                'text' => '{{text}}',
                'from' => '{{from}}',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Send Guard
    |--------------------------------------------------------------------------
    |
    | Every SMS passes through Fleetbase\Support\Sms\SmsGuard before a provider
    | is called (and, for verification codes, before the code row is written).
    | The guard validates the destination with libphonenumber, enforces
    | per-destination cooldowns and caps, per-tenant and platform-wide budgets,
    | and pauses a tenant automatically when it crosses its budget or texts a
    | burst of new numbers. This is what bounds the damage of a leaked public
    | storefront key: see docs/security/storefront-sms-abuse-proposal.md.
    |
    | Everything here is a DEFAULT. A system administrator can change any of it
    | at runtime (the stored override lives in the `system.sms-guard` setting and
    | applies within a minute), so a deployment is never needed to switch modes,
    | raise a tenant's budget or pause one.
    |
    |   mode: "enforce" blocks, "monitor" only logs what would have been blocked.
    |   limits: counted in sends. null means no limit for that window.
    |   company: the default per-tenant budget; an administrator adds exceptions
    |            per company (raise, lower, unlimited).
    |   tenant: a store or network inside a company; null inherits the company's.
    |   probation: companies younger than `days` get this smaller daily budget,
    |              so a self-signup farm cannot mint a fresh budget per account.
    |   platform: enforced for all tenants together, deliberately high relative
    |             to a single tenant's cap. The "trusted" bucket is for purposes
    |             that already proved a password (2FA, admin actions).
    |
    */

    'guard' => [
        'enabled'      => env('SMS_GUARD_ENABLED', true),
        'mode'         => env('SMS_GUARD_MODE', 'enforce'),
        'cache_store'  => env('SMS_GUARD_CACHE_STORE'),
        'alert_emails' => env('SMS_GUARD_ALERT_EMAILS', ''),

        'validation' => [
            'enabled'           => env('SMS_GUARD_VALIDATE', true),
            'allowed_types'     => ['MOBILE', 'FIXED_LINE_OR_MOBILE'],
            'blocked_countries' => env('SMS_GUARD_BLOCKED_COUNTRIES', ''),
            // International freephone, shared-cost, satellite, personal-number and
            // premium ranges: the destinations that pay out per message.
            'blocked_prefixes'  => env('SMS_GUARD_BLOCKED_PREFIXES', '+800,+808,+870,+878,+881,+882,+883,+888,+979'),
        ],

        'limits' => [
            'destination' => ['cooldown_seconds' => 60, 'per_hour' => 3, 'per_day' => 5],
            'ip'          => ['per_15_minutes' => 5, 'per_day' => 20],
            'company'     => ['per_minute' => 10, 'per_hour' => 60, 'per_day' => 200],
            'tenant'      => ['per_minute' => null, 'per_hour' => null, 'per_day' => null],
            'probation'   => ['days' => 7, 'per_day' => 20],
            'platform'    => [
                'public'  => ['per_minute' => 100, 'per_hour' => 1500, 'per_day' => 10000],
                'trusted' => ['per_minute' => 100, 'per_hour' => 1500, 'per_day' => 10000],
            ],
        ],

        'breaker' => [
            'pause_minutes'                 => 60,
            'alert_percentages'             => [50, 80],
            'distinct_destinations_per_10m' => 25,
            'auto_pauses_before_manual'     => 2,
            // Sends in 10 minutes to a country the company has not texted in the last
            // 30 days, once it has at least `new_country_min_history` ledgered sends.
            'new_country_min_sends'         => 10,
            'new_country_min_history'       => 50,
            // Verification codes issued by SMS in the last hour versus codes actually
            // verified: pumping has a conversion of about zero.
            'conversion_min_issued'         => 20,
            'conversion_min_percent'        => 10,
        ],

        // The send ledger (sms_sends): every send and refusal with who, where and why.
        'ledger' => [
            'retention_days'  => env('SMS_GUARD_LEDGER_RETENTION_DAYS', 90),
            'mask_after_days' => env('SMS_GUARD_LEDGER_MASK_AFTER_DAYS', 30),
        ],

        // Per-purpose overrides, keyed by the verification code's `for` value.
        'purposes' => [
            '2fa'                     => ['trusted' => true, 'destination' => ['cooldown_seconds' => 20, 'per_hour' => 10, 'per_day' => 30]],
            'admin_test'              => ['trusted' => true],
            'admin_user_verification' => ['trusted' => true],
            'storefront_login'        => ['destination' => ['cooldown_seconds' => 30, 'per_hour' => 5, 'per_day' => 10]],
            'fleetops_customer_login' => ['destination' => ['cooldown_seconds' => 30, 'per_hour' => 5, 'per_day' => 10]],
            'driver_login'            => ['destination' => ['cooldown_seconds' => 30, 'per_hour' => 5, 'per_day' => 10]],
        ],
    ],
];
