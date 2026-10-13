<?php

use Fleetbase\Models\SmsSend;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Tests\Fixtures\Support\SmsGuardFixture;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class VerificationCodeTracingTwilioFake
{
    public array $messages = [];

    public function message(string $to, string $message, array $mediaUrls = [], array $params = []): object
    {
        $this->messages[] = compact('to', 'message');

        return (object) ['sid' => 'SM-traced'];
    }
}

class VerificationCodeTracingMailerFake
{
    public array $sent = [];

    public function to(mixed $recipient): self
    {
        return $this;
    }

    public function send(mixed $mail): void
    {
        $this->sent[] = $mail;
    }
}

/**
 * A verification_codes table WITH the tracing columns, the ledger table, and a guard on.
 */
function verification_code_tracing_database(bool $guard = true): VerificationCodeTracingTwilioFake
{
    EloquentModel::clearBootedModels();
    session()->flush();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.name'                   => 'Fleetbase',
        'app.key'                    => 'base64:test-app-key',
        'sms.default_provider'       => 'twilio',
        'sms.guard'                  => [
            'enabled' => $guard,
            'limits'  => ['destination' => ['cooldown_seconds' => 0, 'per_hour' => null, 'per_day' => null], 'probation' => ['days' => 0]],
            'breaker' => ['alert_percentages' => [], 'distinct_destinations_per_10m' => 500, 'new_country_min_sends' => null, 'conversion_min_issued' => null],
        ],
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new CacheRepository(new ArrayStore()));
    $container->instance('request', Request::create('/storefront/v1/customers/request-creation-code', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'StorefrontApp/3.1']));
    $container->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    $twilio = new VerificationCodeTracingTwilioFake();
    $container->instance('twilio', $twilio);
    $container->instance('mail.manager', new VerificationCodeTracingMailerFake());
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    SmsGuardFixture::verificationCodesSchema($capsule);
    SmsGuardFixture::companiesSchema($capsule);
    SmsGuardFixture::smsSendsSchema($capsule);

    session(['company' => 'company-1', 'storefront_store' => 'store-1']);

    return $twilio;
}

