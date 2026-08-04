<?php

namespace {
    use Fleetbase\Http\Controllers\Internal\v1\UserController;
    use Fleetbase\Http\Requests\Internal\AcceptCompanyInvite;
    use Fleetbase\Models\Invite;

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

        expect($request->authorize())->toBeTrue()
            ->and($request->rules())->toBe([
                'code' => ['required'],
            ]);
    });
}
