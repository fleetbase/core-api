<?php

use Aws\MockHandler;
use Aws\Result;
use Aws\Sns\SnsClient;
use Fleetbase\Services\AwsSnsSmsService;
use Fleetbase\Services\CallProSmsService;
use Fleetbase\Services\CustomHttpSmsService;
use Fleetbase\Services\MessageBirdSmsService;
use Fleetbase\Services\SmppGatewayClient;
use Fleetbase\Services\SmppSmsService;
use Fleetbase\Services\SmsService;
use Fleetbase\Services\VonageSmsService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;

class MessageBirdSmsServiceProbe extends MessageBirdSmsService
{
    public function exposeValidateParameters(string $to, string $text, string $originator): void
    {
        $this->validateParameters($to, $text, $originator);
    }
}

class SmsServiceRoutingProbe extends SmsService
{
    public array $dispatches = [];

    public function exposeParentAwsSns(string $to, string $text, array $options = []): array
    {
        return parent::sendViaAwsSns($to, $text, $options);
    }

    public function exposeParentSmpp(string $to, string $text, array $options = []): array
    {
        return parent::sendViaSmpp($to, $text, $options);
    }

    protected function sendViaAwsSns(string $to, string $text, array $options = []): array
    {
        $this->dispatches[] = ['provider' => self::PROVIDER_AWS_SNS, 'to' => $to, 'text' => $text, 'options' => $options];

        return ['success' => true, 'message_id' => 'aws-sns-id'];
    }

    protected function sendViaSmpp(string $to, string $text, array $options = []): array
    {
        $this->dispatches[] = ['provider' => self::PROVIDER_SMPP, 'to' => $to, 'text' => $text, 'options' => $options];

        return ['success' => true, 'message_id' => 'smpp-id'];
    }

    protected function sendViaCustomHttp(string $to, string $text, array $options = []): array
    {
        $this->dispatches[] = ['provider' => self::PROVIDER_CUSTOM_HTTP, 'to' => $to, 'text' => $text, 'options' => $options];

        return ['success' => true, 'message_id' => 'custom-http-id'];
    }
}

class SmppSmsServiceProbe extends SmppSmsService
{
    public function exposeValidateParameters(string $to, string $text, ?string $from): void
    {
        $this->validateParameters($to, $text, $from);
    }

    public function exposeMakeClient(): SmppGatewayClient
    {
        return $this->makeClient();
    }
}

if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        $config = Container::getInstance()->make('config');

        if ($key === null) {
            return $config;
        }

        return $config->get($key, $default);
    }
}

beforeEach(function () {
    $app = new Container();

    $app->instance('config', new Repository([
        'services' => [
            'aws' => [
                'key'    => 'aws-key',
                'secret' => 'aws-secret',
                'region' => 'us-east-1',
            ],
            'sms' => [
                'providers' => [
                    'vonage' => [
                        'api_key'    => 'vonage-key',
                        'api_secret' => 'vonage-secret',
                        'from'       => 'Fleetbase',
                        'base_url'   => 'https://rest.nexmo.com/sms/json',
                    ],
                    'messagebird' => [
                        'access_key' => 'messagebird-key',
                        'originator' => 'Fleetbase',
                        'base_url'   => 'https://rest.messagebird.com/messages',
                    ],
                    'callpro' => [
                        'api_key'  => 'callpro-key',
                        'from'     => '99112233',
                        'base_url' => 'https://api-text.callpro.mn/v1/sms',
                    ],
                    'aws_sns' => [
                        'key'       => 'aws-key',
                        'secret'    => 'aws-secret',
                        'region'    => 'us-east-1',
                        'sender_id' => 'FLEETBASE',
                        'sms_type'  => 'Transactional',
                    ],
                    'smpp' => [
                        'host'        => 'smpp.example.test',
                        'port'        => 2775,
                        'system_id'   => 'fleetbase',
                        'password'    => 'secret',
                        'source_addr' => 'FLEETBASE',
                    ],
                    'custom_http' => [
                        'method'      => 'POST',
                        'url'         => 'https://sms-gateway.test/send',
                        'from'        => 'Fleetbase',
                        'auth_header' => 'Authorization',
                        'auth_token'  => 'Bearer token',
                        'headers'     => [
                            'X-Tenant' => 'fleetbase',
                        ],
                        'query_params' => [],
                        'body'         => [
                            'recipient' => '{{to}}',
                            'message'   => '{{text}}',
                            'sender'    => '{{from}}',
                            'reference' => '{{unique_id}}',
                        ],
                    ],
                ],
            ],
        ],
        'sms' => [
            'default_provider' => SmsService::PROVIDER_VONAGE,
            'routing_rules'    => [
                '+44' => SmsService::PROVIDER_MESSAGEBIRD,
            ],
            'providers' => [],
        ],
    ]));
    $app->instance('log', new NullLogger());
    $app->instance(Factory::class, new Factory());

    Container::setInstance($app);
    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();
});

