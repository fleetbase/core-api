<?php

namespace Fleetbase\Services;

use Fleetbase\Twilio\Support\Laravel\Facade as Twilio;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmsService
{
    /**
     * Available SMS providers.
     */
    public const PROVIDER_TWILIO      = 'twilio';
    public const PROVIDER_CALLPRO     = 'callpro';
    public const PROVIDER_VONAGE      = 'vonage';
    public const PROVIDER_MESSAGEBIRD = 'messagebird';
    public const PROVIDER_AWS_SNS     = 'aws_sns';
    public const PROVIDER_SMPP        = 'smpp';
    public const PROVIDER_CUSTOM_HTTP = 'custom_http';

    /**
     * Default SMS provider.
     */
    protected string $defaultProvider;

    /**
     * Provider routing rules based on phone number prefixes.
     */
    protected array $routingRules = [];

    /**
     * Create a new SmsService instance.
     */
    public function __construct()
    {
        $this->defaultProvider = config('sms.default_provider', self::PROVIDER_TWILIO);

        // Load routing rules from config or use defaults
        $this->routingRules = config('sms.routing_rules', [
            '+976' => self::PROVIDER_CALLPRO, // Mongolia numbers route to CallPro
        ]);

        Log::info('SmsService initialized', [
            'default_provider' => $this->defaultProvider,
            'routing_rules'    => $this->routingRules,
        ]);
    }

    /**
     * Send an SMS message with automatic provider selection.
     *
     * @param string      $to       Recipient phone number
     * @param string      $text     Message text
     * @param array       $options  Additional options (from, provider, twilioParams, etc.)
     * @param string|null $provider Explicitly specify provider (overrides auto-routing)
     *
     * @return array Response containing status and provider information
     *
     * @throws \Exception If SMS sending fails
     */
    public function send(string $to, string $text, array $options = [], ?string $provider = null): array
    {
        // Normalize phone number
        $normalizedPhone = $this->normalizePhoneNumber($to);

        // Determine provider
        $selectedProvider = $provider ?? $this->determineProvider($normalizedPhone);

        Log::info('Sending SMS', [
            'to'       => $to,
            'provider' => $selectedProvider,
            'text'     => substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''),
        ]);

        try {
            $result = match ($selectedProvider) {
                self::PROVIDER_CALLPRO     => $this->sendViaCallPro($normalizedPhone, $text, $options),
                self::PROVIDER_TWILIO      => $this->sendViaTwilio($normalizedPhone, $text, $options),
                self::PROVIDER_VONAGE      => $this->sendViaVonage($normalizedPhone, $text, $options),
                self::PROVIDER_MESSAGEBIRD => $this->sendViaMessageBird($normalizedPhone, $text, $options),
                self::PROVIDER_AWS_SNS     => $this->sendViaAwsSns($normalizedPhone, $text, $options),
                self::PROVIDER_SMPP        => $this->sendViaSmpp($normalizedPhone, $text, $options),
                self::PROVIDER_CUSTOM_HTTP => $this->sendViaCustomHttp($normalizedPhone, $text, $options),
                default                    => throw new \InvalidArgumentException("Unsupported SMS provider: {$selectedProvider}"),
            };

            $result['provider'] = $selectedProvider;

            Log::info('SMS sent successfully', [
                'provider' => $selectedProvider,
                'to'       => $to,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('SMS sending failed', [
                'provider' => $selectedProvider,
                'to'       => $to,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send SMS via CallPro/MessagePro.mn.
     *
     * @param string $to      Recipient phone number
     * @param string $text    Message text
     * @param array  $options Additional options
     *
     * @return array Response from CallPro service
     *
     * @throws \Exception If CallPro is not configured or sending fails
     */
    protected function sendViaCallPro(string $to, string $text, array $options = []): array
    {
        $callProService = new CallProSmsService();

        if (!$callProService->isConfigured()) {
            Log::warning('CallPro not configured, falling back to Twilio');

            return $this->sendViaTwilio($to, $text, $options);
        }

        // Keep Mongolia routing backward compatible while allowing documented international format.
        $toNumber = $this->extractCallProNumber($to);

        // CallPro does NOT support alphanumeric sender IDs (Twilio-specific)
        // Only pass 'from' if it's a valid 8-digit number, otherwise use CallPro default
        $from = data_get($options, 'from');
        if ($from && (strlen($from) !== 8 || !ctype_digit($from))) {
            Log::debug('Ignoring Twilio-specific sender ID for CallPro', [
                'sender_id' => $from,
            ]);
            $from = null; // Let CallPro use its configured default
        }

        $callProOptions = array_filter([
            'brand'     => data_get($options, 'brand'),
            'unique_id' => data_get($options, 'unique_id'),
        ], static fn ($value) => $value !== null && $value !== '');

        return $callProService->send($toNumber, $text, $from, $callProOptions);
    }

    /**
     * Send SMS via Twilio.
     *
     * @param string $to      Recipient phone number
     * @param string $text    Message text
     * @param array  $options Additional options (twilioParams, from, etc.)
     *
     * @return array Response from Twilio service
     *
     * @throws \Exception If Twilio sending fails
     */
    protected function sendViaTwilio(string $to, string $text, array $options = []): array
    {
        $twilioParams = data_get($options, 'twilioParams', []);

        // Support 'from' in options
        if (isset($options['from']) && !isset($twilioParams['from'])) {
            $twilioParams['from'] = $options['from'];
        }

        try {
            $response = Twilio::message($to, $text, [], $twilioParams);

            return [
                'success'    => true,
                'message_id' => $response->sid ?? null,
                'result'     => 'SUCCESS',
                'response'   => $response,
            ];
        } catch (\Throwable $e) {
            // return [
            //     'success' => false,
            //     'error'   => $e->getMessage(),
            //     'code'    => $e->getCode(),
            // ];

            throw $e;
        }
    }

    /**
     * Send SMS via Vonage.
     */
    protected function sendViaVonage(string $to, string $text, array $options = []): array
    {
        $service = new VonageSmsService();

        return $service->send($to, $text, data_get($options, 'from'), $options);
    }

    /**
     * Send SMS via MessageBird.
     */
    protected function sendViaMessageBird(string $to, string $text, array $options = []): array
    {
        $service = new MessageBirdSmsService();

        return $service->send($to, $text, data_get($options, 'from', data_get($options, 'originator')), $options);
    }

    /**
     * Send SMS via AWS SNS.
     */
    protected function sendViaAwsSns(string $to, string $text, array $options = []): array
    {
        $service = new AwsSnsSmsService();

        return $service->send($to, $text, data_get($options, 'from', data_get($options, 'sender_id')), $options);
    }

    /**
     * Send SMS via SMPP gateway.
     */
    protected function sendViaSmpp(string $to, string $text, array $options = []): array
    {
        $service = new SmppSmsService();

        return $service->send($to, $text, data_get($options, 'from', data_get($options, 'source_addr')), $options);
    }

    /**
     * Send SMS via custom HTTP gateway.
     */
    protected function sendViaCustomHttp(string $to, string $text, array $options = []): array
    {
        $service = new CustomHttpSmsService();

        return $service->send($to, $text, data_get($options, 'from'), $options);
    }

    /**
     * Determine which provider to use based on phone number.
     *
     * @param string $phoneNumber Normalized phone number
     *
     * @return string Provider identifier
     */
    protected function determineProvider(string $phoneNumber): string
    {
        // Check routing rules
        foreach ($this->routingRules as $prefix => $provider) {
            if (Str::startsWith($phoneNumber, $prefix)) {
                Log::debug('Phone number matched routing rule', [
                    'phone'    => $phoneNumber,
                    'prefix'   => $prefix,
                    'provider' => $provider,
                ]);

                return $provider;
            }
        }

        // Return default provider
        return $this->defaultProvider;
    }

    /**
     * Normalize phone number by removing formatting characters.
     *
     * Fleetbase already ensures all phone numbers are in E.164 format with + prefix,
     * so we just need to strip out any formatting characters.
     *
     * @param string $phoneNumber Phone number (already in E.164 format)
     *
     * @return string Normalized phone number
     */
    protected function normalizePhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters except +
        // Fleetbase already normalizes to E.164 format, so just clean formatting
        return preg_replace('/[^0-9+]/', '', $phoneNumber);
    }

    /**
     * Extract 8-digit number for CallPro (Mongolia format).
     *
     * @param string $phoneNumber Full phone number
     *
     * @return string 8-digit number
     */
    protected function extractCallProNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);

        if (strlen($digits) === 11 && str_starts_with($digits, '976')) {
            return substr($digits, -8);
        }

        return $digits;
    }

    /**
     * Get available providers.
     *
     * @return array List of available providers
     */
    public function getAvailableProviders(): array
    {
        $providers = [
            self::PROVIDER_TWILIO => [
                'name'      => 'Twilio',
                'available' => true, // Twilio is always available if configured
            ],
        ];

        $callProService                    = new CallProSmsService();
        $providers[self::PROVIDER_CALLPRO] = [
            'name'      => 'CallPro/MessagePro.mn',
            'available' => $callProService->isConfigured(),
        ];

        $providers[self::PROVIDER_VONAGE] = [
            'name'      => 'Vonage',
            'available' => (new VonageSmsService())->isConfigured(),
        ];

        $providers[self::PROVIDER_MESSAGEBIRD] = [
            'name'      => 'MessageBird',
            'available' => (new MessageBirdSmsService())->isConfigured(),
        ];

        $providers[self::PROVIDER_AWS_SNS] = [
            'name'      => 'AWS SNS',
            'available' => (new AwsSnsSmsService())->isConfigured(),
        ];

        $providers[self::PROVIDER_SMPP] = [
            'name'      => 'SMPP Gateway',
            'available' => (new SmppSmsService())->isConfigured(),
        ];

        $providers[self::PROVIDER_CUSTOM_HTTP] = [
            'name'      => 'Custom HTTP Gateway',
            'available' => (new CustomHttpSmsService())->isConfigured(),
        ];

        return $providers;
    }

    /**
     * Add a routing rule for phone number prefix.
     *
     * @param string $prefix   Phone number prefix (e.g., '+976')
     * @param string $provider Provider identifier
     */
    public function addRoutingRule(string $prefix, string $provider): void
    {
        $this->routingRules[$prefix] = $provider;

        Log::info('Routing rule added', [
            'prefix'   => $prefix,
            'provider' => $provider,
        ]);
    }

    /**
     * Get current routing rules.
     *
     * @return array Routing rules
     */
    public function getRoutingRules(): array
    {
        return $this->routingRules;
    }

    /**
     * Set default provider.
     *
     * @param string $provider Provider identifier
     */
    public function setDefaultProvider(string $provider): void
    {
        $this->defaultProvider = $provider;

        Log::info('Default provider changed', ['provider' => $provider]);
    }

    /**
     * Get default provider.
     *
     * @return string Provider identifier
     */
    public function getDefaultProvider(): string
    {
        return $this->defaultProvider;
    }

    /**
     * Static convenience method to send SMS.
     *
     * @param string      $to       Recipient phone number
     * @param string      $text     Message text
     * @param array       $options  Additional options
     * @param string|null $provider Explicitly specify provider
     *
     * @return array Response containing status and provider information
     *
     * @throws \Exception If SMS sending fails
     */
    public static function sendSms(string $to, string $text, array $options = [], ?string $provider = null): array
    {
        $instance = new static();

        return $instance->send($to, $text, $options, $provider);
    }
}
