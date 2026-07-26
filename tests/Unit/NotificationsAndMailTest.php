<?php

use Fleetbase\Mail\TestMail;
use Fleetbase\Mail\UserCredentialsMail;
use Fleetbase\Mail\VerificationMail;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\Company;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Notifications\PasswordReset;
use Fleetbase\Notifications\TestPushNotification;
use Fleetbase\Notifications\UserAcceptedCompanyInvite;
use Fleetbase\Notifications\UserCreated;
use Fleetbase\Notifications\UserEmailChange;
use Fleetbase\Notifications\UserForgotPassword;
use Fleetbase\Notifications\UserInvited;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function notification_mail_container(): void
{
    bind_test_container([
        'app.name'                    => 'Fleetbase',
        'app.env'                     => 'production',
        'fleetbase.console.host'      => 'console.example.test',
        'fleetbase.console.secure'    => true,
        'fleetbase.console.subdomain' => null,
    ]);
}

function notification_user(array $attributes = []): User
{
    $user = new User();
    $user->setRawAttributes(array_merge([
        'uuid'  => 'user-1',
        'id'    => 1001,
        'name'  => 'Ron Tester',
        'email' => 'ron@example.test',
        'phone' => '+15550000001',
    ], $attributes), true);

    return $user;
}

function notification_company(array $attributes = []): Company
{
    $company = new Company();
    $company->setRawAttributes(array_merge([
        'uuid' => 'company-1',
        'id'   => 2001,
        'name' => 'Acme Logistics',
    ], $attributes), true);

    return $company;
}

function notification_verification_code(array $attributes = []): VerificationCode
{
    $verificationCode = new VerificationCode();
    $verificationCode->setRawAttributes(array_merge([
        'uuid' => 'verification-1',
        'code' => '123456',
        'for'  => 'password_reset',
        'meta' => [],
    ], $attributes), true);

    return $verificationCode;
}

function notification_apn_private_key(): string
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);

    openssl_pkey_export($key, $privateKey);

    return trim($privateKey);
}

function notification_firebase_private_key(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $privateKey);

    return $privateKey;
}

function notification_chat_participant(array $attributes = [], ?User $user = null): ChatParticipant
{
    $participant = new ChatParticipant();
    $participant->setRawAttributes(array_merge([
        'uuid'      => 'participant-1',
        'public_id' => 'chat_participant_1',
        'user_uuid' => data_get($user, 'uuid', 'user-1'),
    ], $attributes), true);

    if ($user) {
        $participant->setRelation('user', $user);
    }

    return $participant;
}

function notification_chat_channel(array $attributes = []): ChatChannel
{
    $channel = new ChatChannel();
    $channel->setRawAttributes(array_merge([
        'uuid'      => 'channel-1',
        'public_id' => 'chat_channel_1',
        'name'      => 'Dispatch Chat',
    ], $attributes), true);

    return $channel;
}

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

test('user created notification exposes mail database and broadcast contracts', function () {
    notification_mail_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 10:30:00'));
    $user    = notification_user(['uuid' => 'user-created-1', 'name' => 'Jane User', 'email' => 'jane@example.test']);
    $company = notification_company(['uuid' => 'company-created-1', 'name' => 'Dispatch Co']);

    $notification = new UserCreated($user, $company);
    $mail         = $notification->toMail($user);
    $array        = $notification->toArray($user);
    $broadcast    = $notification->toBroadcast($user);

    expect($notification->via($user))->toBe(['mail', 'database', 'broadcast'])
        ->and($mail->subject)->toBe('New User Added to Your Organization')
        ->and($mail->introLines)->toContain('A new user has been added to your organization.')
        ->and($mail->introLines)->toContain('Name: Jane User')
        ->and($mail->introLines)->toContain('Email: jane@example.test')
        ->and($array['notification_id'])->toStartWith('notification_')
        ->and($array['sent_at'])->toBe('2026-07-17 10:30:00')
        ->and($array)->toMatchArray([
            'subject'   => 'New User Added to Your Organization',
            'message'   => 'A new user (Jane User) has been added to your organization (Dispatch Co).',
            'id'        => 'user-created-1',
            'email'     => 'jane@example.test',
            'phone'     => '+15550000001',
            'companyId' => 'company-created-1',
            'company'   => 'Dispatch Co',
        ])
        ->and($broadcast)->toBeInstanceOf(BroadcastMessage::class)
        ->and($broadcast->data)->toBe($array);
});