test('vonage sms service sends form payload and maps success response', function () {
    Http::fake([
        'https://rest.nexmo.com/sms/json' => Http::response([
            'messages' => [
                [
                    'status'     => '0',
                    'message-id' => 'vonage-message-id',
                ],
            ],
        ], 200),
    ]);

    $result = (new VonageSmsService())->send('+15551234567', 'Hello');

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'vonage-message-id',
        'status'     => 'accepted',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://rest.nexmo.com/sms/json'
            && $request['api_key'] === 'vonage-key'
            && $request['api_secret'] === 'vonage-secret'
            && $request['from'] === 'Fleetbase'
            && $request['to'] === '15551234567'
            && $request['text'] === 'Hello';
    });
});

test('vonage sms service maps provider and transport failures', function () {
    Http::fake([
        'https://rest.nexmo.com/sms/json' => Http::response([
            'messages' => [
                [
                    'status'     => '5',
                    'error-text' => 'Invalid destination',
                ],
            ],
        ], 200),
    ]);

    $providerFailure = (new VonageSmsService())->send('+15551234567', 'Hello');

    Http::fake([
        'https://rest.nexmo.com/sms/fail' => Http::response(['unexpected' => true], 503),
    ]);

    $transportFailure = (new VonageSmsService([
        'api_key'    => 'vonage-key',
        'api_secret' => 'vonage-secret',
        'from'       => 'Fleetbase',
        'base_url'   => 'https://rest.nexmo.com/sms/fail',
    ]))->send('+15551234567', 'Hello');

    expect($providerFailure)->toMatchArray([
        'success' => false,
        'error'   => 'Invalid destination',
        'code'    => '5',
    ])->and($transportFailure)->toMatchArray([
        'success' => false,
        'error'   => 'Vonage request failed with status code: 503',
        'code'    => '503',
    ]);
});

test('vonage sms service validates configuration recipient and text inputs', function () {
    expect((new VonageSmsService())->isConfigured())->toBeTrue()
        ->and((new VonageSmsService([
            'api_key'    => '',
            'api_secret' => 'secret',
            'from'       => 'Fleetbase',
        ]))->isConfigured())->toBeFalse()
        ->and(fn () => (new VonageSmsService([
            'api_key'    => '',
            'api_secret' => 'secret',
            'from'       => 'Fleetbase',
        ]))->send('+15551234567', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Vonage SMS provider is not configured')
        ->and(fn () => (new VonageSmsService())->send('', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Recipient phone number (to) is required')
        ->and(fn () => (new VonageSmsService())->send('+15551234567', ''))
        ->toThrow(InvalidArgumentException::class, 'Message text cannot be empty');

    Http::assertNothingSent();
});

test('messagebird sms service sends json payload and maps message id', function () {
    Http::fake([
        'https://rest.messagebird.com/messages' => Http::response([
            'id'         => 'messagebird-id',
            'recipients' => [
                'items' => [
                    ['status' => 'sent'],
                ],
            ],
        ], 201),
    ]);

    $result = (new MessageBirdSmsService())->send('+15551234567', 'Hello', null, [
        'unique_id' => 'verification-123',
    ]);

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'messagebird-id',
        'status'     => 'sent',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://rest.messagebird.com/messages'
            && $request->hasHeader('Authorization', 'AccessKey messagebird-key')
            && $request['originator'] === 'Fleetbase'
            && $request['recipients'] === ['15551234567']
            && $request['body'] === 'Hello'
            && $request['reference'] === 'verification-123';
    });
});

test('messagebird sms service includes optional json fields and normalizes recipient formatting', function () {
    Http::fake([
        'https://rest.messagebird.com/messages' => Http::response([
            'id'         => 'messagebird-id',
            'recipients' => [
                'items' => [
                    ['status' => 'accepted'],
                ],
            ],
        ], 201),
    ]);

    $result = (new MessageBirdSmsService())->send('+1 (555) 123-4567', 'Unicode snowman', 'CustomSender', [
        'reference'  => 'manual-reference',
        'datacoding' => 'unicode',
    ]);

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'messagebird-id',
        'result'     => 'accepted',
        'status'     => 'accepted',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://rest.messagebird.com/messages'
            && $request['originator'] === 'CustomSender'
            && $request['recipients'] === ['15551234567']
            && $request['reference'] === 'manual-reference'
            && $request['datacoding'] === 'unicode';
    });
});

