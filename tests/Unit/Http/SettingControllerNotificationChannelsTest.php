<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Notifications\TestPushNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Facade;

class SettingControllerNotificationDispatcherFake implements Dispatcher
{
    public ?string $exceptionMessage = null;

    public array $sent = [];

    public function send($notifiables, $notification): void
    {
        if ($this->exceptionMessage) {
            throw new RuntimeException($this->exceptionMessage);
        }

        $this->sent[] = [$notifiables, $notification];
    }

    public function sendNow($notifiables, $notification, ?array $channels = null): void
    {
        $this->send($notifiables, $notification);
    }
}

function setting_controller_notification_fixtures(): SettingControllerNotificationDispatcherFake
{
    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }

    $container = bind_test_container([
        'broadcasting.connections.apn' => [
            'key_id'        => 'apn-key-id',
            'team_id'       => 'apn-team-id',
            'app_bundle_id' => 'com.fleetbase.test',
        ],
        'firebase.projects.app'       => [
            'project_id' => 'fleetbase-test',
        ],
    ]);

    $dispatcher = new SettingControllerNotificationDispatcherFake();
    $container->instance(Dispatcher::class, $dispatcher);
    Facade::clearResolvedInstances();

    return $dispatcher;
}

function setting_controller_notification_request(array $input = []): AdminRequest
{
    return AdminRequest::create('/int/v1/settings/notification-channels', 'POST', $input);
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('notification channels config response exposes apn and firebase settings', function () {
    setting_controller_notification_fixtures();

    $response = (new SettingController())->getNotificationChannelsConfig(setting_controller_notification_request());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'apn'      => [
                'key_id'        => 'apn-key-id',
                'team_id'       => 'apn-team-id',
                'app_bundle_id' => 'com.fleetbase.test',
            ],
            'firebase' => [
                'project_id' => 'fleetbase-test',
            ],
        ]);
});

test('test notification channels applies request config removes file-only keys and dispatches push notification', function () {
    $dispatcher = setting_controller_notification_fixtures();

    $response = (new SettingController())->testNotificationChannelsConfig(setting_controller_notification_request([
        'title'     => 'Dispatch title',
        'message'   => 'Dispatch body',
        'apnToken'  => 'apn-device-token',
        'fcmToken'  => 'fcm-device-token',
        'apn'       => [
            'key_id'              => 'apn-request-key',
            'team_id'             => 'apn-request-team',
            'app_bundle_id'       => 'com.fleetbase.request',
            'private_key_path'    => '/tmp/request-key.p8',
            'private_key_file'    => ['uuid' => 'file-object-should-not-persist'],
            'private_key_file_id' => 'not-a-uuid',
        ],
        'firebase'  => [
            'project_id'          => 'fleetbase-request',
            'credentials_file'    => '/tmp/request-firebase.json',
            'credentials_file_id' => 'also-not-a-uuid',
        ],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'success',
            'message' => 'Notification sent successfully.',
        ])
        ->and(config('broadcasting.connections.apn'))->toBe([
            'key_id'              => 'apn-request-key',
            'team_id'             => 'apn-request-team',
            'app_bundle_id'       => 'com.fleetbase.request',
            'private_key_file_id' => 'not-a-uuid',
        ])
        ->and(config('firebase.projects.app'))->toBe([
            'project_id'          => 'fleetbase-request',
            'credentials_file_id' => 'also-not-a-uuid',
        ])
        ->and($dispatcher->sent)->toHaveCount(1)
        ->and($dispatcher->sent[0][0])->toBeInstanceOf(AnonymousNotifiable::class)
        ->and($dispatcher->sent[0][0]->routeNotificationFor('apn'))->toBe('apn-device-token')
        ->and($dispatcher->sent[0][0]->routeNotificationFor('fcm'))->toBe('fcm-device-token')
        ->and($dispatcher->sent[0][1])->toBeInstanceOf(TestPushNotification::class)
        ->and($dispatcher->sent[0][1]->title)->toBe('Dispatch title')
        ->and($dispatcher->sent[0][1]->message)->toBe('Dispatch body');
});

test('test notification channels returns notification dispatcher errors as stable json', function () {
    $dispatcher                   = setting_controller_notification_fixtures();
    $dispatcher->exceptionMessage = 'APN credentials rejected';

    $response = (new SettingController())->testNotificationChannelsConfig(setting_controller_notification_request([
        'apnToken' => 'apn-device-token',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'APN credentials rejected',
        ])
        ->and($dispatcher->sent)->toBe([]);
});
