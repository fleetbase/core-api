<?php

namespace Fleetbase\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessageBirdSmsService
{
    protected string $accessKey;

    protected string $originator;

    protected string $baseUrl;

    public function __construct(?array $config = null)
    {
        $config           = $config ?? config('services.sms.providers.messagebird', config('sms.providers.messagebird', []));
        $this->accessKey  = (string) data_get($config, 'access_key', '');
        $this->originator = (string) data_get($config, 'originator', data_get($config, 'from', ''));
        $this->baseUrl    = rtrim((string) data_get($config, 'base_url', 'https://rest.messagebird.com/messages'), '/');
    }

    public function send(string $to, string $text, ?string $originator = null, array $options = []): array
    {
        $originator = $originator ?: $this->originator;
        $this->validateParameters($to, $text, $originator);

        $payload = array_filter([
            'originator' => $originator,
            'recipients' => [$this->normalizeRecipient($to)],
            'body'       => $text,
            'reference'  => data_get($options, 'unique_id', data_get($options, 'reference')),
            'datacoding' => data_get($options, 'datacoding'),
        ], static fn ($value) => $value !== null && $value !== '');

        Log::info('Sending SMS via MessageBird', [
            'to'         => $to,
            'originator' => $originator,
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'AccessKey ' . $this->accessKey,
            'Accept'        => 'application/json',
        ])->asJson()->post($this->baseUrl, $payload);

        $body = $response->json();

        if ($response->successful() && is_array($body) && isset($body['id'])) {
            return [
                'success'    => true,
                'message_id' => $body['id'],
                'result'     => data_get($body, 'recipients.items.0.status', 'sent'),
                'status'     => data_get($body, 'recipients.items.0.status', 'sent'),
                'response'   => $body,
            ];
        }

        return [
            'success'  => false,
            'error'    => $this->getErrorMessage($response->status(), is_array($body) ? $body : null),
            'code'     => $response->status(),
            'response' => $body,
        ];
    }

    public function isConfigured(): bool
    {
        return !empty($this->accessKey) && !empty($this->originator);
    }

    protected function validateParameters(string $to, string $text, string $originator): void
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('MessageBird SMS provider is not configured');
        }

        if (empty($to)) {
            throw new \InvalidArgumentException('Recipient phone number (to) is required');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }

        if (empty($originator)) {
            throw new \InvalidArgumentException('MessageBird originator is required');
        }
    }

    protected function normalizeRecipient(string $to): string
    {
        return ltrim(preg_replace('/[^0-9+]/', '', $to), '+');
    }

    protected function getErrorMessage(int $statusCode, ?array $body = null): string
    {
        $errors = data_get($body, 'errors');
        if (is_array($errors) && !empty($errors)) {
            return collect($errors)->map(fn ($error) => data_get($error, 'description', data_get($error, 'message')))->filter()->implode('; ');
        }

        return "MessageBird request failed with status code: {$statusCode}";
    }
}
