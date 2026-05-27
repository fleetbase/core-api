<?php

use Fleetbase\Services\CallProSmsService;
use Fleetbase\Services\SmsService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;

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
            'callpromn' => [
                'api_key'  => 'callpro-api-key',
                'from'     => '72001234',
                'base_url' => 'https://api-text.callpro.mn/v1/sms',
            ],
        ],
        'sms' => [
            'default_provider' => SmsService::PROVIDER_TWILIO,
            'routing_rules'    => [
                '+976' => SmsService::PROVIDER_CALLPRO,
            ],
        ],
    ]));
    $app->instance('log', new NullLogger());
    $app->instance(Factory::class, new Factory());

    Container::setInstance($app);
    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();
});

test('callpro sms service sends renewed post payload with api key header', function () {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'status'     => 'queued',
            'message_id' => '0195c03b-7f96-7f9f-8b71-4d7a930adf2f_1',
        ], 200),
    ]);

    $result = (new CallProSmsService())->send('99112233', 'Hello', null, [
        'brand'     => '42',
        'unique_id' => 'custom-prefix',
    ]);

    expect($result)->toMatchArray([
        'success'    => true,
        'message_id' => '0195c03b-7f96-7f9f-8b71-4d7a930adf2f_1',
        'result'     => 'queued',
        'status'     => 'queued',
    ]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api-text.callpro.mn/v1/sms/send'
            && $request->hasHeader('x-api-key', 'callpro-api-key')
            && $request['from'] === '72001234'
            && $request['to'] === '99112233'
            && $request['text'] === 'Hello'
            && $request['brand'] === '42'
            && $request['unique_id'] === 'custom-prefix';
    });
});

test('callpro sms service allows long segmented messages', function () {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'status'     => 'queued',
            'message_id' => 'long-message-id',
        ], 200),
    ]);

    $result = (new CallProSmsService())->send('99112233', str_repeat('A', 320));

    expect($result['success'])->toBeTrue()
        ->and($result['message_id'])->toBe('long-message-id');
});

test('callpro sms service accepts documented recipient number formats', function (string $phone) {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'status'     => 'queued',
            'message_id' => 'message-id',
        ], 200),
    ]);

    $result = (new CallProSmsService())->send($phone, 'Hello');

    expect($result['success'])->toBeTrue();
})->with([
    '99112233',
    '97699112233',
    '+97699112233',
    '15612767156',
]);

test('callpro sms service returns renewed api error details', function () {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $result = (new CallProSmsService())->send('99112233', 'Hello');

    expect($result)->toMatchArray([
        'success' => false,
        'error'   => 'Unauthorized',
        'code'    => 401,
    ]);
});

test('sms service passes callpro options and ignores twilio sender ids', function () {
    Http::fake([
        'https://api-text.callpro.mn/v1/sms/send' => Http::response([
            'status'     => 'queued',
            'message_id' => 'message-id',
        ], 200),
    ]);

    $result = (new SmsService())->send('+97699112233', 'Hello', [
        'from'      => 'FLEETBASE',
        'brand'     => '42',
        'unique_id' => 'verification-123',
    ], SmsService::PROVIDER_CALLPRO);

    expect($result)->toMatchArray([
        'success'  => true,
        'provider' => SmsService::PROVIDER_CALLPRO,
    ]);

    Http::assertSent(function ($request) {
        return $request['from'] === '72001234'
            && $request['to'] === '99112233'
            && $request['brand'] === '42'
            && $request['unique_id'] === 'verification-123';
    });
});
