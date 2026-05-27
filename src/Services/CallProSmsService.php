<?php

namespace Fleetbase\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CallProSmsService
{
    protected string $apiKey;

    protected string $from;

    protected string $baseUrl;

    /**
     * Create a new CallProSmsService instance.
     */
    public function __construct()
    {
        $this->apiKey  = config('services.callpromn.api_key', '');
        $this->from    = config('services.callpromn.from', '');
        $this->baseUrl = config('services.callpromn.base_url', 'https://api-text.callpro.mn/v1/sms');

        Log::info('CallProSmsService initialized', [
            'base_url' => $this->baseUrl,
            'from'     => $this->from,
        ]);
    }

    /**
     * Send an SMS message (static convenience method).
     *
     * @param string      $to      Recipient phone number
     * @param string      $text    Message text
     * @param string|null $from    Optional sender number, defaults to config
     * @param array       $options Optional CallPro parameters
     *
     * @return array Response containing status and message ID
     *
     * @throws \Exception If API request fails
     */
    public static function sendSms(string $to, string $text, ?string $from = null, array $options = []): array
    {
        $instance = new static();

        return $instance->send($to, $text, $from, $options);
    }

    /**
     * Send an SMS message.
     *
     * @param string      $to      Recipient phone number
     * @param string      $text    Message text
     * @param string|null $from    Optional sender number, defaults to config
     * @param array       $options Optional CallPro parameters
     *
     * @return array Response containing status and message ID
     *
     * @throws \Exception If API request fails
     */
    public function send(string $to, string $text, ?string $from = null, array $options = []): array
    {
        $from = $from ?? $this->from;

        $this->validateParameters($to, $text, $from);
        $payload = array_filter([
            'from'      => $from,
            'to'        => $to,
            'text'      => $text,
            'brand'     => data_get($options, 'brand'),
            'unique_id' => data_get($options, 'unique_id'),
        ], static fn ($value) => $value !== null && $value !== '');

        try {
            Log::info('Sending SMS via CallPro', [
                'to'   => $to,
                'from' => $from,
                'text' => substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''),
            ]);

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->post("{$this->baseUrl}/send", $payload);

            $statusCode = $response->status();
            $body       = $response->json();

            if ($statusCode === 200 && is_array($body) && isset($body['message_id'])) {
                Log::info('SMS sent successfully', [
                    'message_id' => $body['message_id'],
                    'status'     => $body['status'] ?? null,
                ]);

                return [
                    'success'    => true,
                    'message_id' => $body['message_id'],
                    'result'     => $body['status'] ?? 'queued',
                    'status'     => $body['status'] ?? 'queued',
                ];
            }

            $errorMessage = $this->getErrorMessage($statusCode, $body);

            Log::error('SMS sending failed', [
                'status_code' => $statusCode,
                'error'       => $errorMessage,
                'response'    => $body,
            ]);

            return [
                'success' => false,
                'error'   => $errorMessage,
                'code'    => $statusCode,
            ];
        } catch (\Throwable $e) {
            Log::error('SMS API request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception('Failed to send SMS: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate SMS parameters.
     *
     * @throws \InvalidArgumentException If parameters are invalid
     */
    protected function validateParameters(string $to, string $text, string $from): void
    {
        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('CallPro API key is not configured');
        }

        if (!preg_match('/^\d{8}$/', $from)) {
            throw new \InvalidArgumentException('Sender number (from) must be exactly 8 digits');
        }

        if (!$this->isValidRecipientNumber($to)) {
            throw new \InvalidArgumentException('Recipient phone number (to) must be an 8-digit, 976-prefixed, +976-prefixed, or international number');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }
    }

    /**
     * Get error message based on status code.
     */
    protected function getErrorMessage(int $statusCode, ?array $body = null): string
    {
        if (is_array($body)) {
            $error = data_get($body, 'error') ?? data_get($body, 'reason');
            if (is_string($error) && !empty($error)) {
                return $error;
            }

            $issues = data_get($body, 'issues');
            if (is_array($issues) && !empty($issues)) {
                return json_encode($issues);
            }
        }

        return match ($statusCode) {
            400     => 'Invalid request parameters',
            401     => 'Invalid or missing API key',
            402     => 'Payment not paid',
            403     => 'Blocked number',
            404     => 'Tenant or phone number not found',
            422     => 'Validation error',
            500     => 'CallPro server error',
            default => "API request failed with status code: {$statusCode}",
        };
    }

    /**
     * Validate recipient number formats accepted by CallPro.
     */
    protected function isValidRecipientNumber(string $to): bool
    {
        return (bool) preg_match('/^(?:\d{8}|976\d{8}|\+976\d{8}|\d{9,15})$/', $to);
    }

    /**
     * Check if the service is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->from);
    }

    /**
     * Get the configured sender ID.
     */
    public function getFrom(): string
    {
        return $this->from;
    }

    /**
     * Get the API base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
