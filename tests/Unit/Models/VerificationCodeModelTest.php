<?php

use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
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
}

class VerificationCodeModelResponseCacheFake
{
    public function clear(): void
    {
    }
}

function verification_code_model_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ];

    $container = bind_test_container([
        'database.default' => 'testing',
        'database.connections.testing' => $connection,
        'fleetbase.connection.db' => 'testing',
    ]);

    $cache = new VerificationCodeModelTaggedCacheFake();
    $container->instance('cache', $cache);
    $container->instance('responsecache', new VerificationCodeModelResponseCacheFake());
    Cache::swap($cache);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
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

it('generates unsaved verification codes with subject purpose and pending status', function () {
    bind_test_container();

    $subject = verification_code_subject();
    $code = VerificationCode::generateFor($subject, 'login_challenge', false);

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
    $code = VerificationCode::generateFor($subject, 'device_pairing', true);

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
        'meta' => ['ip' => '127.0.0.1'],
        'status' => 'queued',
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
