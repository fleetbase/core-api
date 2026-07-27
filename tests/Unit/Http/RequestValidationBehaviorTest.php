<?php

/**
 * FormRequest validation behavior tests.
 *
 * Context: this package does NOT depend on illuminate/validation (only the contracts),
 * so the full Laravel validation engine cannot be driven here — which is exactly why
 * RequestContractsTest stubs Illuminate\Validation\*. Rather than assert only the shape
 * of rules() arrays, this file:
 *
 *   1. Exercises the Fleetbase-owned PublicWebhookUrl SSRF rule behaviorally (it is a real
 *      Illuminate\Contracts\Validation\Rule with no framework-engine dependency), covering
 *      edge cases beyond SecurityFindingsTest.
 *   2. Locks the security-critical invariants of the key request rule sets so a regression
 *      that weakens them (e.g. adding an email-enumeration oracle, or dropping the public-URL
 *      SSRF guard) fails the suite.
 *
 * Follow-up (tracked in the audit): add illuminate/validation as a dev dependency to drive
 * the real validation engine end-to-end for these requests.
 */

namespace Illuminate\Validation\Rules {
    // illuminate/validation is not installed in this package; UpdatePasswordRequest::rules()
    // references this class, so provide the same minimal stub RequestContractsTest uses when
    // the real class is absent (guarded so it never collides with a real/loaded definition).
    if (!class_exists(Password::class)) {
        class Password
        {
            public static function min(int $size): self
            {
                return new self();
            }

            public function mixedCase(): self
            {
                return $this;
            }

            public function letters(): self
            {
                return $this;
            }

            public function numbers(): self
            {
                return $this;
            }

            public function symbols(): self
            {
                return $this;
            }

            public function uncompromised(): self
            {
                return $this;
            }

            public function __toString(): string
            {
                return 'password';
            }
        }
    }
}

namespace {
    use Fleetbase\Http\Requests\Internal\InviteUserRequest;
    use Fleetbase\Http\Requests\Internal\UpdatePasswordRequest;
    use Fleetbase\Http\Requests\Internal\UserForgotPasswordRequest;
    use Fleetbase\Http\Requests\Internal\WebhookEndpointRequest;
    use Fleetbase\Rules\PublicWebhookUrl;
    use Illuminate\Validation\Rules\Password;

    beforeEach(function () {
        bind_test_container();
        session(['company' => 'company-1']);
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

    test('forgot password rule set does not expose an email-enumeration oracle', function () {
        // exists:users would let an attacker probe which emails are registered.
        expect((new UserForgotPasswordRequest())->rules()['email'])->toBe(['required', 'email']);
    });

    test('invite user rule set requires a valid email and a name', function () {
        $rules = (new InviteUserRequest())->rules();

        expect($rules['user.email'])->toBe('required|email')
            ->and($rules['user.name'])->toBe('required');
    });

    test('update password rule set enforces confirmation and password complexity', function () {
        $rules         = (new UpdatePasswordRequest())->rules();
        $passwordRules = $rules['password'];

        $hasComplexityRule = collect($passwordRules)->contains(fn ($rule) => $rule instanceof Password);

        expect($passwordRules)->toContain('required')
            ->and($passwordRules)->toContain('confirmed')
            ->and($passwordRules)->toContain('string')
            ->and($hasComplexityRule)->toBeTrue()
            ->and($rules['password_confirmation'])->toBe(['required', 'string']);
    });

    test('webhook endpoint rule set requires a public url on create and keeps the SSRF guard on update', function () {
        $createRules = WebhookEndpointRequest::create('/int/v1/webhook-endpoints', 'POST')->rules();
        $updateRules = WebhookEndpointRequest::create('/int/v1/webhook-endpoints/webhook_123', 'PUT')->rules();

        $createHasSsrfGuard = collect($createRules['url'])->contains(fn ($rule) => $rule instanceof PublicWebhookUrl);
        $updateHasSsrfGuard = collect($updateRules['url'])->contains(fn ($rule) => $rule instanceof PublicWebhookUrl);

        expect($createRules['url'][0])->toBe('required')
            ->and($createRules['url'])->toContain('url')
            ->and($createHasSsrfGuard)->toBeTrue()
            ->and($updateRules['url'][0])->toBe('sometimes')
            ->and($updateHasSsrfGuard)->toBeTrue();
    });
}
