<?php

use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\Services\SmsService;
use Fleetbase\Support\Sms\SmsContext;
use Fleetbase\Support\Sms\SmsGuard;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class SmsServiceGuardProbe extends SmsService
{
    public array $dispatches = [];

    public ?Throwable $failure = null;

    protected function sendViaTwilio(string $to, string $text, array $options = []): array
    {
        $this->dispatches[] = compact('to', 'text', 'options');

        if ($this->failure) {
            throw $this->failure;
        }

        return ['success' => true, 'message_id' => 'SM-probe'];
    }
}

function sms_service_guard_setup(array $guard = []): SmsGuard
{
    session()->flush();

    $container = bind_test_container([
        'sms.default_provider' => 'twilio',
        'sms.guard'            => array_replace_recursive([
            'enabled' => true,
            'mode'    => 'enforce',
            'limits'  => [
                'destination' => ['cooldown_seconds' => 60, 'per_hour' => 3, 'per_day' => 5],
                'probation'   => ['days' => 0],
            ],
            'breaker' => ['alert_percentages' => [], 'distinct_destinations_per_10m' => 100],
        ], $guard),
    ]);
    $cache = new CacheRepository(new ArrayStore());
    $container->instance('cache', $cache);
    $container->instance('request', Request::create('/v1/drivers/login-with-sms', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.7']));
    Facade::clearResolvedInstances();
    session(['company' => 'company-api']);

    $guard = new SmsGuard($cache);
    $container->instance(SmsGuard::class, $guard);

    return $guard;
}

afterEach(function () {
    session()->flush();
    app()->forgetInstance(SmsGuard::class);
    Facade::clearResolvedInstances();
});

test('sms service refuses a blocked destination before any provider is called', function () {
    sms_service_guard_setup();
    $service = new SmsServiceGuardProbe();

    $refusal = null;
    try {
        $service->send('+449098790123', 'hello', ['purpose' => 'driver_login']);
    } catch (SmsBlockedException $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(SmsBlockedException::class)
        ->and($refusal->status())->toBe(422)
        ->and($service->dispatches)->toBe([]);
});

test('sms service reserves, normalises the destination, strips guard options and settles', function () {
    $guard   = sms_service_guard_setup();
    $service = new SmsServiceGuardProbe();

    $result  = $service->send('44 7400 100123', 'hello', ['purpose' => 'driver_login', 'company_uuid' => 'company-api', 'twilioParams' => ['from' => 'ACME']]);
    $entries = collect(app('log')->entries);

    $cooldown = null;
    try {
        $service->send('+447400100123', 'hello again', ['purpose' => 'driver_login']);
    } catch (SmsBlockedException $e) {
        $cooldown = $e;
    }

    expect($result['provider'])->toBe('twilio')
        ->and($service->dispatches)->toHaveCount(1)
        ->and($service->dispatches[0]['to'])->toBe('+447400100123')
        ->and($service->dispatches[0]['options'])->toBe(['twilioParams' => ['from' => 'ACME']])
        ->and($entries->where(1, 'sms.sent'))->toHaveCount(1)
        ->and($entries->where(1, 'sms.sent')->first()[2])->toMatchArray(['purpose' => 'driver_login', 'company_uuid' => 'company-api', 'source' => 'api', 'ip' => '198.51.100.7', 'message_id' => 'SM-probe'])
        ->and($cooldown)->toBeInstanceOf(SmsBlockedException::class)
        ->and($cooldown->status())->toBe(429)
        ->and($guard->limiter()->attempts(SmsGuard::CACHE_PREFIX . 'company:company-api:per_day'))->toBe(1);
});

test('sms service counts a send once when the caller hands over its reservation, and settles failures', function () {
    $guard   = sms_service_guard_setup(['limits' => ['destination' => ['cooldown_seconds' => 0]]]);
    $service = new SmsServiceGuardProbe();

    $reservation = $guard->reserve(SmsContext::resolve('+447400100123', ['purpose' => 'driver_login']));
    $service->send('+447400100123', 'hello', ['sms_reservation' => $reservation, 'purpose' => 'driver_login']);

    $service->failure = new RuntimeException('carrier refused');
    $thrown           = null;
    try {
        $service->send('+447400100124', 'hello', ['purpose' => 'driver_login']);
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    $entries = collect(app('log')->entries);

    expect($reservation->settled)->toBeTrue()
        ->and($guard->limiter()->attempts(SmsGuard::CACHE_PREFIX . 'company:company-api:per_day'))->toBe(2)
        ->and($thrown?->getMessage())->toBe('carrier refused')
        ->and($entries->where(1, 'sms.failed'))->toHaveCount(1)
        ->and($entries->where(1, 'sms.failed')->first()[2]['error'])->toBe('carrier refused');
});

test('sms service treats a provider result with success false as a failed send', function () {
    sms_service_guard_setup(['limits' => ['destination' => ['cooldown_seconds' => 0]]]);
    $service = new class extends SmsService {
        protected function sendViaTwilio(string $to, string $text, array $options = []): array
        {
            return ['success' => false, 'error' => 'invalid sender'];
        }
    };

    $service->send('+447400100123', 'hello', ['purpose' => 'driver_login']);

    $failed = collect(app('log')->entries)->where(1, 'sms.failed')->first();

    expect($failed)->not->toBeNull()
        ->and($failed[2]['error'])->toBe('invalid sender');
});

test('sms service never texts a number reserved for fiction, such as an App Review number', function () {
    sms_service_guard_setup();
    $service = new SmsServiceGuardProbe();

    $result = $service->send('+1 202 555 0143', 'hello', ['purpose' => 'storefront_login']);

    expect($result)->toMatchArray(['success' => true, 'skipped' => true])
        ->and($service->dispatches)->toBe([]);
});