test('invite notifications build console URLs mail actions and compact array payloads', function () {
    notification_mail_container();
    $recipient = notification_user(['name' => 'Recipient User']);
    $sender    = notification_user(['uuid' => 'sender-1', 'id' => 1002, 'name' => 'Sender User', 'email' => 'sender@example.test']);
    $company   = notification_company(['uuid' => 'company-invite-1', 'id' => 2002, 'name' => 'Invite Co']);

    $invite = new Invite();
    $invite->setRawAttributes([
        'uuid'      => 'invite-1',
        'public_id' => 'invite_public_1',
        'uri'       => 'join-token',
        'code'      => 'INV1234',
    ], true);
    $invite->setRelation('subject', $company);
    $invite->setRelation('createdBy', $sender);

    $invited      = new UserInvited($invite);
    $invitedMail  = $invited->toMail($recipient);
    $accepted     = new UserAcceptedCompanyInvite($company, $recipient);
    $acceptedMail = $accepted->toMail($sender);

    expect($invited->via($recipient))->toBe(['mail'])
        ->and($invited->url)->toBe('https://console.example.test/join/org/join-token')
        ->and($invitedMail->subject)->toBe('You\'ve been invited to join Invite Co on Fleetbase!')
        ->and($invitedMail->greeting)->toBe('Hello, Recipient User!')
        ->and($invitedMail->introLines)->toContain('Your invitiation code: INV1234')
        ->and($invitedMail->actionText)->toBe('Accept Invitation')
        ->and($invitedMail->actionUrl)->toBe('https://console.example.test/join/org/join-token')
        ->and($invited->toArray($recipient))->toBe([
            'invite_id'  => 'invite-1',
            'subject_id' => 'invite-1',
        ])
        ->and($accepted->via($sender))->toBe(['mail'])
        ->and($acceptedMail->subject)->toBe('Recipient User has joined Invite Co on Fleetbase!')
        ->and($acceptedMail->actionText)->toBe('View Team Members')
        ->and($acceptedMail->actionUrl)->toBe('https://console.example.test/iam/users')
        ->and($accepted->toArray($sender))->toBe([
            'company_id' => 2002,
            'user_id'    => 1001,
        ]);
});

test('password and email-change notifications expose reset and confirmation contracts', function () {
    notification_mail_container();
    $user             = notification_user(['name' => 'Reset User']);
    $verificationCode = notification_verification_code();
    $verificationCode->setRelation('subject', $user);

    $forgotPassword = new UserForgotPassword($verificationCode);
    $passwordReset  = new PasswordReset($verificationCode);

    $emailChangeCode = notification_verification_code([
        'uuid' => 'email-change-1',
        'code' => '654321',
        'for'  => 'email_change',
        'meta' => ['old_email' => 'old@example.test', 'new_email' => 'new@example.test'],
    ]);
    $emailChangeCode->setRelation('subject', $user);
    $emailChange = new UserEmailChange($emailChangeCode);

    expect($forgotPassword->via($user))->toBe(['mail'])
        ->and($forgotPassword->url)->toBe('https://console.example.test/auth/reset-password/verification-1?code=123456')
        ->and($forgotPassword->toMail($user)->subject)->toBe('Your password reset link for Fleetbase')
        ->and($forgotPassword->toMail($user)->actionText)->toBe('Reset Password')
        ->and($forgotPassword->toArray($user))->toBe(['code' => '123456'])
        ->and($passwordReset->via($user))->toBe(['mail'])
        ->and($passwordReset->url)->toBe('https://console.example.test/auth/reset-password/verification-1?code=123456')
        ->and($passwordReset->toMail($user)->subject)->toBe('Your password reset link for Fleetbase')
        ->and($passwordReset->toArray($user))->toBe(['code' => '123456'])
        ->and($emailChange->via($user))->toBe(['mail'])
        ->and($emailChange->url)->toBe('https://console.example.test/auth/confirm-email-change/email-change-1?code=654321')
        ->and($emailChange->toMail($user)->subject)->toBe('Confirm your Fleetbase email change')
        ->and($emailChange->toMail($user)->introLines)->toContain('Current login email: old@example.test')
        ->and($emailChange->toMail($user)->introLines)->toContain('New login email: new@example.test')
        ->and($emailChange->toMail($user)->actionText)->toBe('Confirm Email Change')
        ->and($emailChange->toArray($user))->toBe(['code' => '654321']);
});

