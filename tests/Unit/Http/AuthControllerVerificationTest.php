<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

class AuthControllerVerificationRedisFake
{
    public array $deleted = [];

    public array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function del(string $key): int
    {
        $this->deleted[] = $key;
        unset($this->values[$key]);

        return 1;
    }

    public function set(string $key, mixed $value, mixed ...$options): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function setex(string $key, int $ttl, mixed $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }
}

function auth_controller_verification_fixtures(array $config = []): AuthControllerVerificationRedisFake
{
    $container = bind_test_container(array_merge([
        'app.env'                        => 'testing',
        'fleetbase.sms_auth_bypass_code' => null,
    ], $config));

    $redis = new AuthControllerVerificationRedisFake();
    $container->instance('redis', $redis);
    Facade::clearResolvedInstance('redis');
    session()->flush();

    return $redis;
}

function auth_controller_verification_request(array $input = []): Request
{
    return Request::create('/int/v1/auth/verification', 'POST', $input);
}

function auth_controller_verification_phone_key(string $phone): string
{
    return Str::slug($phone . '_verify_code', '_');
}

afterEach(function () {
    session()->flush();
    Facade::clearResolvedInstances();
});

test('validate verification session reports false when token is missing or mismatched', function () {
    $redis                          = auth_controller_verification_fixtures();
    $redis->values['session-token'] = json_encode([
        'email'     => 'owner@example.test',
        'user_uuid' => '11111111-1111-4111-8111-111111111111',
    ]);

    $missingToken = (new AuthController())->validateVerificationSession(auth_controller_verification_request([
        'email' => 'owner@example.test',
    ]));
    $mismatchedEmail = (new AuthController())->validateVerificationSession(auth_controller_verification_request([
        'email' => 'other@example.test',
        'token' => 'session-token',
    ]));

    expect($missingToken->getStatusCode())->toBe(200)
        ->and($missingToken->getData(true))->toBe(['valid' => false])
        ->and($mismatchedEmail->getStatusCode())->toBe(200)
        ->and($mismatchedEmail->getData(true))->toBe(['valid' => false]);
});

test('verification email endpoints reject invalid sessions with stable error contracts', function () {
    auth_controller_verification_fixtures();

    $sendResponse = (new AuthController())->sendVerificationEmail(auth_controller_verification_request([
        'email' => 'owner@example.test',
        'token' => 'missing-token',
    ]));
    $verifyResponse = (new AuthController())->verifyEmail(auth_controller_verification_request([
        'email' => 'owner@example.test',
        'token' => 'missing-token',
        'code'  => '123456',
    ]));

    expect($sendResponse->getStatusCode())->toBe(400)
        ->and($sendResponse->getData(true))->toBe([
            'errors' => ['Invalid verification session.'],
        ])
        ->and($verifyResponse->getStatusCode())->toBe(400)
        ->and($verifyResponse->getData(true))->toBe([
            'errors' => ['Invalid verification session.'],
        ]);
});

test('verify sms code returns success and deletes the consumed verification key', function () {
    $redis               = auth_controller_verification_fixtures();
    $key                 = auth_controller_verification_phone_key('+15555550123');
    $redis->values[$key] = '765432';

    $response = (new AuthController())->verifySmsCode(auth_controller_verification_request([
        'phone' => '+15555550123',
        'code'  => '765432',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'OK',
            'message' => 'Code verified',
        ])
        ->and($redis->deleted)->toBe([$key])
        ->and($redis->values)->not->toHaveKey($key);
});

test('verify sms code rejects mismatched codes without deleting the stored code', function () {
    $redis               = auth_controller_verification_fixtures();
    $key                 = auth_controller_verification_phone_key('+15555550123');
    $redis->values[$key] = '765432';

    $response = (new AuthController())->verifySmsCode(auth_controller_verification_request([
        'phone' => '+15555550123',
        'code'  => '000000',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Invalid verification code'],
        ])
        ->and($redis->deleted)->toBe([])
        ->and($redis->values[$key])->toBe('765432');
});

test('authenticate sms code rejects invalid otp before querying users or deleting redis state', function () {
    $redis = auth_controller_verification_fixtures([
        'fleetbase.sms_auth_bypass_code' => '246810',
    ]);
    $key                 = auth_controller_verification_phone_key('+19765550123');
    $redis->values[$key] = '135790';

    $response = (new AuthController())->authenticateSmsCode(auth_controller_verification_request([
        'phone'       => '9765550123',
        'countryCode' => '1',
        'code'        => '000000',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Invalid verification code'],
        ])
        ->and($redis->deleted)->toBe([])
        ->and($redis->values[$key])->toBe('135790');
});