test('messagebird sms service returns provider error descriptions when delivery fails', function () {
    Http::fake([
        'https://rest.messagebird.com/messages' => Http::response([
            'errors' => [
                ['description' => 'Access key is invalid'],
                ['message' => 'Recipient is not routable'],
            ],
        ], 401),
    ]);

    $result = (new MessageBirdSmsService())->send('+15551234567', 'Hello');

    expect($result)->toMatchArray([
        'success' => false,
        'error'   => 'Access key is invalid; Recipient is not routable',
        'code'    => 401,
    ])
        ->and($result['response']['errors'])->toHaveCount(2);
});

test('messagebird sms service falls back to status code error messages for unstructured failures', function () {
    Http::fake([
        'https://rest.messagebird.com/messages' => Http::response('gateway unavailable', 503),
    ]);

    $result = (new MessageBirdSmsService())->send('+15551234567', 'Hello');

    expect($result)->toMatchArray([
        'success'  => false,
        'error'    => 'MessageBird request failed with status code: 503',
        'code'     => 503,
        'response' => null,
    ]);
});

test('messagebird sms service validates configuration recipient text and originator inputs', function () {
    Http::fake();

    expect(fn () => (new MessageBirdSmsService([
        'access_key' => '',
        'originator' => '',
    ]))->send('+15551234567', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'MessageBird SMS provider is not configured')
        ->and(fn () => (new MessageBirdSmsService())->send('', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Recipient phone number (to) is required')
        ->and(fn () => (new MessageBirdSmsService())->send('+15551234567', ''))
        ->toThrow(InvalidArgumentException::class, 'Message text cannot be empty')
        ->and(fn () => (new MessageBirdSmsServiceProbe())->exposeValidateParameters('+15551234567', 'Hello', ''))
        ->toThrow(InvalidArgumentException::class, 'MessageBird originator is required');

    Http::assertNothingSent();
});

test('callpro sms service sends configured payload through static convenience method', function () {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'message_id' => 'callpro-message-id',
        ], 200),
    ]);

    $result = CallProSmsService::sendSms('97699112233', str_repeat('A', 55), null, [
        'brand'     => 'Fleetbase',
        'unique_id' => 'callpro-123',
    ]);

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'callpro-message-id',
        'result'     => 'queued',
        'status'     => 'queued',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api-text.callpro.mn/v1/sms/send'
            && $request->hasHeader('x-api-key', 'callpro-key')
            && $request['from'] === '99112233'
            && $request['to'] === '97699112233'
            && $request['text'] === str_repeat('A', 55)
            && $request['brand'] === 'Fleetbase'
            && $request['unique_id'] === 'callpro-123';
    });
});

test('callpro sms service returns provider reason before status fallbacks', function () {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'reason' => 'Tenant is suspended',
        ], 403),
    ]);

    expect((new CallProSmsService())->send('+97699112233', 'Hello'))->toMatchArray([
        'success' => false,
        'error'   => 'Tenant is suspended',
        'code'    => 403,
    ]);
});

test('callpro sms service returns provider issues before status fallbacks', function () {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'issues' => [
                ['field' => 'to', 'message' => 'Invalid recipient'],
            ],
        ], 422),
    ]);

    expect((new CallProSmsService())->send('99112233', 'Hello'))->toMatchArray([
        'success' => false,
        'error'   => json_encode([
            ['field' => 'to', 'message' => 'Invalid recipient'],
        ]),
        'code'    => 422,
    ]);
});

test('callpro sms service maps known and unknown status code failures', function (int $statusCode, string $expectedError) {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([], $statusCode),
    ]);

    expect((new CallProSmsService())->send('99112233', 'Hello'))->toMatchArray([
        'success' => false,
        'error'   => $expectedError,
        'code'    => $statusCode,
    ]);
})->with([
    [400, 'Invalid request parameters'],
    [401, 'Invalid or missing API key'],
    [402, 'Payment not paid'],
    [403, 'Blocked number'],
    [404, 'Tenant or phone number not found'],
    [422, 'Validation error'],
    [500, 'CallPro server error'],
    [503, 'API request failed with status code: 503'],
]);