test('mailables expose envelopes markdown views and view data contracts', function () {
    notification_mail_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 15:45:00'));

    $user    = notification_user(['name' => 'Mail User', 'email' => 'mail@example.test']);
    $company = notification_company(['name' => 'Mail Co']);
    $user->setRelation('company', $company);
    $verificationCode = notification_verification_code(['code' => '777888', 'for' => 'email_verification']);
    $verificationCode->setRelation('subject', $user);

    $testMail     = new TestMail($user, 'mailgun');
    $testEnvelope = $testMail->envelope();
    $testContent  = $testMail->content();

    $credentialsMail     = new UserCredentialsMail('plain-secret', $user);
    $credentialsEnvelope = $credentialsMail->envelope();
    $credentialsContent  = $credentialsMail->content();

    $verificationMail = (new VerificationMail($verificationCode, 'Use this code to continue.'))->build();

    expect($testEnvelope->subject)->toBe('🎉 Your Fleetbase Mail Configuration Works!')
        ->and($testEnvelope->to[0]->address)->toBe('mail@example.test')
        ->and($testEnvelope->to[0]->name)->toBe('Mail User')
        ->and($testContent->markdown)->toBe('fleetbase::mail.test')
        ->and($testContent->with['mailer'])->toBe('mailgun')
        ->and($testContent->with['currentHour'])->toBe(15)
        ->and($credentialsEnvelope->subject)->toBe('Your login credentials for Mail Co on Fleetbase')
        ->and($credentialsContent->markdown)->toBe('fleetbase::mail.user-credentials')
        ->and($credentialsContent->with['plaintextPassword'])->toBe('plain-secret')
        ->and($credentialsContent->with['currentHour'])->toBe(15)
        ->and($verificationMail->subject)->toBe('777888 is your Fleetbase verification code')
        ->and($verificationMail->markdown)->toBe('fleetbase::mail.verification')
        ->and($verificationMail->viewData)->toMatchArray([
            'appName'     => 'Fleetbase',
            'currentHour' => 15,
            'user'        => $user,
            'code'        => '777888',
            'type'        => 'email_verification',
            'content'     => 'Use this code to continue.',
        ]);
});

test('test push notification exposes mobile delivery channels and deterministic payload metadata', function () {
    notification_mail_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 18:22:10'));

    $notification = new TestPushNotification('Test title', 'Test body');

    expect($notification->via())->toBe([
        NotificationChannels\Fcm\FcmChannel::class,
        NotificationChannels\Apn\ApnChannel::class,
    ])
        ->and($notification->title)->toBe('Test title')
        ->and($notification->message)->toBe('Test body')
        ->and($notification->data['id'])->toBeString()->not->toBe('')
        ->and($notification->data)->toMatchArray([
            'message' => 'Test Push Notification',
            'type'    => 'test',
            'date'    => '2026-07-17 18:22:10',
        ]);
});

