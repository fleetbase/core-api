<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Illuminate\Support\Facades\Facade;

class SettingControllerTwilioFake
{
    public array $messages = [];

    public ?Throwable $exception = null;

    public function message(string $phone, string $message): void
    {
        if ($this->exception) {
            throw $this->exception;
        }

        $this->messages[] = [$phone, $message];
    }
}

function setting_controller_external_probe_fixtures(array $config = []): void
{
    bind_test_container(array_merge([
        'twilio.twilio.connections.twilio'        => [
            'sid'   => 'existing-sid',
            'token' => 'existing-token',
            'from'  => '+15555550100',
        ],
        'broadcasting.connections.socketcluster.options' => [
            'secure'  => false,
            'host'    => '127.0.0.1',
            'port'    => 9,
            'path'    => '',
            'query'   => [],
            'timeout' => 1,
        ],
    ], $config));

    Facade::clearResolvedInstances();
}

function setting_controller_external_probe_request(array $input = []): AdminRequest
{
    return AdminRequest::create('/int/v1/settings/external-probe', 'POST', $input);
}

afterEach(function () {
    // Unbind rather than just forget the instance: a binding left behind by these tests
    // would satisfy later files that expect twilio to be unbound.
    app()->offsetUnset('twilio');
    Facade::clearResolvedInstances();
});

test('test twilio config requires a phone number before mutating runtime config', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'override-sid',
        'token' => 'override-token',
        'from'  => '+15555550999',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'No test phone number provided!',
        ])
        ->and(config('twilio.twilio.connections.twilio'))->toBe([
            'sid'   => 'existing-sid',
            'token' => 'existing-token',
            'from'  => '+15555550100',
        ]);
});

test('test twilio config applies request credentials before returning provider errors', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'override-sid',
        'token' => 'override-token',
        'from'  => '+15555550999',
        'phone' => '+15555550123',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('error')
        ->and($response->getData(true)['message'])->toBe('Target class [twilio] does not exist.')
        ->and(config('twilio.twilio.connections.twilio'))->toBe([
            'sid'   => 'override-sid',
            'token' => 'override-token',
            'from'  => '+15555550999',
        ]);
});

test('test twilio config returns twilio rest exceptions as provider errors', function () {
    setting_controller_external_probe_fixtures();
    $twilio            = new SettingControllerTwilioFake();
    $twilio->exception = new Twilio\Exceptions\RestException('Twilio rejected the destination phone number', 21614, 400);

    app()->instance('twilio', $twilio);
    Facade::clearResolvedInstance('twilio');

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'rest-sid',
        'token' => 'rest-token',
        'from'  => '+15555550999',
        'phone' => '+15555550123',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'Twilio rejected the destination phone number',
        ])
        ->and($twilio->messages)->toBe([])
        ->and(config('twilio.twilio.connections.twilio'))->toBe([
            'sid'   => 'rest-sid',
            'token' => 'rest-token',
            'from'  => '+15555550999',
        ]);
});

test('test twilio config returns fatal provider errors as stable probe errors', function () {
    setting_controller_external_probe_fixtures();
    $twilio            = new SettingControllerTwilioFake();
    $twilio->exception = new Error('Twilio facade failed to boot');

    app()->instance('twilio', $twilio);
    Facade::clearResolvedInstance('twilio');

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'error-sid',
        'token' => 'error-token',
        'from'  => '+15555550999',
        'phone' => '+15555550123',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'Twilio facade failed to boot',
        ])
        ->and($twilio->messages)->toBe([]);
});

test('test twilio config returns php warning failures as stable probe errors', function () {
    setting_controller_external_probe_fixtures();
    $twilio            = new SettingControllerTwilioFake();
    $twilio->exception = new ErrorException('Twilio transport emitted a warning');

    app()->instance('twilio', $twilio);
    Facade::clearResolvedInstance('twilio');

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'warning-sid',
        'token' => 'warning-token',
        'from'  => '+15555550999',
        'phone' => '+15555550123',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'Twilio transport emitted a warning',
        ])
        ->and($twilio->messages)->toBe([]);
});

test('test socketcluster returns stable json when the configured socket cannot send', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testSocketcluster(setting_controller_external_probe_request([
        'channel' => 'settings-probe',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'   => 'error',
            'message'  => 'Socket broadcasted message successfully.',
            'channel'  => 'settings-probe',
            'response' => null,
        ]);
});

test('test sentry config rejects invalid dsns before sdk fallback handling', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testSentryConfig(setting_controller_external_probe_request([
        'dsn' => 'not-a-dsn',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'The provided Sentry DSN is invalid.',
        ])
        ->and(config('sentry.dsn'))->toBe('not-a-dsn');
});

