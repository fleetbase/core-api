<?php

use Fleetbase\Events\AccountCreated;
use Fleetbase\Listeners\HandleAccountCreated;
use Fleetbase\Mail\VerificationMail;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Services\SmsService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class VerificationCodeModelTaggedCacheFake
{
    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return true;
    }

    public function increment(string $key, mixed $value = 1): int
    {
        return (int) $value;
    }
}

class VerificationCodeModelResponseCacheFake
{
    public function clear(): void
    {
    }
}

class VerificationCodeModelMailerFake
{
    public array $recipients = [];

    public array $calls = [];

    public array $sent = [];

    public ?Throwable $sendException = null;

    public function to(mixed $recipient): self
    {
        $this->recipients[] = $recipient;

        return $this;
    }

    public function cc(mixed $recipient): self
    {
        $this->calls[] = ['cc', $recipient];

        return $this;
    }

    public function bcc(mixed $recipient): self
    {
        $this->calls[] = ['bcc', $recipient];

        return $this;
    }

    public function send(mixed $mail): void
    {
        if ($this->sendException) {
            throw $this->sendException;
        }

        $this->sent[] = $mail;
    }
}

class VerificationCodeModelTwilioFake
{
    public array $messages = [];

    public ?Throwable $sendException = null;

    public function message(string $to, string $message, array $mediaUrls = [], array $params = []): object
    {
        $this->messages[] = compact('to', 'message', 'mediaUrls', 'params');

        if ($this->sendException) {
            throw $this->sendException;
        }

        return (object) ['sid' => 'SM-verification-code'];
    }
}

