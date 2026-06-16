<?php

namespace Fleetbase\Services;

use Illuminate\Support\Facades\Log;

class SmppSmsService
{
    protected array $config;

    protected $clientFactory;

    public function __construct(?array $config = null, ?callable $clientFactory = null)
    {
        $this->config        = $config ?? config('services.sms.providers.smpp', config('sms.providers.smpp', []));
        $this->clientFactory = $clientFactory;
    }

    public function send(string $to, string $text, ?string $from = null, array $options = []): array
    {
        $from = $from ?: data_get($this->config, 'source_addr');
        $this->validateParameters($to, $text, $from);

        Log::info('Sending SMS via SMPP gateway', [
            'to'   => $to,
            'from' => $from,
        ]);

        $client = $this->makeClient();
        $client->connect();

        try {
            $messageId = $client->submit($from, $to, $text, $options);
        } finally {
            $client->close();
        }

        return [
            'success'    => true,
            'message_id' => $messageId,
            'result'     => 'SUCCESS',
            'status'     => 'sent',
        ];
    }

    public function isConfigured(): bool
    {
        return !empty(data_get($this->config, 'host'))
            && !empty(data_get($this->config, 'port'))
            && !empty(data_get($this->config, 'system_id'))
            && data_get($this->config, 'password') !== null
            && !empty(data_get($this->config, 'source_addr'));
    }

    protected function validateParameters(string $to, string $text, ?string $from): void
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('SMPP SMS gateway is not configured');
        }

        if (empty($to)) {
            throw new \InvalidArgumentException('Recipient phone number (to) is required');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }

        if (empty($from)) {
            throw new \InvalidArgumentException('SMPP source address is required');
        }
    }

    protected function makeClient(): SmppGatewayClient
    {
        if (is_callable($this->clientFactory)) {
            return call_user_func($this->clientFactory, $this->config);
        }

        return new SmppGatewayClient($this->config);
    }
}