test('test push notification builds apn messages with configured action payload', function () {
    bind_test_container([
        'app.env'                      => 'local',
        'broadcasting.connections.apn' => [
            'key_id'              => 'ABC123DEFG',
            'team_id'             => 'TEAM123456',
            'app_bundle_id'       => 'com.fleetbase.test',
            'private_key_content' => notification_apn_private_key(),
            'production'          => false,
        ],
    ]);
    Carbon::setTestNow(Carbon::parse('2026-07-17 18:22:10'));

    $message = (new TestPushNotification('Test title', 'Test body'))->toApn(notification_user());
    $custom  = $message->custom;

    expect($message)->toBeInstanceOf(NotificationChannels\Apn\ApnMessage::class)
        ->and($message->title)->toBe('Test title')
        ->and($message->body)->toBe('Test body')
        ->and($message->badge)->toBe(1)
        ->and($custom)->toMatchArray([
            'message' => 'Test Push Notification',
            'type'    => 'test',
            'date'    => '2026-07-17 18:22:10',
        ])
        ->and($custom['id'])->toBeString()->not->toBe('')
        ->and($custom['action'])->toBe([
            'action' => 'test_push_notification',
            'params' => [
                'id'      => $custom['id'],
                'message' => 'Test Push Notification',
                'type'    => 'test',
                'date'    => '2026-07-17 18:22:10',
            ],
        ]);
});

test('test push notification builds fcm messages with configured metadata payload', function () {
    bind_test_container([
        'firebase.projects.app' => [
            'project_id'  => 'fleetbase-test',
            'credentials' => [
                'type'                        => 'service_account',
                'project_id'                  => 'fleetbase-test',
                'private_key_id'              => 'test-key-id',
                'private_key'                 => notification_firebase_private_key(),
                'client_email'                => 'firebase@test.invalid',
                'client_id'                   => '1234567890',
                'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri'                   => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url'        => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase%40test.invalid',
            ],
            'credentials_file' => '/tmp/unused-firebase.json',
        ],
    ]);
    Carbon::setTestNow(Carbon::parse('2026-07-17 18:22:10'));

    $message = (new TestPushNotification('Test title', 'Test body'))->toFcm(notification_user());

    expect($message)->toBeInstanceOf(NotificationChannels\Fcm\FcmMessage::class)
        ->and($message->notification->title)->toBe('Test title')
        ->and($message->notification->body)->toBe('Test body')
        ->and($message->data)->toMatchArray([
            'message' => 'Test Push Notification',
            'type'    => 'test',
            'date'    => '2026-07-17 18:22:10',
        ])
        ->and($message->data['id'])->toBeString()->not->toBe('')
        ->and($message->toArray())->toMatchArray([
            'notification' => [
                'title' => 'Test title',
                'body'  => 'Test body',
            ],
            'android' => [
                'notification' => [
                    'color' => '#4391EA',
                    'sound' => 'default',
                ],
                'fcm_options' => [
                    'analytics_label' => 'analytics',
                ],
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                    ],
                ],
                'fcm_options' => [
                    'analytics_label' => 'analytics',
                ],
            ],
        ])
        ->and(config('firebase.projects.app'))->not->toHaveKey('credentials_file');
});