test('callpro sms service validates configuration sender recipient and text inputs', function () {
    Http::fake();

    config()->set('services.sms.providers.callpro.api_key', '');

    expect(fn () => (new CallProSmsService())->send('99112233', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'CallPro API key is not configured');

    config()->set('services.sms.providers.callpro.api_key', 'callpro-key');

    expect(fn () => (new CallProSmsService())->send('99112233', 'Hello', 'short'))
        ->toThrow(InvalidArgumentException::class, 'Sender number (from) must be exactly 8 digits')
        ->and(fn () => (new CallProSmsService())->send('invalid', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Recipient phone number (to) must be an 8-digit, 976-prefixed, +976-prefixed, or international number')
        ->and(fn () => (new CallProSmsService())->send('99112233', ''))
        ->toThrow(InvalidArgumentException::class, 'Message text cannot be empty');

    Http::assertNothingSent();
});

test('callpro sms service wraps lower level request exceptions', function () {
    Http::fake(function () {
        throw new RuntimeException('network timeout');
    });

    expect(fn () => (new CallProSmsService())->send('99112233', 'Hello'))
        ->toThrow(Exception::class, 'Failed to send SMS: network timeout');
});

test('callpro sms service exposes configuration state', function () {
    $service = new CallProSmsService();

    expect($service->isConfigured())->toBeTrue()
        ->and($service->getFrom())->toBe('99112233')
        ->and($service->getBaseUrl())->toBe('https://api-text.callpro.mn/v1/sms');
});

test('custom http sms service renders configured post templates', function () {
    Http::fake([
        'https://sms-gateway.test/send' => Http::response([
            'message_id' => 'custom-message-id',
            'status'     => 'queued',
        ], 200),
    ]);

    $result = (new CustomHttpSmsService())->send('+15551234567', 'Hello', null, [
        'unique_id' => 'custom-123',
    ]);

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'custom-message-id',
        'status'     => 'queued',
    ]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://sms-gateway.test/send'
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request->hasHeader('X-Tenant', 'fleetbase')
            && $request['recipient'] === '+15551234567'
            && $request['message'] === 'Hello'
            && $request['sender'] === 'Fleetbase'
            && $request['reference'] === 'custom-123';
    });
});

test('custom http sms service supports get method with rendered query params', function () {
    Http::fake([
        'https://sms-gateway.test/send*' => Http::response([
            'message_id' => 'custom-get-message-id',
            'status'     => 'queued',
        ], 200),
    ]);

    $result = (new CustomHttpSmsService([
        'method'      => 'GET',
        'url'         => 'https://sms-gateway.test/send',
        'from'        => 'Fleetbase',
        'auth_header' => 'Authorization',
        'auth_token'  => 'Bearer {{unique_id}}',
        'headers'     => [
            'X-Recipient' => '{{to}}',
        ],
        'query_params' => [
            'recipient' => '{{to}}',
            'message'   => '{{text}}',
            'sender'    => '{{from}}',
            'reference' => '{{unique_id}}',
        ],
        'body' => [
            'should_not_send' => '{{text}}',
        ],
    ]))->send('+15551234567', 'Hello', null, [
        'unique_id' => 'custom-get-123',
    ]);

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'custom-get-message-id',
        'status'     => 'queued',
    ]);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://sms-gateway.test/send?')
            && $request->hasHeader('Authorization', 'Bearer custom-get-123')
            && $request->hasHeader('X-Recipient', '+15551234567')
            && $query['recipient'] === '+15551234567'
            && $query['message'] === 'Hello'
            && $query['sender'] === 'Fleetbase'
            && $query['reference'] === 'custom-get-123'
            && !isset($request['should_not_send']);
    });
});

