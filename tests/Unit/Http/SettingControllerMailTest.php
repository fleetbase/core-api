<?php

use Fleetbase\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Mail\TestMail;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class SettingControllerMailFake
{
    public ?string $exceptionMessage = null;

    public array $sent = [];

    public function send(mixed $mailable): void
    {
        if ($this->exceptionMessage) {
            throw new RuntimeException($this->exceptionMessage);
        }

        $this->sent[] = $mailable;
    }
}

function setting_controller_mail_fixtures(): SettingControllerMailFake
{
    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }

    $container = bind_test_container([
        'mail.default'                         => 'smtp',
        'mail.from'                            => [
            'address' => 'noreply@example.test',
            'name'    => 'Fleetbase Test',
        ],
        'mail.mailers'                         => [
            'smtp'            => [
                'transport'  => 'smtp',
                'host'       => 'smtp.example.test',
                'port'       => 587,
                'encryption' => 'tls',
                'username'   => 'smtp-user',
                'password'   => 'smtp-secret',
            ],
            'microsoft-graph' => [
                'transport' => 'microsoft-graph',
                'tenant'    => 'tenant-id',
                'client_id' => 'client-id',
            ],
            'mailgun'         => ['transport' => 'mailgun'],
            'log'             => ['transport' => 'log'],
            'array'           => ['transport' => 'array'],
            'failover'        => ['transport' => 'failover'],
        ],
        'mail.mailers.smtp'                    => [
            'transport'  => 'smtp',
            'host'       => 'smtp.example.test',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => 'smtp-user',
            'password'   => 'smtp-secret',
        ],
        'mail.mailers.microsoft-graph'         => [
            'transport' => 'microsoft-graph',
            'tenant'    => 'tenant-id',
            'client_id' => 'client-id',
        ],
        'services.mailgun'                     => [
            'domain' => 'mg.example.test',
            'secret' => 'mailgun-secret',
        ],
        'services.postmark'                    => [
            'token' => 'postmark-token',
        ],
        'services.sendgrid'                    => [
            'key' => 'sendgrid-key',
        ],
        'services.resend'                      => [
            'key' => 'resend-key',
        ],
    ]);

    $mail = new SettingControllerMailFake();
    $container->instance('mail.manager', $mail);
    $container->instance('mailer', $mail);
    Facade::clearResolvedInstance('mail.manager');
    Facade::clearResolvedInstance('mailer');

    return $mail;
}

function setting_controller_mail_request(array $input = [], ?User $user = null): AdminRequest
{
    $request = AdminRequest::create('/int/v1/settings/mail', 'POST', $input);
    $request->setUserResolver(fn () => $user ?? setting_controller_mail_user());

    return $request;
}

function setting_controller_mail_user(): User
{
    $user = new User();
    $user->setRawAttributes([
        'uuid'  => '11111111-1111-4111-8111-111111111111',
        'name'  => 'Mail Admin',
        'email' => 'mail-admin@example.test',
        'type'  => 'admin',
    ], true);

    return $user;
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('mail config response flattens provider configuration without transport fields', function () {
    setting_controller_mail_fixtures();

    $response = (new SettingController())->getMailConfig(setting_controller_mail_request());
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload)->toMatchArray([
            'mailer'                  => 'smtp',
            'fromAddress'             => 'noreply@example.test',
            'fromName'                => 'Fleetbase Test',
            'smtpHost'                => 'smtp.example.test',
            'smtpPort'                => 587,
            'smtpEncryption'          => 'tls',
            'smtpUsername'            => 'smtp-user',
            'smtpPassword'            => 'smtp-secret',
            'microsoftGraphTenant'    => 'tenant-id',
            'microsoftGraphClient_id' => 'client-id',
            'mailgunDomain'           => 'mg.example.test',
            'mailgunSecret'           => 'mailgun-secret',
            'postmarkToken'           => 'postmark-token',
            'sendgridKey'             => 'sendgrid-key',
            'resendKey'               => 'resend-key',
        ])
        ->and($payload)->not->toHaveKey('smtpTransport')
        ->and($payload)->not->toHaveKey('microsoftGraphTransport');
});

test('test mail config applies microsoft graph config and sends the test mailable', function () {
    $mail = setting_controller_mail_fixtures();
    $user = setting_controller_mail_user();

    $response = (new SettingController())->testMailConfig(setting_controller_mail_request([
        'mailer'         => 'microsoft-graph',
        'from'           => [
            'address' => 'ops@example.test',
            'name'    => 'Operations',
        ],
        'smtp'           => [
            'host' => 'smtp.override.test',
        ],
        'microsoftGraph' => [
            'tenant'    => 'tenant-override',
            'client_id' => 'client-override',
        ],
    ], $user));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'success',
            'message' => 'Mail configuration is successful, check your inbox for the test email to confirm.',
        ])
        ->and(config('mail.default'))->toBe('microsoft-graph')
        ->and(config('mail.from'))->toBe([
            'address' => 'ops@example.test',
            'name'    => 'Operations',
        ])
        ->and(config('mail.mailers.smtp'))->toBe([
            'transport' => 'smtp',
            'host'      => 'smtp.override.test',
        ])
        ->and(config('mail.mailers.microsoft-graph'))->toBe([
            'transport' => 'microsoft-graph',
            'tenant'    => 'tenant-override',
            'client_id' => 'client-override',
        ])
        ->and($mail->sent)->toHaveCount(1)
        ->and($mail->sent[0])->toBeInstanceOf(TestMail::class)
        ->and($mail->sent[0]->user)->toBe($user)
        ->and($mail->sent[0]->sendingMailer)->toBe('microsoft-graph');
});

test('test mail config applies provider service config before sending', function (string $mailer, string $configKey, array $providerConfig) {
    $mail = setting_controller_mail_fixtures();

    $response = (new SettingController())->testMailConfig(setting_controller_mail_request([
        'mailer'   => $mailer,
        $configKey => $providerConfig,
        'from'     => [
            'address' => 'noreply@example.test',
            'name'    => 'Fleetbase Test',
        ],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['status'])->toBe('success')
        ->and(config('services.' . $configKey))->toBe($providerConfig)
        ->and($mail->sent)->toHaveCount(1)
        ->and($mail->sent[0]->sendingMailer)->toBe($mailer);
})->with([
    ['mailgun', 'mailgun', ['domain' => 'mailgun.override.test', 'secret' => 'secret']],
    ['postmark', 'postmark', ['token' => 'postmark-override']],
    ['sendgrid', 'sendgrid', ['key' => 'sendgrid-override']],
    ['resend', 'resend', ['key' => 'resend-override']],
]);

test('test mail config returns mail transport errors as a stable json response', function () {
    $mail                   = setting_controller_mail_fixtures();
    $mail->exceptionMessage = 'SMTP authentication failed';

    $response = (new SettingController())->testMailConfig(setting_controller_mail_request([
        'mailer' => 'smtp',
        'from'   => [
            'address' => 'noreply@example.test',
            'name'    => 'Fleetbase Test',
        ],
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'status'  => 'error',
            'message' => 'SMTP authentication failed',
        ])
        ->and($mail->sent)->toBe([]);
});