test('chat message received notification exposes broadcast channels and stable payload shape', function () {
    notification_mail_container();
    config()->set('broadcasting.connections.apn', [
        'key_id'              => 'ABC123DEFG',
        'team_id'             => 'TEAM123456',
        'app_bundle_id'       => 'com.fleetbase.test',
        'private_key_content' => notification_apn_private_key(),
        'production'          => false,
    ]);
    config()->set('firebase.projects.app', [
        'project_id'  => 'fleetbase-test',
        'credentials' => [
            'type'                        => 'service_account',
            'project_id'                  => 'fleetbase-test',
            'private_key_id'              => 'test-key-id',
            'private_key'                 => notification_firebase_private_key(),
            'client_email'                => 'firebase@test.invalid',
            'client_id'                   => '1234567890',
            'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri'                   => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url'        => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase%40test.invalid',
        ],
    ]);
    $senderUser = notification_user([
        'uuid'  => 'sender-user-1',
        'name'  => 'Dispatcher Dana',
        'email' => 'dana@example.test',
    ]);
    $sender = notification_chat_participant([
        'uuid'      => 'sender-participant-1',
        'public_id' => 'chat_participant_sender',
    ], $senderUser);
    $recipient = notification_chat_participant([
        'uuid'      => 'recipient-participant-1',
        'public_id' => 'chat_participant_recipient',
    ], notification_user([
        'uuid'  => 'recipient-user-1',
        'name'  => 'Driver Riley',
        'email' => 'riley@example.test',
    ]));
    $channel = notification_chat_channel([
        'uuid'      => 'chat-channel-uuid-1',
        'public_id' => 'chat_channel_public_1',
    ]);

    $message = new ChatMessage();
    $message->setRawAttributes([
        'uuid'              => 'message-uuid-1',
        'public_id'         => 'chat_message_public_1',
        'sender_uuid'       => 'sender-participant-1',
        'chat_channel_uuid' => 'chat-channel-uuid-1',
        'content'           => 'Package is loaded.',
        'created_at'        => Carbon::parse('2026-07-17 18:30:00'),
    ], true);
    $message->setRelation('sender', $sender);
    $message->setRelation('chatChannel', $channel);

    $notification = new ChatMessageReceived($message, $recipient);
    $channels     = $notification->broadcastOn();
    $array        = $notification->toArray();
    $fcmMessage   = $notification->toFcm($recipient->user);
    $apnMessage   = $notification->toApn($recipient->user);

    expect($notification->via($recipient->user))->toBe([
        'broadcast',
        NotificationChannels\Fcm\FcmChannel::class,
        NotificationChannels\Apn\ApnChannel::class,
    ])
        ->and(array_map(fn ($channel) => $channel->name, $channels))->toBe([
            'chat_channel.chat-channel-uuid-1',
            'chat_channel.chat_channel_public_1',
            'chat_participant.recipient-participant-1',
            'chat_participant.chat_participant_recipient',
        ])
        ->and($array['title'])->toBe('Message from Dispatcher Dana')
        ->and($array['body'])->toBe('Package is loaded.')
        ->and($array['event'])->toBe('chat_participent.chat_message_received')
        ->and($array['data'])->toMatchArray([
            'id'        => 'chat_message_public_1',
            'type'      => 'chat_message_received',
            'sender'    => 'chat_participant_sender',
            'recipient' => 'chat_participant_recipient',
            'channel'   => 'chat_channel_public_1',
        ])
        ->and(collect($array['data']['message'])->except('sent_at')->all())->toBe([
            'sender'    => 'chat_participant_sender',
            'recipient' => 'chat_participant_recipient',
            'channel'   => 'chat_channel_public_1',
            'content'   => 'Package is loaded.',
        ])
        ->and($array['data']['message']['sent_at'])->toBeInstanceOf(Carbon::class)
        ->and($array['data']['message']['sent_at']->toDateTimeString())->toBe('2026-07-17 18:30:00')
        ->and($fcmMessage)->toBeInstanceOf(NotificationChannels\Fcm\FcmMessage::class)
        ->and($fcmMessage->notification->title)->toBe('Message from Dispatcher Dana')
        ->and($fcmMessage->notification->body)->toBe('Package is loaded.')
        ->and($fcmMessage->data)->toMatchArray([
            'type'      => 'chat_message_received',
            'recipient' => 'chat_participant_recipient',
            'channel'   => 'chat_channel_public_1',
        ])
        ->and($apnMessage)->toBeInstanceOf(NotificationChannels\Apn\ApnMessage::class)
        ->and($apnMessage->title)->toBe('Message from Dispatcher Dana')
        ->and($apnMessage->body)->toBe('Package is loaded.')
        ->and($apnMessage->custom)->toMatchArray([
            'type'      => 'chat_message_received',
            'recipient' => 'chat_participant_recipient',
            'channel'   => 'chat_channel_public_1',
        ])
        ->and($apnMessage->custom['action']['action'])->toBe('chat_message_received')
        ->and($apnMessage->custom['action']['params'])->toMatchArray([
            'type'      => 'chat_message_received',
            'recipient' => 'chat_participant_recipient',
            'channel'   => 'chat_channel_public_1',
        ]);
});
