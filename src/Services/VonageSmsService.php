<?php

namespace Fleetbase\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VonageSmsService
{
    protected string $apiKey;

    protected string $apiSecret;

    protected string $from;

    protected string $baseUrl;

    public function __construct(?array $config = null)
    {
        $config          = $config ?? config('services.sms.providers.vonage', config('sms.providers.vonage', []));
        $this->apiKey    = (string) data_get($config, 'api_key', '');
        $this->apiSecret = (string) data_get($config, 'api_secret', '');
        $this->from      = (string) data_get($config, 'from', '');
        $this->baseUrl   = rtrim((string) data_get($config, 'base_url', 'https://rest.nexmo.com/sms/json'), '/');
    }

    public function send(string $to, string $text, ?string $from = null, array $options = []): array
    {
        $from = $from ?: $this->from;
        $this->validateParameters($to, $text, $from);

        $payload = array_filter([
            'api_key'    => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'from'       => $from,
            'to'         => $this->normalizeRecipient($to),
            'text'       => $text,
            'type'       => data_get($options, 'type'),
            'client-ref' => data_get($options, 'unique_id', data_get($options, 'client_ref')),
        ], static fn ($value) => $value !== null && $value !== '');

        Log::info('Sending SMS via Vonage', [
            'to'   => $to,
            'from' => $from,
        ]);

        $response = Http::asForm()->post($this->baseUrl, $payload);
        $body     = $response->json();
        $message  = is_array($body) ? data_get($body, 'messages.0', []) : [];
        $status   = (string) data_get($message, 'status', $response->successful() ? '0' : (string) $response->status());

        if ($response->successful() && $status === '0') {
            return [
                'success'    => true,
                'message_id' => data_get($message, 'message-id'),
                'result'     => data_get($message, 'status', 'accepted'),
                'status'     => 'accepted',
                'response'   => $body,
            ];
        }

        return [
            'success'  => false,
            'error'    => data_get($message, 'error-text', "Vonage request failed with status code: {$response->status()}"),
            'code'     => $status,
            'response' => $body,
        ];
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->apiSecret) && !empty($this->from);
    }

    protected function validateParameters(string $to, string $text, string $from): void
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('Vonage SMS provider is not configured');
        }

        if (empty($to)) {
            throw new \InvalidArgumentException('Recipient phone number (to) is required');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }

        if (empty($from)) {
            throw new \InvalidArgumentException('Vonage sender (from) is required');
        }
    }

    protected function normalizeRecipient(string $to): string
    {
        return ltrim(preg_replace('/[^0-9+]/', '', $to), '+');
    }
}