test('test sentry config accepts an empty dsn as a local no op probe', function () {
    setting_controller_external_probe_fixtures();

    $response = (new SettingController())->testSentryConfig(setting_controller_external_probe_request([
        'dsn' => null,
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'success',
            'message' => 'Sentry configuration is successful, test Exception sent.',
        ])
        ->and(config('sentry.dsn'))->toBeNull();
});

/**
 * A real Twilio manager that records which credentials a send would use instead of
 * calling Twilio.
 */
class SettingControllerRecordingTwilioManager extends Fleetbase\Twilio\Manager
{
    public static array $sent = [];

    public function message(string $to, string $message, array $mediaUrls = [], array $params = []): Twilio\Rest\Api\V2010\Account\MessageInstance
    {
        $connection     = (new ReflectionProperty(Fleetbase\Twilio\Manager::class, 'settings'))->getValue($this)['twilio'];
        static::$sent[] = ['to' => $to, 'sid' => $connection['sid'], 'token' => $connection['token'], 'from' => $connection['from']];

        throw new RuntimeException('recorded');
    }
}

function setting_controller_bind_recording_twilio(): void
{
    SettingControllerRecordingTwilioManager::$sent = [];

    // Bound the way the Twilio service provider binds the real manager. Under Octane its
    // closure reads the config of the worker's base application, not the request's copy,
    // so it is modelled here with the config captured when the binding was registered.
    $bootConfig = config('twilio.twilio');
    app()->singleton('twilio', fn () => new SettingControllerRecordingTwilioManager($bootConfig['default'] ?? 'twilio', $bootConfig['connections']));

    // Resolved before the test runs with the saved credentials, as happens at boot or in an
    // earlier request handled by the same Octane worker.
    app('twilio');
    Fleetbase\Twilio\Support\Laravel\Facade::getFacadeRoot();
}

function setting_controller_twilio_facade_is_cached(): bool
{
    $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();

    return isset($resolved['twilio']);
}

test('test twilio config sends with the credentials entered, not those the client was built with', function () {
    setting_controller_external_probe_fixtures(['twilio.twilio.default' => 'twilio']);
    setting_controller_bind_recording_twilio();

    $response = (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'entered-sid',
        'token' => 'entered-token',
        'from'  => '+15555550999',
        'phone' => '+15555550123',
    ]));

    expect($response->getData(true)['message'])->toBe('recorded')
        ->and(SettingControllerRecordingTwilioManager::$sent)->toBe([
            ['to' => '+15555550123', 'sid' => 'entered-sid', 'token' => 'entered-token', 'from' => '+15555550999'],
        ])
        ->and(setting_controller_twilio_facade_is_cached())->toBeFalse('the credentials under test do not outlive the request');
});

test('test sms provider config sends through twilio with the credentials entered', function () {
    setting_controller_external_probe_fixtures(['twilio.twilio.default' => 'twilio']);
    setting_controller_bind_recording_twilio();

    $response = (new SettingController())->testSmsProviderConfig(setting_controller_external_probe_request([
        'provider' => 'twilio',
        'phone'    => '+15555550123',
        'config'   => ['sid' => 'entered-sid', 'token' => 'entered-token', 'from' => '+15555550999'],
    ]));

    expect($response->getData(true))->toMatchArray(['status' => 'error', 'message' => 'recorded'])
        ->and(SettingControllerRecordingTwilioManager::$sent)->toBe([
            ['to' => '+15555550123', 'sid' => 'entered-sid', 'token' => 'entered-token', 'from' => '+15555550999'],
        ])
        ->and(setting_controller_twilio_facade_is_cached())->toBeFalse();
});

test('a stand-in bound in place of the twilio manager is left in place', function () {
    setting_controller_external_probe_fixtures();
    $twilio = new SettingControllerTwilioFake();
    app()->instance('twilio', $twilio);

    (new SettingController())->testTwilioConfig(setting_controller_external_probe_request([
        'sid'   => 'entered-sid',
        'token' => 'entered-token',
        'from'  => '+15555550999',
        'phone' => '+15555550123',
    ]));

    expect(app('twilio'))->toBe($twilio)
        ->and($twilio->messages)->toBe([['+15555550123', 'This is a Twilio test from Fleetbase']]);
});

test('a twilio manager bound but not yet built is built from the config just applied', function () {
    setting_controller_external_probe_fixtures(['twilio.twilio.default' => 'twilio']);
    $bootConfig = config('twilio.twilio');
    app()->singleton('twilio', fn () => new SettingControllerRecordingTwilioManager($bootConfig['default'], $bootConfig['connections']));
    config(['twilio.twilio.connections.twilio.sid' => 'entered-sid']);

    $refresh = new ReflectionMethod(SettingController::class, 'refreshTwilioClient');
    $refresh->invoke(new SettingController());

    $manager = app('twilio');
    expect(get_class($manager))->toBe(Fleetbase\Twilio\Manager::class)
        ->and((new ReflectionProperty(Fleetbase\Twilio\Manager::class, 'settings'))->getValue($manager)['twilio']['sid'])->toBe('entered-sid');
});