test('custom http sms service maps failures validates inputs and appends post query params', function () {
    Http::fake([
        'https://sms-gateway.test/send?tenant=fleetbase&trace=custom-post-123' => Http::response([
            'provider' => [
                'message' => 'Rejected by gateway',
            ],
        ], 422),
    ]);

    $result = (new CustomHttpSmsService([
        'method'          => 'POST',
        'url'             => 'https://sms-gateway.test/send?tenant=fleetbase',
        'from'            => 'Fleetbase',
        'headers'         => [
            'X-Static' => 'value',
        ],
        'query_params' => [
            'trace' => '{{unique_id}}',
            'empty' => '',
        ],
        'body' => [
            'message' => [
                'to'   => '{{to}}',
                'text' => '{{text}}',
            ],
            'literal' => 42,
        ],
        'error_path' => 'provider.message',
    ]))->send('+15551234567', 'Hello', null, [
        'unique_id' => 'custom-post-123',
    ]);

    expect($result)->toMatchArray([
        'success' => false,
        'error'   => 'Rejected by gateway',
        'code'    => 422,
    ]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://sms-gateway.test/send?tenant=fleetbase&trace=custom-post-123'
            && $request->hasHeader('X-Static', 'value')
            && $request['message']['to'] === '+15551234567'
            && $request['message']['text'] === 'Hello'
            && $request['literal'] === 42;
    });

    expect((new CustomHttpSmsService(['url' => 'https://sms-gateway.test/send']))->isConfigured())->toBeTrue()
        ->and((new CustomHttpSmsService(['url' => '']))->isConfigured())->toBeFalse()
        ->and(fn () => (new CustomHttpSmsService(['url' => '']))->send('+15551234567', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Custom HTTP SMS gateway is not configured')
        ->and(fn () => (new CustomHttpSmsService(['url' => 'https://sms-gateway.test/send']))->send('', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Recipient phone number (to) is required')
        ->and(fn () => (new CustomHttpSmsService(['url' => 'https://sms-gateway.test/send']))->send('+15551234567', ''))
        ->toThrow(InvalidArgumentException::class, 'Message text cannot be empty')
        ->and(fn () => (new CustomHttpSmsService([
            'url'    => 'https://sms-gateway.test/send',
            'method' => 'PUT',
        ]))->send('+15551234567', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Custom HTTP SMS method must be GET or POST');
});

test('aws sns sms service publishes to phone number', function () {
    $mock = new MockHandler();
    $mock->append(new Result(['MessageId' => 'sns-message-id']));
    $client = new SnsClient([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'handler'     => $mock,
        'credentials' => [
            'key'    => 'aws-key',
            'secret' => 'aws-secret',
        ],
    ]);

    $result = (new AwsSnsSmsService(null, $client))->send('+15551234567', 'Hello');

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'sns-message-id',
        'status'     => 'sent',
    ]);
});

test('aws sns sms service validates parameters and exposes configuration state', function () {
    $service = new AwsSnsSmsService([
        'key'    => 'explicit-key',
        'secret' => 'explicit-secret',
        'region' => 'ap-southeast-1',
    ]);

    expect($service->isConfigured())->toBeTrue()
        ->and((new AwsSnsSmsService([
            'key'    => '',
            'secret' => 'explicit-secret',
            'region' => 'ap-southeast-1',
        ]))->isConfigured())->toBeFalse()
        ->and(fn () => (new AwsSnsSmsService([
            'key'    => '',
            'secret' => 'explicit-secret',
            'region' => 'ap-southeast-1',
        ]))->send('+15551234567', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'AWS SNS SMS provider is not configured')
        ->and(fn () => (new AwsSnsSmsService())->send('', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Recipient phone number (to) is required')
        ->and(fn () => (new AwsSnsSmsService())->send('+15551234567', ''))
        ->toThrow(InvalidArgumentException::class, 'Message text cannot be empty');
});

test('smpp sms service validates config and delegates to client', function () {
    $client = new class(config('services.sms.providers.smpp')) extends SmppGatewayClient {
        public bool $connected = false;

        public bool $closed = false;

        public array $submitted = [];

        public function connect(): void
        {
            $this->connected = true;
        }

        public function submit(string $from, string $to, string $text, array $options = []): string
        {
            $this->submitted = compact('from', 'to', 'text', 'options');

            return 'smpp-message-id';
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };

    $service = new SmppSmsService(null, fn () => $client);
    $result  = $service->send('+15551234567', 'Hello');

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'smpp-message-id',
    ])
        ->and($client->connected)->toBeTrue()
        ->and($client->closed)->toBeTrue()
        ->and($client->submitted['from'])->toBe('FLEETBASE')
        ->and($client->submitted['to'])->toBe('+15551234567');
});

test('smpp sms service rejects missing config recipient text and source address', function () {
    expect(fn () => (new SmppSmsService([
        'host'        => '',
        'port'        => 2775,
        'system_id'   => 'fleetbase',
        'password'    => 'secret',
        'source_addr' => 'FLEETBASE',
    ]))->send('+15551234567', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'SMPP SMS gateway is not configured')
        ->and(fn () => (new SmppSmsService())->send('', 'Hello'))
        ->toThrow(InvalidArgumentException::class, 'Recipient phone number (to) is required')
        ->and(fn () => (new SmppSmsService())->send('+15551234567', ''))
        ->toThrow(InvalidArgumentException::class, 'Message text cannot be empty')
        ->and(fn () => (new SmppSmsServiceProbe())->exposeValidateParameters('+15551234567', 'Hello', null))
        ->toThrow(InvalidArgumentException::class, 'SMPP source address is required');
});

test('smpp sms service default client factory builds a gateway client from config', function () {
    $client = (new SmppSmsServiceProbe())->exposeMakeClient();

    expect($client)->toBeInstanceOf(SmppGatewayClient::class);
});

test('smpp gateway client encodes configured submit ton and npi values', function () {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();

    [$clientSocket, $serverSocket] = $sockets;
    $client                        = new SmppGatewayClient([
        'host'                => 'smpp.example.test',
        'port'                => 2775,
        'source_addr_ton'     => 1,
        'source_addr_npi'     => 0,
        'dest_addr_ton'       => 1,
        'dest_addr_npi'       => 0,
        'registered_delivery' => 1,
        'data_coding'         => 0,
    ]);

    $socketProperty = new ReflectionProperty($client, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($client, $clientSocket);

    fwrite($serverSocket, pack('NNNN', 20, 0x80000004, 0, 1) . "1\0\0\0");

    $messageId = $client->submit('SENDER', '+15551234567', 'This is a Fleetbase SMS test.');
    $packet    = fread($serverSocket, 4096);
    $body      = substr($packet, 16);

    expect($messageId)->toBe('1')
        ->and(unpack('Nlength/Ncommand/Nstatus/Nsequence', substr($packet, 0, 16)))->toMatchArray([
            'length'   => strlen($packet),
            'command'  => 0x00000004,
            'status'   => 0,
            'sequence' => 1,
        ])
        ->and(ord($body[1]))->toBe(1)
        ->and(ord($body[2]))->toBe(0)
        ->and(substr($body, 3, 7))->toBe("SENDER\0")
        ->and(ord($body[10]))->toBe(1)
        ->and(ord($body[11]))->toBe(0)
        ->and(substr($body, 12, 12))->toBe("15551234567\0");

    fclose($clientSocket);
    fclose($serverSocket);
});

test('smpp gateway client reports command-aware submit failures', function () {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();

    [$clientSocket, $serverSocket] = $sockets;
    $client                        = new SmppGatewayClient([
        'host' => 'smpp.example.test',
        'port' => 2775,
    ]);

    $socketProperty = new ReflectionProperty($client, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($client, $clientSocket);

    fwrite($serverSocket, pack('NNNN', 16, 0x80000004, 196, 1));

    expect(fn () => $client->submit('SENDER', '+15551234567', 'Test'))
        ->toThrow(RuntimeException::class, 'submit_sm failed with SMPP status 196 (0x000000C4) from submit_sm_resp');

    fclose($clientSocket);
    fclose($serverSocket);
});

test('smpp gateway client reports connection failures with endpoint context', function () {
    $client = new SmppGatewayClient([
        'host'    => '127.0.0.1',
        'port'    => 1,
        'timeout' => 0.01,
    ]);

    set_error_handler(fn () => true);

    try {
        expect(fn () => $client->connect())
            ->toThrow(RuntimeException::class, 'Unable to connect to SMPP gateway tcp://127.0.0.1:1');
    } finally {
        restore_error_handler();
    }
});

test('smpp gateway client connects over tcp and binds against a reachable gateway', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to exercise the blocking SMPP connect/bind path.');
    }

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        $this->markTestSkipped('Loopback server sockets are unavailable: ' . ($errstr ?: 'unknown error'));
    }

    $serverName = stream_socket_get_name($server, false);
    $port       = (int) substr(strrchr($serverName, ':'), 1);
    $pid        = pcntl_fork();

    if ($pid === 0) {
        $connection = stream_socket_accept($server, 5);
        if ($connection) {
            for ($i = 0; $i < 2; $i++) {
                $header = fread($connection, 16);
                if (strlen($header) !== 16) {
                    break;
                }

                $parts      = unpack('Nlength/Ncommand/Nstatus/Nsequence', $header);
                $bodyLength = max(0, $parts['length'] - 16);
                if ($bodyLength > 0) {
                    fread($connection, $bodyLength);
                }

                fwrite($connection, pack('NNNN', 16, $parts['command'] | 0x80000000, 0, $parts['sequence']));
            }

            fclose($connection);
        }

        fclose($server);
        exit(0);
    }

    fclose($server);

    try {
        $client = new SmppGatewayClient([
            'host'      => '127.0.0.1',
            'port'      => $port,
            'timeout'   => 2,
            'system_id' => 'fleetbase-system',
            'password'  => 'secret',
        ]);

        $client->connect();
        $client->close();

        expect($pid)->toBeGreaterThan(0);
    } finally {
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
        }
    }
});

test('smpp gateway client binds with configured mode and credentials', function (string $bindType, int $expectedCommand) {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();

    [$clientSocket, $serverSocket] = $sockets;
    $client                        = new SmppGatewayClient([
        'host'              => 'smpp.example.test',
        'port'              => 2775,
        'bind_type'         => $bindType,
        'system_id'         => 'fleetbase-system',
        'password'          => 'secret',
        'system_type'       => 'fleetbase',
        'interface_version' => 0x34,
        'addr_ton'          => 1,
        'addr_npi'          => 1,
        'address_range'     => '1555',
    ]);

    $socketProperty = new ReflectionProperty($client, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($client, $clientSocket);

    fwrite($serverSocket, pack('NNNN', 16, $expectedCommand | 0x80000000, 0, 1));

    $bind = new ReflectionMethod($client, 'bind');
    $bind->setAccessible(true);
    $bind->invoke($client);

    $packet = fread($serverSocket, 4096);
    $body   = substr($packet, 16);

    expect(unpack('Nlength/Ncommand/Nstatus/Nsequence', substr($packet, 0, 16)))->toMatchArray([
        'length'   => strlen($packet),
        'command'  => $expectedCommand,
        'status'   => 0,
        'sequence' => 1,
    ])
        ->and($body)->toStartWith("fleetbase-system\0secret\0fleetbase\0")
        ->and(ord($body[strlen("fleetbase-system\0secret\0fleetbase\0")]))->toBe(0x34);

    fclose($clientSocket);
    fclose($serverSocket);
})->with([
    ['transmitter', 0x00000002],
    ['receiver', 0x00000001],
    ['transceiver', 0x00000009],
]);

test('smpp gateway client returns early when closing without a socket', function () {
    $client = new SmppGatewayClient([
        'host' => 'smpp.example.test',
        'port' => 2775,
    ]);

    $client->close();

    $socketProperty = new ReflectionProperty($client, 'socket');
    $socketProperty->setAccessible(true);

    expect($socketProperty->getValue($client))->toBeNull();
});

test('smpp gateway client closes socket after unbind failures', function () {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();

    [$clientSocket, $serverSocket] = $sockets;
    $client                        = new SmppGatewayClient([
        'host' => 'smpp.example.test',
        'port' => 2775,
    ]);

    $socketProperty = new ReflectionProperty($client, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($client, $clientSocket);

    fwrite($serverSocket, pack('NNNN', 16, 0x80000006, 196, 1));

    $client->close();

    expect($socketProperty->getValue($client))->toBeNull();

    fclose($serverSocket);
});

test('smpp gateway client reports invalid response header and body lengths', function () {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();

    [$clientSocket, $serverSocket] = $sockets;
    $client                        = new SmppGatewayClient([
        'host' => 'smpp.example.test',
        'port' => 2775,
    ]);

    $socketProperty = new ReflectionProperty($client, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($client, $clientSocket);

    fwrite($serverSocket, 'short');

    $sendPdu = new ReflectionMethod($client, 'sendPdu');
    $sendPdu->setAccessible(true);

    expect(fn () => $sendPdu->invoke($client, 0x00000004, ''))
        ->toThrow(RuntimeException::class, 'submit_sm failed: invalid SMPP response header, expected 16 bytes, read 5 bytes');

    fclose($clientSocket);
    fclose($serverSocket);

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();

    [$clientSocket, $serverSocket] = $sockets;
    $socketProperty->setValue($client, $clientSocket);

    stream_set_blocking($clientSocket, false);
    fwrite($serverSocket, pack('NNNN', 20, 0x80000004, 0, 2) . 'xx');

    expect(fn () => $sendPdu->invoke($client, 0x00000004, ''))
        ->toThrow(RuntimeException::class, 'submit_sm failed: invalid SMPP response body, expected 4 bytes, read 2 bytes from submit_sm_resp');

    fclose($clientSocket);
    fclose($serverSocket);
});

test('sms service routes explicit provider and prefix rules to new providers', function () {
    Http::fake([
        'https://rest.messagebird.com/messages' => Http::response([
            'id' => 'messagebird-id',
        ], 201),
        'https://rest.nexmo.com/sms/json' => Http::response([
            'messages' => [
                [
                    'status'     => '0',
                    'message-id' => 'vonage-id',
                ],
            ],
        ], 200),
    ]);

    $messageBirdResult = (new SmsService())->send('+441234567890', 'Hello');
    $vonageResult      = (new SmsService())->send('+15551234567', 'Hello', [], SmsService::PROVIDER_VONAGE);

    expect($messageBirdResult)->toMatchArray([
        'success'  => true,
        'provider' => SmsService::PROVIDER_MESSAGEBIRD,
    ])->and($vonageResult)->toMatchArray([
        'success'  => true,
        'provider' => SmsService::PROVIDER_VONAGE,
    ]);
});

test('sms service dispatches through the configured custom http provider', function () {
    Http::fake([
        'https://sms-gateway.test/send' => Http::response([
            'message_id' => 'custom-http-message-id',
            'status'     => 'accepted',
        ], 200),
    ]);

    $result = (new SmsService())->send('+1 (555) 123-4567', 'Gateway hello', [
        'from'      => 'Ops',
        'unique_id' => 'verification-456',
    ], SmsService::PROVIDER_CUSTOM_HTTP);

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => 'custom-http-message-id',
        'status'     => 'accepted',
        'provider'   => SmsService::PROVIDER_CUSTOM_HTTP,
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://sms-gateway.test/send'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request['recipient'] === '+15551234567'
            && $request['message'] === 'Gateway hello'
            && $request['sender'] === 'Ops'
            && $request['reference'] === 'verification-456';
    });
});

test('sms service routes aws smpp and custom http providers without leaking formatting', function () {
    config()->set('sms.default_provider', SmsService::PROVIDER_AWS_SNS);
    config()->set('sms.routing_rules', [
        '+88' => SmsService::PROVIDER_SMPP,
        '+77' => SmsService::PROVIDER_CUSTOM_HTTP,
    ]);

    $service = new SmsServiceRoutingProbe();

    $awsResult    = $service->send('+1 (555) 123-4567', 'Default AWS', ['sender_id' => 'Fleetbase']);
    $smppResult   = $service->send('+88 123 456', 'Route SMPP', ['source_addr' => 'FBASE']);
    $customResult = $service->send('+77-999-000', 'Route HTTP', ['from' => 'Fleetbase']);

    $service->addRoutingRule('+66', SmsService::PROVIDER_CUSTOM_HTTP);
    $service->setDefaultProvider(SmsService::PROVIDER_SMPP);
    $staticResult = SmsServiceRoutingProbe::sendSms('+77 111 222', 'Static route');

    expect($awsResult)->toMatchArray([
        'success'    => true,
        'message_id' => 'aws-sns-id',
        'provider'   => SmsService::PROVIDER_AWS_SNS,
    ])
        ->and($smppResult)->toMatchArray([
            'success'    => true,
            'message_id' => 'smpp-id',
            'provider'   => SmsService::PROVIDER_SMPP,
        ])
        ->and($customResult)->toMatchArray([
            'success'    => true,
            'message_id' => 'custom-http-id',
            'provider'   => SmsService::PROVIDER_CUSTOM_HTTP,
        ])
        ->and($staticResult)->toMatchArray([
            'success'    => true,
            'message_id' => 'custom-http-id',
            'provider'   => SmsService::PROVIDER_CUSTOM_HTTP,
        ])
        ->and($service->dispatches)->toBe([
            ['provider' => SmsService::PROVIDER_AWS_SNS, 'to' => '+15551234567', 'text' => 'Default AWS', 'options' => ['sender_id' => 'Fleetbase']],
            ['provider' => SmsService::PROVIDER_SMPP, 'to' => '+88123456', 'text' => 'Route SMPP', 'options' => ['source_addr' => 'FBASE']],
            ['provider' => SmsService::PROVIDER_CUSTOM_HTTP, 'to' => '+77999000', 'text' => 'Route HTTP', 'options' => ['from' => 'Fleetbase']],
        ])
        ->and($service->getRoutingRules())->toBe([
            '+88' => SmsService::PROVIDER_SMPP,
            '+77' => SmsService::PROVIDER_CUSTOM_HTTP,
            '+66' => SmsService::PROVIDER_CUSTOM_HTTP,
        ])
        ->and($service->getDefaultProvider())->toBe(SmsService::PROVIDER_SMPP);
});

test('sms service parent provider wiring delegates to concrete providers before validation failures', function () {
    config()->set('services.sms.providers.aws_sns.key', '');
    config()->set('services.sms.providers.smpp.host', '');

    $service = new SmsServiceRoutingProbe();

    expect(fn () => $service->exposeParentAwsSns('+15551234567', 'Default AWS', ['sender_id' => 'Fleetbase']))
        ->toThrow(InvalidArgumentException::class, 'AWS SNS SMS provider is not configured')
        ->and(fn () => $service->exposeParentSmpp('+15551234567', 'Default SMPP', ['source_addr' => 'Fleetbase']))
        ->toThrow(InvalidArgumentException::class, 'SMPP SMS gateway is not configured');
});

test('aws sns sms service can construct a configured client lazily', function () {
    $service = new AwsSnsSmsService([
        'key'    => 'lazy-key',
        'secret' => 'lazy-secret',
        'region' => 'ap-southeast-1',
    ]);

    $client = new ReflectionMethod(AwsSnsSmsService::class, 'client');
    $client->setAccessible(true);

    expect($client->invoke($service))->toBeInstanceOf(SnsClient::class)
        ->and($client->invoke($service))->toBe($client->invoke($service));
});
