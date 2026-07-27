<?php

/**
 * FormRequest validation behavior tests.
 *
 * These drive the REAL Laravel validation engine (illuminate/validation) against each
 * request's rules(), asserting that valid input passes and invalid input is actually
 * rejected — not merely that the rules() array has a given shape. They also exercise the
 * Fleetbase-owned PublicWebhookUrl SSRF rule directly.
 */

use Fleetbase\Http\Requests\Internal\InviteUserRequest;
use Fleetbase\Http\Requests\Internal\UpdatePasswordRequest;
use Fleetbase\Http\Requests\Internal\UserForgotPasswordRequest;
use Fleetbase\Http\Requests\Internal\WebhookEndpointRequest;
use Fleetbase\Rules\PublicWebhookUrl;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

function request_validation_factory(): Factory
{
    $container = bind_test_container();
    session(['company' => 'company-1']);

    $factory = new Factory(new Translator(new ArrayLoader(), 'en'), $container);
    // The Password rule resolves the 'validator' service internally.
    $container->instance('validator', $factory);
    Facade::clearResolvedInstance('validator');

    return $factory;
}

function validate_payload(object $request, array $data): Illuminate\Contracts\Validation\Validator
{
    $factory  = request_validation_factory();
    $messages = method_exists($request, 'messages') ? $request->messages() : [];

    return $factory->make($data, $request->rules(), $messages);
}

test('forgot password request rejects missing and malformed emails but accepts a valid one', function () {
    $request = new UserForgotPasswordRequest();

    expect(validate_payload($request, [])->fails())->toBeTrue()
        ->and(validate_payload($request, ['email' => 'not-an-email'])->fails())->toBeTrue()
        ->and(validate_payload($request, ['email' => 'user@example.test'])->passes())->toBeTrue();

    // Regression guard: forgot-password must NOT use exists:users (email-enumeration oracle).
    expect($request->rules()['email'])->toBe(['required', 'email']);
});

test('invite user request requires a valid email and a name', function () {
    $request = new InviteUserRequest();

    $missingBoth  = validate_payload($request, ['user' => []]);
    $invalidEmail = validate_payload($request, ['user' => ['email' => 'nope', 'name' => 'Jane']]);
    $missingName  = validate_payload($request, ['user' => ['email' => 'jane@example.test']]);
    $valid        = validate_payload($request, ['user' => ['email' => 'jane@example.test', 'name' => 'Jane']]);

    expect($missingBoth->fails())->toBeTrue()
        ->and($missingBoth->errors()->has('user.email'))->toBeTrue()
        ->and($missingBoth->errors()->has('user.name'))->toBeTrue()
        ->and($invalidEmail->fails())->toBeTrue()
        ->and($invalidEmail->errors()->has('user.email'))->toBeTrue()
        ->and($missingName->fails())->toBeTrue()
        ->and($missingName->errors()->has('user.name'))->toBeTrue()
        ->and($valid->passes())->toBeTrue();
});

test('update password request rejects missing, weak, and unconfirmed passwords', function () {
    $request = new UpdatePasswordRequest();

    // NOTE: only failure cases are asserted — the Password rule's uncompromised() makes a
    // network call, but only AFTER the local complexity checks pass, so weak/missing/mismatched
    // passwords are rejected before any network access.
    $missing     = validate_payload($request, []);
    $weak        = validate_payload($request, ['password' => 'abc', 'password_confirmation' => 'abc']);
    $unconfirmed = validate_payload($request, ['password' => 'weakpass', 'password_confirmation' => 'different']);

    expect($missing->fails())->toBeTrue()
        ->and($missing->errors()->has('password'))->toBeTrue()
        ->and($weak->fails())->toBeTrue()
        ->and($weak->errors()->has('password'))->toBeTrue()
        ->and($weak->errors()->first('password'))->toContain('Password must')
        ->and($unconfirmed->fails())->toBeTrue()
        ->and($unconfirmed->errors()->has('password'))->toBeTrue();
});

test('webhook endpoint request enforces a public url on create and is optional on update', function () {
    $createRequest = WebhookEndpointRequest::create('/int/v1/webhook-endpoints', 'POST');
    $updateRequest = WebhookEndpointRequest::create('/int/v1/webhook-endpoints/webhook_123', 'PUT');

    // POST: url is required, must be a syntactically valid URL, and must pass the SSRF guard.
    expect(validate_payload($createRequest, [])->fails())->toBeTrue()
        ->and(validate_payload($createRequest, ['url' => 'not a url'])->fails())->toBeTrue()
        ->and(validate_payload($createRequest, ['url' => 'https://10.0.0.1/hook'])->fails())->toBeTrue()
        ->and(validate_payload($createRequest, ['url' => 'https://93.184.216.34/hooks/fleetbase'])->passes())->toBeTrue();

    // PUT: url is "sometimes" — omitting it is allowed, but a bad value is still rejected.
    expect(validate_payload($updateRequest, [])->passes())->toBeTrue()
        ->and(validate_payload($updateRequest, ['url' => 'https://169.254.169.254/latest/meta-data/'])->fails())->toBeTrue();
});

test('public webhook url rule accepts public http and https hosts', function () {
    $rule = new PublicWebhookUrl();

    expect($rule->passes('url', 'https://93.184.216.34/hooks/fleetbase'))->toBeTrue()
        ->and($rule->passes('url', 'http://93.184.216.34/hook'))->toBeTrue()
        ->and($rule->message())->toContain('public');
});

test('public webhook url rule blocks SSRF and malformed vectors', function (mixed $value) {
    expect((new PublicWebhookUrl())->passes('url', $value))->toBeFalse();
})->with([
    'non-string int'    => [12345],
    'non-string array'  => [['https://example.test']],
    'empty string'      => [''],
    'not a url'         => ['not a url'],
    'no host'           => ['https:///path'],
    'ftp scheme'        => ['ftp://93.184.216.34/x'],
    'file scheme'       => ['file:///etc/passwd'],
    'javascript scheme' => ['javascript:alert(1)'],
    'localhost'         => ['http://localhost/hook'],
    'localhost suffix'  => ['http://api.localhost/hook'],
    'internal suffix'   => ['https://svc.internal/hook'],
    'gcp metadata host' => ['http://metadata.google.internal/x'],
    'aws metadata ip'   => ['http://169.254.169.254/latest/meta-data/'],
    'loopback v4'       => ['http://127.0.0.1/hook'],
    'loopback v6'       => ['http://[::1]/hook'],
    'private 10'        => ['https://10.0.0.1/hook'],
    'private 172'       => ['https://172.16.0.1/hook'],
    'private 192'       => ['https://192.168.0.1/hook'],
    'link local'        => ['https://169.254.10.20/hook'],
]);
