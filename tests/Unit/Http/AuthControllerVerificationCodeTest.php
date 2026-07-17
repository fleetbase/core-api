<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Requests\Internal\ResetPasswordRequest;
use Fleetbase\Models\VerificationCode;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class AuthControllerVerificationCodeCacheFake
{
    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
    }

    public function forget(string $key): bool
    {
        return true;
    }
}

function auth_controller_verification_code_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => $connection,
        'fleetbase.connection.db'      => 'testing',
    ]);

    $cache = new AuthControllerVerificationCodeCacheFake();
    $container->instance('cache', $cache);
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

function auth_controller_verification_code_insert(Capsule $capsule, array $attributes): void
{
    $capsule->getConnection('testing')->table('verification_codes')->insert(array_merge([
        'uuid'         => 'verification-code-1',
        'subject_uuid' => null,
        'subject_type' => null,
        'code'         => '123456',
        'for'          => 'password_reset',
        'expires_at'   => '2026-07-18 12:00:00',
        'meta'         => json_encode([]),
        'status'       => 'active',
        'deleted_at'   => null,
        'created_at'   => '2026-07-18 11:00:00',
        'updated_at'   => '2026-07-18 11:00:00',
    ], $attributes));
}

function auth_controller_verification_code_request(array $input = []): Request
{
    return Request::create('/int/v1/auth/verification-code', 'POST', $input);
}

function auth_controller_reset_password_request(array $input = []): ResetPasswordRequest
{
    return ResetPasswordRequest::create('/int/v1/auth/reset-password', 'POST', $input);
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('validate verification code returns false for unknown ids and echoes the requested id', function () {
    auth_controller_verification_code_database();

    $response = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id'  => 'missing-code',
        'for' => 'password_reset',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'is_valid' => false,
            'id'       => 'missing-code',
        ]);
});

test('validate verification code requires active status when a purpose is supplied', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'inactive-code',
        'for'    => 'password_reset',
        'status' => 'used',
    ]);
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'active-code',
        'for'    => 'password_reset',
        'status' => 'active',
    ]);

    $inactive = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id'  => 'inactive-code',
        'for' => 'password_reset',
    ]));
    $active = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id'  => 'active-code',
        'for' => 'password_reset',
    ]));

    expect($inactive->getData(true))->toBe([
        'is_valid' => false,
        'id'       => 'inactive-code',
    ])
        ->and($active->getData(true))->toBe([
            'is_valid' => true,
            'id'       => 'active-code',
        ]);
});

test('validate verification code only checks existence when no purpose is supplied', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'used-code',
        'for'    => 'password_reset',
        'status' => 'used',
    ]);

    $response = (new AuthController())->validateVerificationCode(auth_controller_verification_code_request([
        'id' => 'used-code',
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'is_valid' => true,
            'id'       => 'used-code',
        ]);
});

test('reset password rejects missing or mismatched verification code records', function () {
    $capsule = auth_controller_verification_code_database();
    auth_controller_verification_code_insert($capsule, [
        'uuid'   => 'reset-code',
        'code'   => '123456',
        'for'    => 'password_reset',
        'status' => 'active',
    ]);

    $missing = (new AuthController())->resetPassword(auth_controller_reset_password_request([
        'link'     => 'missing-code',
        'code'     => '123456',
        'password' => 'new-password',
    ]));
    $wrongCode = (new AuthController())->resetPassword(auth_controller_reset_password_request([
        'link'     => 'reset-code',
        'code'     => '000000',
        'password' => 'new-password',
    ]));

    expect($missing->getStatusCode())->toBe(400)
        ->and($missing->getData(true))->toBe([
            'errors' => ['Invalid password reset request!'],
        ])
        ->and($wrongCode->getStatusCode())->toBe(400)
        ->and($wrongCode->getData(true))->toBe([
            'errors' => ['Invalid password reset request!'],
        ])
        ->and(VerificationCode::query()->where('uuid', 'reset-code')->exists())->toBeTrue();
});

test('confirm email change rejects invalid links before mutating users', function () {
    auth_controller_verification_code_database();

    $response = (new AuthController())->confirmEmailChange(auth_controller_verification_code_request([
        'link' => 'missing-email-change',
        'code' => '123456',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => ['Invalid email change request!'],
        ]);
});
