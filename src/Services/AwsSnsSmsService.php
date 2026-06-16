<?php

namespace Fleetbase\Services;

use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\Log;

class AwsSnsSmsService
{
    protected array $config;

    protected ?SnsClient $client;

    public function __construct(?array $config = null, ?SnsClient $client = null)
    {
        $awsConfig    = config('services.aws', []);
        $smsConfig    = $config ?? config('services.sms.providers.aws_sns', config('sms.providers.aws_sns', []));
        $this->config = array_merge($awsConfig, $smsConfig);
        $this->client = $client;
    }

    public function send(string $to, string $text, ?string $from = null, array $options = []): array
    {
        $this->validateParameters($to, $text);

        $params = [
            'PhoneNumber' => $to,
            'Message'     => $text,
        ];

        $senderId = $from ?: data_get($this->config, 'sender_id');
        $smsType  = data_get($options, 'sms_type', data_get($this->config, 'sms_type', 'Transactional'));

        $attributes = array_filter([
            'AWS.SNS.SMS.SenderID' => $senderId ? [
                'DataType'    => 'String',
                'StringValue' => $senderId,
            ] : null,
            'AWS.SNS.SMS.SMSType' => $smsType ? [
                'DataType'    => 'String',
                'StringValue' => $smsType,
            ] : null,
        ]);

        if (!empty($attributes)) {
            $params['MessageAttributes'] = $attributes;
        }

        Log::info('Sending SMS via AWS SNS', ['to' => $to]);

        $result = $this->client()->publish($params);

        return [
            'success'    => true,
            'message_id' => $result->get('MessageId'),
            'result'     => 'SUCCESS',
            'status'     => 'sent',
            'response'   => $result->toArray(),
        ];
    }

    public function isConfigured(): bool
    {
        return !empty(data_get($this->config, 'key')) && !empty(data_get($this->config, 'secret')) && !empty(data_get($this->config, 'region'));
    }

    protected function client(): SnsClient
    {
        if ($this->client) {
            return $this->client;
        }

        return $this->client = new SnsClient([
            'version'     => 'latest',
            'region'      => data_get($this->config, 'region', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'credentials' => [
                'key'    => data_get($this->config, 'key', env('AWS_ACCESS_KEY_ID')),
                'secret' => data_get($this->config, 'secret', env('AWS_SECRET_ACCESS_KEY')),
            ],
        ]);
    }

    protected function validateParameters(string $to, string $text): void
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException('AWS SNS SMS provider is not configured');
        }

        if (empty($to)) {
            throw new \InvalidArgumentException('Recipient phone number (to) is required');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }
    }
}
