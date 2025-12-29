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
    public const PROVIDER_TWILIO = 'twilio';
    public const PROVIDER_CALLPRO = 'callpro';

    /**
     * Default SMS provider.
     */
    protected string $defaultProvider;

    /**
     * Provider routing rules based on phone number prefixes.
     *
     * @var array
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
                self::PROVIDER_CALLPRO => $this->sendViaCallPro($normalizedPhone, $text, $options),
                self::PROVIDER_TWILIO  => $this->sendViaTwilio($normalizedPhone, $text, $options),
                default                => throw new \InvalidArgumentException("Unsupported SMS provider: {$selectedProvider}"),
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

        // Extract the last 8 digits for CallPro (Mongolia format)
        $toNumber = $this->extractCallProNumber($to);

        // Get from number from options or use default
        $from = data_get($options, 'from');

        return $callProService->send($toNumber, $text, $from);
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
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'code'    => $e->getCode(),
            ];
        }
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
     * Normalize phone number to E.164 format if possible.
     *
     * @param string $phoneNumber Raw phone number
     *
     * @return string Normalized phone number
     */
    protected function normalizePhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters except +
        $normalized = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Ensure + prefix for international numbers
        if (!Str::startsWith($normalized, '+') && strlen($normalized) > 10) {
            $normalized = '+' . $normalized;
        }

        return $normalized;
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
        // Remove + and country code, get last 8 digits
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);

        return substr($digits, -8);
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

        $callProService = new CallProSmsService();
        $providers[self::PROVIDER_CALLPRO] = [
            'name'      => 'CallPro/MessagePro.mn',
            'available' => $callProService->isConfigured(),
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