function verification_code_model_database(): Capsule
{
    EloquentModel::clearBootedModels();
    session()->flush();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => $connection,
        'database.connections.mysql'   => $connection,
        'fleetbase.connection.db'      => 'testing',
    ]);

    $cache = new VerificationCodeModelTaggedCacheFake();
    $container->instance('cache', $cache);
    $container->instance('responsecache', new VerificationCodeModelResponseCacheFake());
    Cache::swap($cache);
    Facade::clearResolvedInstance('log');

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('verification_codes', function ($table) {
        $table->string('uuid')->primary();
        $table->string('subject_uuid')->nullable()->index();
        $table->string('subject_type')->nullable();
        $table->string('code')->nullable();
        $table->string('for')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->text('meta')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    $companySchema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $companySchema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('slug')->nullable();
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    return $capsule;
}

function verification_code_subject(array $attributes = []): User
{
    $user = new User();
    $user->setRawAttributes(array_merge([
        'uuid' => 'user-1',
        'name' => 'Ada Lovelace',
    ], $attributes), true);

    return $user;
}

function verification_code_company(array $attributes = []): Company
{
    $company = new Company();
    $company->setRawAttributes(array_merge([
        'uuid' => 'company-1',
        'name' => 'Acme Logistics',
    ], $attributes), true);

    return $company;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('generates unsaved verification codes with subject purpose and pending status', function () {
    bind_test_container();

    $subject = verification_code_subject();
    $code    = VerificationCode::generateFor($subject, 'login_challenge', false);

    expect($code)->toBeInstanceOf(VerificationCode::class)
        ->and($code->for)->toBe('login_challenge')
        ->and($code->status)->toBe('pending')
        ->and($code->subject_uuid)->toBe('user-1')
        ->and($code->subject_type)->toBe(User::class)
        ->and($code->exists)->toBeFalse()
        ->and($code->code)->toBeNull();
});

it('persists verification codes with generated six digit codes', function () {
    verification_code_model_database();

    $subject = verification_code_subject(['uuid' => 'user-2']);
    $code    = VerificationCode::generateFor($subject, 'device_pairing', true);

    expect($code->exists)->toBeTrue()
        ->and($code->uuid)->toBeString()
        ->and($code->for)->toBe('device_pairing')
        ->and($code->status)->toBe('pending')
        ->and($code->subject_uuid)->toBe('user-2')
        ->and($code->subject_type)->toBe(User::class)
        ->and((string) $code->code)->toMatch('/^[1-9][0-9]{5}$/')
        ->and(VerificationCode::query()->whereKey($code->uuid)->exists())->toBeTrue();
});

it('generates email verification records with default and explicit options without requiring an email recipient', function () {
    verification_code_model_database();
    Carbon::setTestNow(Carbon::parse('2026-06-06 10:00:00', 'UTC'));

    $subject = verification_code_subject(['uuid' => 'user-3']);
    $default = VerificationCode::generateEmailVerificationFor($subject);

    $explicit = VerificationCode::generateEmailVerificationFor($subject, 'security_review', [
        'expireAfter' => Carbon::parse('2026-06-07 12:30:00', 'UTC'),
        'meta'        => ['ip' => '127.0.0.1'],
        'status'      => 'queued',
    ]);

    expect($default->exists)->toBeTrue()
        ->and($default->for)->toBe('email_verification')
        ->and($default->status)->toBe('active')
        ->and($default->expires_at->toDateTimeString())->toBe('2026-06-06 11:00:00')
        ->and($default->meta)->toBe([])
        ->and((string) $default->code)->toMatch('/^[1-9][0-9]{5}$/')
        ->and($explicit->for)->toBe('security_review')
        ->and($explicit->status)->toBe('queued')
        ->and($explicit->expires_at->toDateTimeString())->toBe('2026-06-07 12:30:00')
        ->and($explicit->meta)->toBe(['ip' => '127.0.0.1']);

    Carbon::setTestNow();
});

it('sends email verification with callback content recipient override and supported mailer options', function () {
    verification_code_model_database();
    Carbon::setTestNow(Carbon::parse('2026-06-08 08:00:00', 'UTC'));

    $mailer    = new VerificationCodeModelMailerFake();
    $container = Illuminate\Container\Container::getInstance();
    $container->instance('mail.manager', $mailer);

    $subject = verification_code_subject([
        'uuid'  => 'user-mail-1',
        'email' => 'recipient@example.test',
    ]);

    $code = VerificationCode::generateEmailVerificationFor($subject, 'account_recovery', [
        'to'              => 'security@example.test',
        'subject'         => fn (VerificationCode $verificationCode) => 'Challenge ' . $verificationCode->code,
        'messageCallback' => fn (VerificationCode $verificationCode) => 'Use ' . $verificationCode->code . ' to recover access.',
        'cc'              => 'ops@example.test',
        'bcc'             => 'audit@example.test',
        'meta'            => ['request_id' => 'req-1'],
    ]);

    expect($code->exists)->toBeTrue()
        ->and($code->for)->toBe('account_recovery')
        ->and($code->status)->toBe('active')
        ->and($code->expires_at->toDateTimeString())->toBe('2026-06-08 09:00:00')
        ->and($code->meta)->toBe(['request_id' => 'req-1'])
        ->and($mailer->recipients)->toBe(['security@example.test'])
        ->and($mailer->calls)->toBe([
            ['cc', 'ops@example.test'],
            ['bcc', 'audit@example.test'],
        ])
        ->and($mailer->sent)->toHaveCount(1)
        ->and($mailer->sent[0])->toBeInstanceOf(VerificationMail::class)
        ->and($mailer->sent[0]->verificationCode)->toBe($code)
        ->and($mailer->sent[0]->content)->toBe('Use ' . $code->code . ' to recover access.');

    Carbon::setTestNow();
});

it('sends sms verification through explicit provider with company twilio sender and callback message', function () {
    verification_code_model_database();
    Carbon::setTestNow(Carbon::parse('2026-06-09 12:00:00', 'UTC'));
    session(['company' => 'company-sms-1']);
    config([
        'app.name'             => 'Fleetbase',
        'sms.default_provider' => SmsService::PROVIDER_TWILIO,
        'sms.routing_rules'    => [],
    ]);

    Illuminate\Container\Container::getInstance()->make('db')->connection('mysql')->table('companies')->insert([
        'uuid'       => 'company-sms-1',
        'public_id'  => 'company_sms_1',
        'slug'       => 'company-sms-1',
        'options'    => json_encode([
            'alpha_numeric_sender_id_enabled' => true,
            'alpha_numeric_sender_id'         => 'FLEETBASE',
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $twilio    = new VerificationCodeModelTwilioFake();
    $container = Illuminate\Container\Container::getInstance();
    $container->instance('twilio', $twilio);
    Facade::clearResolvedInstance('twilio');

    $subject = verification_code_subject([
        'uuid'  => 'user-sms-1',
        'phone' => '+15550002222',
    ]);

    $code = VerificationCode::generateSmsVerificationFor($subject, 'phone_login', [
        'provider'        => SmsService::PROVIDER_TWILIO,
        'expireAfter'     => Carbon::parse('2026-06-09 12:15:00', 'UTC'),
        'meta'            => ['channel' => 'sms'],
        'messageCallback' => fn (VerificationCode $verificationCode) => 'Code: ' . $verificationCode->code,
    ]);

    expect($code->exists)->toBeTrue()
        ->and($code->for)->toBe('phone_login')
        ->and($code->expires_at->toDateTimeString())->toBe('2026-06-09 12:15:00')
        ->and($code->meta)->toBe(['channel' => 'sms'])
        ->and($twilio->messages)->toHaveCount(1)
        ->and($twilio->messages[0])->toMatchArray([
            'to'        => '+15550002222',
            'message'   => 'Code: ' . $code->code,
            'mediaUrls' => [],
            'params'    => ['from' => 'FLEETBASE'],
        ]);

    Carbon::setTestNow();
});

it('persists sms verification and rethrows provider failures after logging the phone context', function () {
    verification_code_model_database();
    config([
        'app.name'             => 'Fleetbase',
        'sms.default_provider' => SmsService::PROVIDER_TWILIO,
        'sms.routing_rules'    => [],
    ]);

    Illuminate\Container\Container::getInstance()->instance('twilio', new class {
        public function message(): never
        {
            throw new RuntimeException('Twilio unavailable');
        }
    });
    Facade::clearResolvedInstance('twilio');

    $subject = verification_code_subject([
        'uuid'  => 'user-sms-error',
        'phone' => '+15550003333',
    ]);

    expect(fn () => VerificationCode::generateSmsVerificationFor($subject))
        ->toThrow(RuntimeException::class, 'Twilio unavailable')
        ->and(VerificationCode::query()->count())->toBe(1);

    $log                 = Illuminate\Container\Container::getInstance()->make('log');
    $verificationFailure = collect($log->entries)->first(
        fn (array $entry) => $entry[0] === 'error' && $entry[1] === 'Failed to send SMS verification'
    );

    expect($verificationFailure)->not->toBeNull()
        ->and($verificationFailure[2])->toBe([
            'phone' => '+15550003333',
            'error' => 'Twilio unavailable',
        ]);
});

it('account created listener creates email verification for non-admin users without requiring mail delivery', function () {
    verification_code_model_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 09:00:00', 'UTC'));

    $user = verification_code_subject([
        'uuid'  => 'user-4',
        'type'  => 'user',
        'phone' => null,
    ]);
    $company = verification_code_company();

    (new HandleAccountCreated())->handle(new AccountCreated($user, $company));

    $verificationCode = VerificationCode::query()->first();

    expect(VerificationCode::query()->count())->toBe(1)
        ->and($verificationCode->subject_uuid)->toBe('user-4')
        ->and($verificationCode->subject_type)->toBe(User::class)
        ->and($verificationCode->for)->toBe('email_verification')
        ->and($verificationCode->status)->toBe('active')
        ->and($verificationCode->expires_at->toDateTimeString())->toBe('2026-07-17 10:00:00');

    Carbon::setTestNow();
});

it('account created listener falls back to sms verification when email delivery fails and a phone exists', function () {
    verification_code_model_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 11:00:00', 'UTC'));
    config([
        'app.name'             => 'Fleetbase',
        'sms.default_provider' => SmsService::PROVIDER_TWILIO,
        'sms.routing_rules'    => [],
    ]);

    $mailer                = new VerificationCodeModelMailerFake();
    $mailer->sendException = new RuntimeException('SMTP unavailable');
    $container             = Illuminate\Container\Container::getInstance();
    $container->instance('mail.manager', $mailer);

    $twilio = new VerificationCodeModelTwilioFake();
    $container->instance('twilio', $twilio);
    Facade::clearResolvedInstance('twilio');

    $user = verification_code_subject([
        'uuid'  => 'user-fallback',
        'type'  => 'user',
        'email' => 'fallback@example.test',
        'phone' => '+15550004444',
    ]);
    $company = verification_code_company();

    (new HandleAccountCreated())->handle(new AccountCreated($user, $company));

    $codes = VerificationCode::query()->orderBy('for')->get();

    expect($codes)->toHaveCount(2)
        ->and($codes->pluck('for')->all())->toBe(['email_verification', 'phone_verification'])
        ->and($mailer->recipients)->toBe([$user])
        ->and($mailer->sent)->toBe([])
        ->and($twilio->messages)->toHaveCount(1)
        ->and($twilio->messages[0]['to'])->toBe('+15550004444')
        ->and($twilio->messages[0]['message'])->toContain('Fleetbase verification code is');

    Carbon::setTestNow();
});

it('account created listener swallows sms fallback delivery failures', function () {
    verification_code_model_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 11:30:00', 'UTC'));
    config([
        'app.name'             => 'Fleetbase',
        'sms.default_provider' => SmsService::PROVIDER_TWILIO,
        'sms.routing_rules'    => [],
    ]);

    $mailer                = new VerificationCodeModelMailerFake();
    $mailer->sendException = new RuntimeException('SMTP unavailable');
    $container             = Illuminate\Container\Container::getInstance();
    $container->instance('mail.manager', $mailer);

    $twilio                = new VerificationCodeModelTwilioFake();
    $twilio->sendException = new RuntimeException('Twilio unavailable');
    $container->instance('twilio', $twilio);
    Facade::clearResolvedInstance('twilio');

    $user = verification_code_subject([
        'uuid'  => 'user-fallback-failure',
        'type'  => 'user',
        'email' => 'fallback-failure@example.test',
        'phone' => '+15550005555',
    ]);
    $company = verification_code_company();

    (new HandleAccountCreated())->handle(new AccountCreated($user, $company));

    expect(VerificationCode::query()->orderBy('for')->pluck('for')->all())->toBe(['email_verification', 'phone_verification'])
        ->and($twilio->messages)->toHaveCount(1)
        ->and($twilio->messages[0]['to'])->toBe('+15550005555');

    Carbon::setTestNow();
});

it('account created listener skips verification for admin users', function () {
    verification_code_model_database();

    $user = verification_code_subject([
        'uuid'  => 'admin-1',
        'type'  => 'admin',
        'phone' => '+15550001111',
    ]);
    $company = verification_code_company();

    (new HandleAccountCreated())->handle(new AccountCreated($user, $company));

    expect(VerificationCode::query()->count())->toBe(0);
});
