<?php

use Fleetbase\Support\PushNotification;

it('configures fcm client options and removes file path credentials from runtime config', function () {
    bind_test_container([
        'firebase.projects.app' => [
            'project_id'        => 'fleetbase-test',
            'credentials'       => ['client_email' => 'firebase@test.invalid'],
            'credentials_file'  => '/tmp/firebase.json',
            'credentials_file_id' => 'not-a-file-uuid',
        ],
    ]);

    $config = PushNotification::configureFcmClient();

    expect($config)->toBe([
        'project_id'          => 'fleetbase-test',
        'credentials'         => ['client_email' => 'firebase@test.invalid'],
        'credentials_file_id' => 'not-a-file-uuid',
    ])->and(config('firebase.projects.app'))->toBe($config);
});
