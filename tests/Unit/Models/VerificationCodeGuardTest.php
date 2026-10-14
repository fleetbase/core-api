<?php

use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class VerificationCodeGuardTwilioFake
{
    public array $messages = [];

    public function message(string $to, string $message, array $mediaUrls = [], array $params = []): object
    {
        $this->messages[] = compact('to', 'message', 'mediaUrls', 'params');

        return (object) ['sid' => 'SM-guarded'];
    }
}

function verification_code_guard_database(array $guard = []): VerificationCodeGuardTwilioFake
{
    EloquentModel::clearBootedModels();
    session()->flush();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'app.name'                     => 'Fleetbase',
        'sms.default_provider'         => 'twilio',
        'sms.guard'                    => array_replace_recursive([
            'enabled' => true,
            'mode'    => 'enforce',
            'limits'  => [
                'destination' => ['cooldown_seconds' => 60, 'per_hour' => 3, 'per_day' => 5],
                'company'     => ['per_minute' => 100, 'per_hour' => 100, 'per_day' => 100],
                'probation'   => ['days' => 0],
            ],
            'breaker' => ['alert_percentages' => [], 'distinct_destinations_per_10m' => 100],
        ], $guard),
        'database.default'             => 'testing',
        'database.connections.testing' => $connection,
        'database.connections.mysql'   => $connection,
        'fleetbase.connection.db'      => 'testing',
    ]);
    $container->instance('cache', new CacheRepository(new ArrayStore()));
    $container->instance('request', Request::create('/storefront/v1/customers/request-creation-code', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'StorefrontApp/3.1']));
    $container->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    $twilio = new VerificationCodeGuardTwilioFake();
    $container->instance('twilio', $twilio);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    $container->instance('db', $capsule->getDatabaseManager());
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
    // Company lives on the core connection, like the verification code model test.
    $capsule->getConnection('mysql')->getSchemaBuilder()->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('slug')->nullable();
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    session(['company' => 'company-1', 'storefront_store' => 'store-1']);

    return $twilio;
}

function verification_code_guard_subject(string $phone): User
{
    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-guarded', 'name' => 'Ada Lovelace', 'phone' => $phone], true);

    return $user;
}

afterEach(function () {
    Carbon::setTestNow();
    session()->flush();
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

it('writes no verification code row when the guard refuses the send', function () {
    $twilio = verification_code_guard_database();

    $refusal = null;
    try {
        VerificationCode::generateSmsVerificationFor(verification_code_guard_subject('+4471'), 'storefront_create_customer', ['meta' => ['identity' => '+4471']]);
    } catch (SmsBlockedException $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(SmsBlockedException::class)
        ->and($refusal->status())->toBe(422)
        ->and(VerificationCode::query()->count())->toBe(0)
        ->and($twilio->messages)->toBe([]);
});

it('traces an allowed send on the code row, sends once and refuses the repeat inside the cooldown', function () {
    $twilio = verification_code_guard_database();

    $code = VerificationCode::generateSmsVerificationFor(verification_code_guard_subject('+447400100123'), 'storefront_create_customer', [
        'meta'            => ['identity' => '+447400100123'],
        'messageCallback' => fn (VerificationCode $verificationCode) => 'Code: ' . $verificationCode->code,
    ]);

    $repeat = null;
    try {
        VerificationCode::generateSmsVerificationFor(verification_code_guard_subject('+447400100123'), 'storefront_create_customer', ['meta' => ['identity' => '+447400100123']]);
    } catch (SmsBlockedException $e) {
        $repeat = $e;
    }

    $entries = collect(app('log')->entries)->pluck(1);

    expect($code->exists)->toBeTrue()
        ->and($code->meta['identity'])->toBe('+447400100123')
        ->and($code->meta['trace'])->toMatchArray([
            'company_uuid'        => 'company-1',
            'tenant_type'         => 'store',
            'tenant_uuid'         => 'store-1',
            'source'              => 'storefront',
            'ip'                  => '203.0.113.9',
            'user_agent'          => 'StorefrontApp/3.1',
            'destination_country' => 'GB',
            'decision'            => 'allowed',
        ])
        ->and($twilio->messages)->toHaveCount(1)
        ->and($twilio->messages[0]['to'])->toBe('+447400100123')
        ->and($twilio->messages[0]['message'])->toBe('Code: ' . $code->code)
        ->and($repeat)->toBeInstanceOf(SmsBlockedException::class)
        ->and($repeat->status())->toBe(429)
        ->and(VerificationCode::query()->count())->toBe(1)
        ->and($entries->filter(fn ($name) => $name === 'sms.sent')->count())->toBe(1)
        ->and($entries->filter(fn ($name) => $name === 'sms.blocked')->count())->toBe(1);
});

it('leaves the code row untouched when the guard is disabled', function () {
    $twilio = verification_code_guard_database(['enabled' => false]);

    $code = VerificationCode::generateSmsVerificationFor(verification_code_guard_subject('+447400100123'), 'phone_login', ['meta' => ['channel' => 'sms']]);

    expect($code->meta)->toBe(['channel' => 'sms'])
        ->and($twilio->messages)->toHaveCount(1)
        ->and(collect(app('log')->entries)->pluck(1)->all())->not->toContain('sms.sent');
});
