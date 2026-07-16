<?php

namespace Illuminate\Foundation\Auth\Access {
    trait AuthorizesRequests
    {
    }
}

namespace Illuminate\Foundation\Bus {
    trait DispatchesJobs
    {
    }
}

namespace Illuminate\Foundation\Validation {
    trait ValidatesRequests
    {
    }
}

namespace Illuminate\Foundation\Http {
    class FormRequest extends \Illuminate\Http\Request
    {
    }
}

namespace {
    use Fleetbase\Http\Controllers\Internal\v1\CompanyController;
    use Fleetbase\Http\Requests\Internal\UserForgotPasswordRequest;
    use Fleetbase\Http\Requests\Internal\WebhookEndpointRequest;
    use Fleetbase\Rules\PublicWebhookUrl;

    test('forgot password request does not expose a registered email oracle', function () {
        $rules = (new UserForgotPasswordRequest())->rules();

        expect($rules['email'])->toBe(['required', 'email'])
            ->and($rules['email'])->not->toContain('exists:users,email');
    });

    test('company controller owns generic company rest operations', function () {
        $reflection = new ReflectionClass(CompanyController::class);

        expect($reflection->getMethod('findRecord')->getDeclaringClass()->getName())->toBe(CompanyController::class)
            ->and($reflection->getMethod('updateRecord')->getDeclaringClass()->getName())->toBe(CompanyController::class)
            ->and($reflection->getMethod('deleteRecord')->getDeclaringClass()->getName())->toBe(CompanyController::class);
    });

    test('webhook endpoint request validates webhook urls on create and update', function () {
        $createRequest = WebhookEndpointRequest::create('/int/v1/webhook-endpoints', 'POST');
        $updateRequest = WebhookEndpointRequest::create('/int/v1/webhook-endpoints/webhook_123', 'PUT');

        expect($createRequest->rules()['url'][0])->toBe('required')
            ->and($updateRequest->rules()['url'][0])->toBe('sometimes');
    });

    test('public webhook url rule accepts public http and https urls', function () {
        $rule = new PublicWebhookUrl();

        expect($rule->passes('url', 'https://93.184.216.34/hooks/fleetbase'))->toBeTrue()
            ->and($rule->passes('url', 'http://93.184.216.34/hooks/fleetbase'))->toBeTrue();
    });

    test('public webhook url rule rejects private and metadata urls', function (string $url) {
        $rule = new PublicWebhookUrl();

        expect($rule->passes('url', $url))->toBeFalse();
    })->with([
        'aws metadata' => ['http://169.254.169.254/latest/meta-data/'],
        'localhost'    => ['http://localhost/hook'],
        'loopback v4'  => ['http://127.0.0.1/hook'],
        'loopback v6'  => ['http://[::1]/hook'],
        'private 10'   => ['https://10.0.0.1/hook'],
        'private 172'  => ['https://172.16.0.1/hook'],
        'private 192'  => ['https://192.168.0.1/hook'],
        'link local'   => ['https://169.254.10.20/hook'],
    ]);
}