function verification_code_tracing_subject(array $attributes = []): User
{
    $user = new User();
    $user->setRawAttributes(array_merge(['uuid' => 'user-traced', 'name' => 'Ada Lovelace', 'phone' => '+447400100123', 'email' => 'ada@example.test'], $attributes), true);

    return $user;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('fills the tracing columns and links the ledger row for an sms code', function () {
    $twilio = verification_code_tracing_database();

    $code = VerificationCode::generateSmsVerificationFor(verification_code_tracing_subject(), 'storefront_create_customer', [
        'meta' => ['identity' => '+447400100123'],
    ]);

    $send = SmsSend::first();

    expect(VerificationCode::supportsTracingColumns())->toBeTrue()
        ->and($code->company_uuid)->toBe('company-1')
        ->and($code->identity)->toBe('+447400100123')
        ->and($code->channel)->toBe('sms')
        ->and($code->ip_address)->toBe('203.0.113.9')
        ->and($code->user_agent)->toBe('StorefrontApp/3.1')
        ->and($code->sms_send_uuid)->toBe($send->uuid)
        ->and($code->consumed_at)->toBeNull()
        ->and($send->verification_code_uuid)->toBe($code->uuid)
        ->and($send->status)->toBe('sent')
        ->and($twilio->messages)->toHaveCount(1);
});

it('issues a hashed single-use code when asked, and the message still carries the plain code', function () {
    $twilio = verification_code_tracing_database();
    Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00', 'UTC'));

    $code = VerificationCode::generateSmsVerificationFor(verification_code_tracing_subject(), 'storefront_create_customer', [
        'hashed'          => true,
        'meta'            => ['identity' => '+447400100123'],
        'messageCallback' => fn (VerificationCode $verification) => 'Your code is ' . $verification->code,
    ]);

    $stored = VerificationCode::withoutGlobalScopes()->where('uuid', $code->uuid)->first();
    $found  = VerificationCode::findActiveFor('storefront_create_customer', '+447400100123');

    expect($code->plainCode)->toMatch('/^\d{6}$/')
        ->and($stored->code)->toBe(VerificationCode::hashCode($code->plainCode))
        ->and($stored->code)->not->toBe($code->plainCode)
        ->and($stored->meta)->toMatchArray(['identity' => '+447400100123', 'hashed' => true, 'attempts' => 0])
        ->and($stored->status)->toBe('active')
        ->and($stored->expires_at->toDateTimeString())->toBe('2026-10-13 10:10:00')
        ->and($twilio->messages[0]['message'])->toBe('Your code is ' . $code->plainCode)
        ->and($found?->uuid)->toBe($code->uuid)
        ->and($found->check('000000'))->toBe(VerificationCode::CHECK_INVALID)
        ->and($found->check($code->plainCode))->toBe(VerificationCode::CHECK_VALID);

    $found->consume();

    expect(VerificationCode::findActiveFor('storefront_create_customer', '+447400100123'))->toBeNull()
        ->and($found->fresh()->status)->toBe('used')
        ->and($found->fresh()->consumed_at)->not->toBeNull()
        ->and($found->fresh()->getMeta('consumed_at'))->not->toBeNull();
});

it('finds live codes by identity or subject, skipping used, locked and expired ones, including legacy rows', function () {
    verification_code_tracing_database(false);
    $db = EloquentModel::getConnectionResolver()->connection('mysql');

    $row = fn (string $uuid, string $code, ?string $identity, ?string $subject, ?string $status, Carbon $expires, Carbon $created, ?array $meta = null) => [
        'uuid'         => $uuid,
        'code'         => $code,
        'for'          => 'storefront_login',
        'identity'     => $identity,
        'subject_uuid' => $subject,
        'status'       => $status,
        'meta'         => $meta ? json_encode($meta) : null,
        'expires_at'   => $expires,
        'created_at'   => $created,
        'updated_at'   => Carbon::now(),
    ];
    $db->table('verification_codes')->insert([
        $row('legacy', '111111', null, 'user-1', null, Carbon::now()->addHour(), Carbon::now()->subMinutes(3), ['identity' => '+447400100001']),
        $row('used', '222222', '+447400100001', null, 'used', Carbon::now()->addHour(), Carbon::now()->subMinutes(2)),
        $row('locked', '333333', '+447400100001', null, 'locked', Carbon::now()->addHour(), Carbon::now()->subMinute()),
        $row('expired', '444444', '+447400100001', null, 'active', Carbon::now()->subMinute(), Carbon::now()),
        $row('other', '555555', '+447400100002', 'user-2', 'active', Carbon::now()->addHour(), Carbon::now()),
    ]);

    expect(VerificationCode::findActiveFor('storefront_login', '+447400100001')?->uuid)->toBe('legacy')
        ->and(VerificationCode::findActiveFor('storefront_login', '+447400100001', 'user-1')?->uuid)->toBe('legacy')
        ->and(VerificationCode::findActiveFor('storefront_login', '+447400100001', 'user-9'))->toBeNull()
        ->and(VerificationCode::findActiveFor('storefront_login', '+447400100002')?->uuid)->toBe('other')
        ->and(VerificationCode::findActiveForSubject('storefront_login', 'user-2')?->uuid)->toBe('other')
        ->and(VerificationCode::findActiveForSubject('storefront_login', 'user-9'))->toBeNull()
        ->and(VerificationCode::findActiveFor('storefront_login', '+447400100001')->check('111111'))->toBe(VerificationCode::CHECK_VALID);
});

it('traces email codes too and leaves tracing alone without the guard', function () {
    verification_code_tracing_database(false);

    $email = VerificationCode::generateEmailVerificationFor(verification_code_tracing_subject(), 'storefront_create_customer', ['meta' => ['identity' => 'ada@example.test']]);
    $sms   = VerificationCode::generateSmsVerificationFor(verification_code_tracing_subject(), 'storefront_login');

    expect($email->channel)->toBe('email')
        ->and($email->identity)->toBe('ada@example.test')
        ->and($email->company_uuid)->toBe('company-1')
        ->and($email->ip_address)->toBe('203.0.113.9')
        ->and($sms->channel)->toBe('sms')
        ->and($sms->identity)->toBe('+447400100123')
        ->and($sms->sms_send_uuid)->toBeNull()
        ->and($sms->meta)->toBe([])
        ->and(SmsSend::count())->toBe(0);
});
