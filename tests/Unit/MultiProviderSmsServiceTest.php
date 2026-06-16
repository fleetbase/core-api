<?php

use Aws\MockHandler;
use Aws\Result;
use Aws\Sns\SnsClient;
use Fleetbase\Services\AwsSnsSmsService;
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
                        'url'         => 'https://sms-gateway.test/send',
                        'from'        => 'Fleetbase',
                        'auth_header' => 'Authorization',
                        'auth_token'  => 'Bearer token',
                        'headers'     => [
                            'X-Tenant' => 'fleetbase',
                        ],
                        'body' => [
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

test('custom http sms service renders configured templates', function () {
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
        return $request->url() === 'https://sms-gateway.test/send'
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request->hasHeader('X-Tenant', 'fleetbase')
            && $request['recipient'] === '+15551234567'
            && $request['message'] === 'Hello'
            && $request['sender'] === 'Fleetbase'
            && $request['reference'] === 'custom-123';
    });
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
