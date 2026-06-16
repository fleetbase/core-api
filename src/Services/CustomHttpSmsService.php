<?php

namespace Fleetbase\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CustomHttpSmsService
{
    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('services.sms.providers.custom_http', config('sms.providers.custom_http', []));
    }

    public function send(string $to, string $text, ?string $from = null, array $options = []): array
    {
        $this->validateParameters($to, $text);

        $variables = [
            'to'        => $to,
            'text'      => $text,
            'from'      => $from ?: data_get($this->config, 'from', ''),
            'provider'  => SmsService::PROVIDER_CUSTOM_HTTP,
            'timestamp' => date('c'),
            'unique_id' => data_get($options, 'unique_id', data_get($options, 'reference', '')),
        ];

        $url     = $this->renderTemplate((string) data_get($this->config, 'url'), $variables);
        $headers = $this->renderTemplateValues((array) data_get($this->config, 'headers', []), $variables);
        $body    = $this->renderTemplateValues((array) data_get($this->config, 'body', [
            'to'   => '{{to}}',
            'text' => '{{text}}',
            'from' => '{{from}}',
        ]), $variables);

        $authHeaderName  = data_get($this->config, 'auth_header');
        $authHeaderValue = data_get($this->config, 'auth_token');
        if ($authHeaderName && $authHeaderValue) {
            $headers[$authHeaderName] = $this->renderTemplate((string) $authHeaderValue, $variables);
        }

        Log::info('Sending SMS via custom HTTP gateway', ['to' => $to, 'url' => $url]);

        $response = Http::withHeaders($headers)->asJson()->post($url, $body);
        $payload  = $response->json();

        if ($response->successful()) {
            return [
                'success'    => true,
                'message_id' => data_get($payload, data_get($this->config, 'message_id_path', 'message_id')),
                'result'     => data_get($payload, data_get($this->config, 'status_path', 'status'), 'sent'),
                'status'     => data_get($payload, data_get($this->config, 'status_path', 'status'), 'sent'),
                'response'   => $payload,
            ];
        }

        return [
            'success'  => false,
            'error'    => data_get($payload, data_get($this->config, 'error_path', 'error'), "Custom HTTP gateway failed with status code: {$response->status()}"),
            'code'     => $response->status(),
            'response' => $payload,
        ];
    }

    public function isConfigured(): bool
    {
        return !empty(data_get($this->config, 'url'));
    }

    protected function validateParameters(string $to, string $text): void
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('Custom HTTP SMS gateway is not configured');
        }

        if (empty($to)) {
            throw new \InvalidArgumentException('Recipient phone number (to) is required');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }
    }

    protected function renderTemplateValues(array $values, array $variables): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->renderTemplateValues($value, $variables);
            } elseif (is_string($value)) {
                $values[$key] = $this->renderTemplate($value, $variables);
            }
        }

        return $values;
    }

    protected function renderTemplate(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return $template;
    }
}
