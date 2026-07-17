<?php

use Fleetbase\Mail\TestMail;
use Fleetbase\Mail\UserCredentialsMail;
use Fleetbase\Mail\VerificationMail;
use Fleetbase\Models\Company;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\PasswordReset;
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
        ->and($passwordReset->url)->toBe('https://console.example.test/auth/reset-password/verification-1?code=123456')
        ->and($passwordReset->toMail($user)->subject)->toBe('Your password reset link for Fleetbase')
        ->and($passwordReset->toArray($user))->toBe(['code' => '123456'])
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
