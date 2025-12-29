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
        $this->baseUrl = config('services.callpromn.base_url', 'https://api.messagepro.mn');

        Log::info('CallProSmsService initialized', [
            'base_url' => $this->baseUrl,
            'from'     => $this->from,
        ]);
    }

    /**
     * Send an SMS message (static convenience method).
     *
     * @param string $to      Recipient phone number (8 digits)
     * @param string $text    Message text (max 160 characters)
     * @param string|null $from Optional sender ID (8 characters), defaults to config
     *
     * @return array Response containing status and message ID
     *
     * @throws \Exception If API request fails
     */
    public static function sendSms(string $to, string $text, ?string $from = null): array
    {
        $instance = new static();

        return $instance->send($to, $text, $from);
    }

    /**
     * Send an SMS message.
     *
     * @param string $to      Recipient phone number (8 digits)
     * @param string $text    Message text (max 160 characters)
     * @param string|null $from Optional sender ID (8 characters), defaults to config
     *
     * @return array Response containing status and message ID
     *
     * @throws \Exception If API request fails
     */
    public function send(string $to, string $text, ?string $from = null): array
    {
        $from = $from ?? $this->from;

        // Validate sender ID format - if invalid, use default
        if (!$this->isValidSenderId($from)) {
            Log::warning('Invalid sender ID for CallPro, using default', [
                'provided' => $from,
                'default'  => $this->from,
            ]);
            $from = $this->from;
        }

        // Validate parameters
        $this->validateParameters($to, $text, $from);

        try {
            Log::info('Sending SMS via CallPro', [
                'to'   => $to,
                'from' => $from,
                'text' => substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''),
            ]);

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/send", [
                'from' => $from,
                'to'   => $to,
                'text' => $text,
            ]);

            $statusCode = $response->status();
            $body       = $response->json();

            // Handle response based on status code
            if ($statusCode === 200) {
                Log::info('SMS sent successfully', [
                    'message_id' => $body['Message ID'] ?? null,
                    'result'     => $body['Result'] ?? null,
                ]);

                return [
                    'success'    => true,
                    'message_id' => $body['Message ID'] ?? null,
                    'result'     => $body['Result'] ?? 'SUCCESS',
                ];
            }

            // Handle error responses
            $errorMessage = $this->getErrorMessage($statusCode);

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
     * Check if sender ID is valid for CallPro (8 digits).
     *
     * @param string|null $senderId Sender ID to validate
     *
     * @return bool True if valid, false otherwise
     */
    protected function isValidSenderId(?string $senderId): bool
    {
        if (empty($senderId)) {
            return false;
        }

        // Must be exactly 8 characters and numeric
        return strlen($senderId) === 8 && ctype_digit($senderId);
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

        if (!$this->isValidSenderId($from)) {
            throw new \InvalidArgumentException('Sender ID (from) must be exactly 8 digits');
        }

        if (strlen($to) !== 8) {
            throw new \InvalidArgumentException('Recipient phone number (to) must be exactly 8 characters');
        }

        if (strlen($text) > 160) {
            throw new \InvalidArgumentException('Message text cannot exceed 160 characters');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }
    }

    /**
     * Get error message based on status code.
     */
    protected function getErrorMessage(int $statusCode): string
    {
        return match ($statusCode) {
            402 => 'Invalid request parameters',
            403 => 'Invalid API key (x-api-key)',
            404 => 'Invalid sender ID or recipient phone number format',
            503 => 'API rate limit exceeded (max 5 requests per second)',
            default => "API request failed with status code: {$statusCode}",
        };
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
