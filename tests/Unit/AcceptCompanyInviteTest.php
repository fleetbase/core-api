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
    use Fleetbase\Http\Controllers\Internal\v1\UserController;
    use Fleetbase\Http\Requests\Internal\AcceptCompanyInvite;
    use Fleetbase\Models\Invite;
    use Illuminate\Http\JsonResponse;

    if (!function_exists('response')) {
        function response(): AcceptCompanyInviteTestResponseFactory
        {
            return new AcceptCompanyInviteTestResponseFactory();
        }
    }

    class AcceptCompanyInviteTestResponseFactory
    {
        public function error($error, int $statusCode = 400, ?array $data = []): JsonResponse
        {
            return $this->json(
                array_merge([
                    'errors' => is_array($error) ? $error : [$error],
                ], $data),
                $statusCode
            );
        }

        public function json(array $data, int $statusCode = 200): JsonResponse
        {
            return new JsonResponse($data, $statusCode);
        }
    }

    class AcceptCompanyInviteTestController extends UserController
    {
        public function __construct()
        {
        }

        protected function findCompanyInvite(string $code): ?Invite
        {
            return null;
        }
    }

    test('accepting an unavailable company invite returns a fleetbase error response', function () {
        $request = AcceptCompanyInvite::create('/internal/v1/users/accept-company-invite', 'POST', [
            'code' => 'USED123',
        ]);

        $response = (new AcceptCompanyInviteTestController())->acceptCompanyInvite($request);
        $payload  = json_decode($response->getContent(), true);

        expect($response->getStatusCode())->toBe(400)
            ->and($payload)->toBe([
                'errors' => ['This invitation has already been accepted or is no longer available.'],
            ]);
    });

    test('company invite acceptance still requires a code', function () {
        $request = new AcceptCompanyInvite();

        expect($request->rules())->toBe([
            'code' => ['required'],
        ]);
    });
}
