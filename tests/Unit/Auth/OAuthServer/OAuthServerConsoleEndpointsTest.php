<?php

require_once __DIR__ . '/OAuthServerTestSupport.php';

use Fleetbase\Console\Commands\OAuthServerClient;
use Fleetbase\Console\Commands\OAuthServerKeys;
use Fleetbase\Console\Commands\OAuthServerPrune;
use Fleetbase\Http\Controllers\Internal\v1\ConnectedAppController;
use Fleetbase\Http\Controllers\Internal\v1\OAuthAppController;
use Fleetbase\Http\Controllers\Internal\v1\OAuthConsentController;
use Fleetbase\Http\Controllers\OAuthServer\AuthorizationController;
use Fleetbase\Http\Controllers\OAuthServer\TokenController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\OAuthAccessToken;
use Fleetbase\Models\OAuthAuthCode;
use Fleetbase\Models\OAuthClient;
use Fleetbase\Models\OAuthGrant;
use Fleetbase\Models\OAuthRefreshToken;
use Fleetbase\Models\User;
use Fleetbase\Notifications\OAuthAppAuthorized;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    oauth_test_setup();
    config([
        'app.name'                 => 'Fleetbase',
        'fleetbase.console.host'   => 'console.fleetbase.test',
        'fleetbase.console.secure' => true,
    ]);
    $container = Container::getInstance();
    $container->instance('validator', new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), $container));
    Illuminate\Support\Facades\Facade::clearResolvedInstance('validator');
});
afterEach(fn () => oauth_test_teardown());

/**
 * A console request from a signed-in user, with their Sanctum token's sign-in time.
 */
function oauth_console_request(string $userUuid, string $method = 'GET', array $input = [], string $signedIn = '-1 minute', string $class = Request::class): Request
{
    $user = User::where('uuid', $userUuid)->first();
    $user->withAccessToken((object) ['created_at' => Illuminate\Support\Carbon::parse($signedIn)]);

    $request = $class::create('/int/v1/oauth-server/authorization', $method, $input);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function oauth_console_consent(): OAuthConsentController
{
    return new OAuthConsentController(oauth_test_server());
}

/**
 * Run a command with these arguments; returns [exit code, output].
 */
function oauth_console_command(Illuminate\Console\Command $command, array $input = []): array
{
    $output = new BufferedOutput();
    $command->setLaravel(Container::getInstance());
    $command->setInput(new ArrayInput($input, $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    return [$command->handle(), $output->fetch()];
}

describe('consent screen', function () {
    it('describes the request: the app, the user, the scopes and the organizations they can share', function () {
        [$client] = oauth_test_client(['logo_url' => 'https://app.example.com/logo.png']);
        $response = oauth_console_consent()->show(oauth_console_request('user-admin', 'GET', oauth_test_params($client, oauth_test_verifier()) + ['utm_source' => 'mail']));
        $body     = $response->getData(true);

        expect($response->getStatusCode())->toBe(200)
            ->and($body['client'])->toMatchArray(['id' => $client->id, 'name' => 'AppHelm', 'official' => true, 'redirect_host' => 'app.example.com'])
            ->and($body['user']['email'])->toBe('admin@example.com')
            ->and(array_column($body['scopes'], 'scope'))->toBe(['openid', 'profile', 'email', 'organization:read'])
            ->and($body['scopes'][3]['description'])->toContain('{organization}')
            ->and($body['requires_organization'])->toBeTrue()
            ->and($body['organizations'])->toBe([['id' => 'company_a', 'name' => 'Acme Deliveries', 'logo_url' => $body['organizations'][0]['logo_url'], 'can_share' => true]])
            ->and($body['skip_consent'])->toBeFalse();
    });

    it('preselects the organization the user shared with the app before', function () {
        [$client] = oauth_test_client();
        $verifier = oauth_test_verifier();
        oauth_test_code($client, $verifier, [], 'user-member', 'company-b');

        $body = oauth_console_consent()->show(oauth_console_request('user-member', 'GET', oauth_test_params($client, oauth_test_verifier())))->getData(true);

        expect($body['selected_organization'])->toBe('company_b');
    });

    it('asks for a fresh sign-in when the app wants one', function () {
        [$client] = oauth_test_client();
        $params   = oauth_test_params($client, oauth_test_verifier(), ['prompt' => 'login']);

        $show    = oauth_console_consent()->show(oauth_console_request('user-owner', 'GET', $params, '-1 hour'));
        $approve = oauth_console_consent()->approve(oauth_console_request('user-owner', 'POST', $params + ['organization' => 'company_a'], '-1 hour'));

        expect($show->getData(true))->toBe(['reauthenticate' => true])
            ->and($approve->getStatusCode())->toBe(409);
    });

    it('returns an error the app can receive with where to send the user, and one it cannot without', function () {
        [$client] = oauth_test_client();

        $scope   = oauth_console_consent()->show(oauth_console_request('user-owner', 'GET', oauth_test_params($client, oauth_test_verifier(), ['scope' => 'openid nope'])));
        $unknown = oauth_console_consent()->show(oauth_console_request('user-owner', 'GET', ['client_id' => 'nope']));

        expect($scope->getStatusCode())->toBe(400)
            ->and($scope->getData(true)['error'])->toBe('invalid_scope')
            ->and($scope->getData(true)['redirect'])->toStartWith(OAUTH_TEST_REDIRECT . '?error=invalid_scope')
            ->and($unknown->getData(true))->toMatchArray(['error' => 'invalid_client', 'redirect' => null]);
    });

    it('approves with the organization picked, sends the user back with a code, and tells the account holder', function () {
        [$client] = oauth_test_client();
        $params   = oauth_test_params($client, oauth_test_verifier());

        $response = oauth_console_consent()->approve(oauth_console_request('user-owner', 'POST', $params + ['organization' => 'company_a']));
        $again    = oauth_console_consent()->approve(oauth_console_request('user-owner', 'POST', $params + ['organization' => 'company_a']));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true)['redirect'])->toContain('code=')
            ->and(OAuthGrant::count())->toBe(1)
            ->and(OAuthGrant::first()->company_uuid)->toBe('company-a')
            ->and(OAuthAuthCode::first()->auth_time)->not->toBeNull()
            ->and($again->getStatusCode())->toBe(200);
    });

    it('refuses an organization the user does not administer', function () {
        [$client] = oauth_test_client();

        $response = oauth_console_consent()->approve(oauth_console_request('user-member', 'POST', oauth_test_params($client, oauth_test_verifier()) + ['organization' => 'company_a']));

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['error'])->toBe('access_denied')
            ->and(OAuthGrant::count())->toBe(0);
    });

    it('sends a cancelled request back to the app', function () {
        [$client] = oauth_test_client();

        $deny    = oauth_console_consent()->deny(oauth_console_request('user-owner', 'DELETE', oauth_test_params($client, oauth_test_verifier())));
        $invalid = oauth_console_consent()->deny(oauth_console_request('user-owner', 'DELETE', ['client_id' => 'nope']));

        expect($deny->getData(true)['redirect'])->toStartWith(OAUTH_TEST_REDIRECT . '?error=access_denied')
            ->and($invalid->getStatusCode())->toBe(400);
    });
});

describe('the authorization endpoint', function () {
    it('sends a valid request to the console consent screen, and errors where they belong', function () {
        [$client]   = oauth_test_client();
        $controller = new AuthorizationController();
        $authorize  = fn (array $query) => $controller->authorize(Request::create('/oauth/authorize', 'GET', $query), oauth_test_server())->getTargetUrl();

        expect($authorize(oauth_test_params($client, oauth_test_verifier())))->toStartWith('https://console.fleetbase.test/oauth/authorize?response_type=code&client_id=' . $client->id)
            ->and($authorize(oauth_test_params($client, oauth_test_verifier(), ['response_type' => 'token'])))->toStartWith(OAUTH_TEST_REDIRECT . '?error=unsupported_response_type')
            ->and($authorize(['client_id' => 'nope']))->toStartWith('https://console.fleetbase.test/oauth/authorize?error=invalid_client');
    });

    it('hands token and revocation requests to the server', function () {
        [$client, $secret] = oauth_test_client();
        $controller        = new TokenController();

        $token  = $controller->token(Request::create('/oauth/token', 'POST', ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => $secret, 'refresh_token' => 'x']), oauth_test_server());
        $revoke = $controller->revoke(Request::create('/oauth/revoke', 'POST', ['token' => 'x', 'client_id' => $client->id, 'client_secret' => $secret]), oauth_test_server());

        expect($token->getStatusCode())->toBe(401)
            ->and($revoke->getStatusCode())->toBe(200);
    });
});

describe('connected apps', function () {
    it('lists the user\'s apps in plain words, and removes one', function () {
        [$client]  = oauth_test_client(['logo_url' => 'https://app.example.com/logo.png']);
        $verifier  = oauth_test_verifier();
        oauth_test_code($client, $verifier);
        $controller = new ConnectedAppController();

        $apps = $controller->index(oauth_console_request('user-owner'))->getData(true)['connected_apps'];

        expect($apps)->toHaveCount(1)
            ->and($apps[0]['app'])->toMatchArray(['name' => 'AppHelm', 'official' => true, 'disabled' => false])
            ->and($apps[0]['organization'])->toBe(['id' => 'company_a', 'name' => 'Acme Deliveries'])
            ->and(array_column($apps[0]['scopes'], 'description'))->toContain("See Acme Deliveries's name, logo, currency and country")
            ->and($controller->index(oauth_console_request('user-member'))->getData(true)['connected_apps'])->toBe([]);

        expect(fn () => $controller->destroy(oauth_console_request('user-member'), $apps[0]['id']))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $controller->destroy(oauth_console_request('user-owner'), $apps[0]['id']);

        expect(OAuthGrant::first()->isRevoked())->toBeTrue()
            ->and(OAuthAuthCode::where('revoked', false)->count())->toBe(0);
    });
});

describe('admin: OAuth Apps', function () {
    function oauth_admin_request(string $method = 'GET', array $input = []): AdminRequest
    {
        return oauth_console_request('user-owner', $method, $input, '-1 minute', AdminRequest::class);
    }

    it('registers an app, showing the secret once, then edits it', function () {
        $controller = new OAuthAppController();

        $created = $controller->store(oauth_admin_request('POST', [
            'name'           => 'AppHelm',
            'redirect_uris'  => [OAUTH_TEST_REDIRECT, OAUTH_TEST_REDIRECT],
            'allowed_scopes' => ['openid', 'organization:read', 'openid'],
            'official'       => true,
        ]));
        $body   = $created->getData(true);
        $client = OAuthClient::find($body['app']['id']);

        expect($created->getStatusCode())->toBe(201)
            ->and($body['client_secret'])->toBeString()
            ->and($client->verifySecret($body['client_secret']))->toBeTrue()
            ->and($client->redirect_uris)->toBe([OAUTH_TEST_REDIRECT])
            ->and($client->allowed_scopes)->toBe(['openid', 'organization:read'])
            ->and($client->created_by_uuid)->toBe('user-owner')
            ->and($body['app'])->not->toHaveKey('secret');

        $updated = $controller->update(oauth_admin_request('PUT', ['name' => 'AppHelm 2', 'confidential' => false]), $client->id)->getData(true);

        expect($updated['app']['name'])->toBe('AppHelm 2')
            ->and($updated['app']['confidential'])->toBeTrue();

        $listed = $controller->index(oauth_admin_request())->getData(true);
        $shown  = $controller->show(oauth_admin_request(), $client->id)->getData(true);

        expect($listed['apps'][0]['active_grants_count'])->toBe(0)
            ->and(array_column($listed['scopes'], 'scope'))->toContain('openid')
            ->and($shown['app']['id'])->toBe($client->id);
    });

    it('registers a public app without a secret', function () {
        $body = (new OAuthAppController())->store(oauth_admin_request('POST', [
            'name'           => 'Native',
            'redirect_uris'  => ['http://127.0.0.1:3000/cb'],
            'allowed_scopes' => ['openid'],
            'development'    => true,
            'confidential'   => false,
        ]))->getData(true);

        expect($body['client_secret'])->toBeNull()
            ->and($body['app']['confidential'])->toBeFalse()
            ->and((new OAuthAppController())->rotateSecret(oauth_admin_request('POST'), $body['app']['id'])->getStatusCode())->toBe(422);
    });

    it('refuses an unsafe redirect URI or an unknown scope', function () {
        $register = fn (array $input) => (new OAuthAppController())->store(oauth_admin_request('POST', $input + ['name' => 'X', 'allowed_scopes' => ['openid'], 'redirect_uris' => [OAUTH_TEST_REDIRECT]]));

        expect(fn () => $register(['redirect_uris' => ['http://app.example.com/cb']]))->toThrow(ValidationException::class)
            ->and(fn () => $register(['allowed_scopes' => ['admin:all']]))->toThrow(ValidationException::class)
            ->and(OAuthClient::count())->toBe(0);
    });

    it('rotates a secret, turns an app off and on, and lists and revokes who authorized it', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $tokens]        = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);
        $controller        = new OAuthAppController();

        $rotated = $controller->rotateSecret(oauth_admin_request('POST'), $client->id)->getData(true);
        expect($client->fresh()->verifySecret($secret))->toBeFalse()
            ->and($client->fresh()->verifySecret($rotated['client_secret']))->toBeTrue();

        $grants = $controller->grants(oauth_admin_request(), $client->id)->getData(true)['grants'];
        expect($grants)->toHaveCount(1)
            ->and($grants[0]['user'])->toBe(['id' => 'user_owner', 'name' => 'Olive Owner', 'email' => 'owner@example.com'])
            ->and($grants[0]['organization'])->toBe(['id' => 'company_a', 'name' => 'Acme Deliveries']);

        $controller->disable(oauth_admin_request('POST'), $client->id);
        expect($client->fresh()->isDisabled())->toBeTrue()
            ->and(OAuthGrant::first()->isRevoked())->toBeTrue()
            ->and(OAuthAccessToken::where('revoked', false)->count())->toBe(0)
            ->and(OAuthRefreshToken::where('revoked', false)->count())->toBe(0);

        $controller->enable(oauth_admin_request('POST'), $client->id);
        expect($client->fresh()->isDisabled())->toBeFalse()
            ->and($tokens)->toHaveKey('access_token');

        $verifier = oauth_test_verifier();
        oauth_test_code($client, $verifier);
        $grant = OAuthGrant::active()->first();
        $controller->revokeGrant(oauth_admin_request('DELETE'), $grant->uuid);
        expect($grant->fresh()->isRevoked())->toBeTrue();
    });
});

describe('the account holder notice', function () {
    it('names the app, the organization and what it can do', function () {
        $user         = User::where('uuid', 'user-owner')->first();
        $organization = new OAuthAppAuthorized('AppHelm', 'Acme Deliveries', ['See your email address']);
        $signIn       = new OAuthAppAuthorized('AppHelm', null, ['Sign you in with your Fleetbase account']);
        $mail         = $organization->toMail($user);

        expect($organization->via($user))->toBe(['mail'])
            ->and($mail->subject)->toBe('AppHelm can now access Acme Deliveries')
            ->and($mail->introLines)->toContain('• See your email address')
            ->and($mail->actionUrl)->toBe('https://console.fleetbase.test/account/auth')
            ->and($signIn->toMail($user)->subject)->toBe('AppHelm can now sign you in with Fleetbase')
            ->and($organization->toArray($user))->toMatchArray(['app' => 'AppHelm', 'organization' => 'Acme Deliveries', 'scopes' => ['See your email address']]);
    });
});

describe('artisan', function () {
    it('registers, lists and rotates apps', function () {
        [$code, $output] = oauth_console_command(new OAuthServerClient(), [
            'action'     => 'create',
            '--name'     => 'AppHelm',
            '--redirect' => [OAUTH_TEST_REDIRECT],
            '--scope'    => ['openid', 'organization:read'],
            '--official' => true,
        ]);
        $client = OAuthClient::first();

        expect($code)->toBe(0)
            ->and($output)->toContain('client_id:     ' . $client->id)
            ->and($output)->toContain('client_secret: ')
            ->and($client->official)->toBeTrue()
            ->and($client->allowed_scopes)->toBe(['openid', 'organization:read']);

        [$listCode, $list] = oauth_console_command(new OAuthServerClient(), ['action' => 'list']);
        expect($listCode)->toBe(0)->and($list)->toContain('AppHelm')->and($list)->toContain('confidential');

        [$rotateCode, $rotated] = oauth_console_command(new OAuthServerClient(), ['action' => 'rotate', 'id' => $client->id]);
        expect($rotateCode)->toBe(0)->and($rotated)->toContain('old secret stopped working');
    });

    it('registers a public app with every scope by default', function () {
        [$code, $output] = oauth_console_command(new OAuthServerClient(), ['action' => 'create', '--name' => 'Native', '--redirect' => ['http://127.0.0.1:3000/cb'], '--public' => true, '--development' => true]);

        expect($code)->toBe(0)
            ->and($output)->not->toContain('client_secret')
            ->and(OAuthClient::first()->allowed_scopes)->toBe(array_keys(Fleetbase\Auth\OAuthServer\OAuthScopes::all()));
    });

    it('refuses what it cannot register or rotate', function () {
        $run = fn (array $input) => oauth_console_command(new OAuthServerClient(), $input)[0];

        expect($run(['action' => 'delete']))->toBe(1)
            ->and($run(['action' => 'create', '--name' => 'X']))->toBe(1)
            ->and($run(['action' => 'create', '--name' => 'X', '--redirect' => ['http://app.example.com/cb']]))->toBe(1)
            ->and($run(['action' => 'create', '--name' => 'X', '--redirect' => [OAUTH_TEST_REDIRECT], '--scope' => ['admin:all']]))->toBe(1)
            ->and($run(['action' => 'rotate', 'id' => 'nope']))->toBe(1)
            ->and(OAuthClient::count())->toBe(0);
    });

    it('writes or prints a key pair, and will not overwrite one by accident', function () {
        foreach (['private', 'public'] as $type) {
            if (file_exists(Fleetbase\Auth\OAuthServer\OAuthKeys::keyPath($type))) {
                unlink(Fleetbase\Auth\OAuthServer\OAuthKeys::keyPath($type));
            }
        }
        if (!is_dir(dirname(Fleetbase\Auth\OAuthServer\OAuthKeys::keyPath('private')))) {
            mkdir(dirname(Fleetbase\Auth\OAuthServer\OAuthKeys::keyPath('private')), 0777, true);
        }

        [$shownCode, $shown] = oauth_console_command(new OAuthServerKeys(), ['--show' => true]);
        [$writeCode]         = oauth_console_command(new OAuthServerKeys());
        [$againCode]         = oauth_console_command(new OAuthServerKeys());
        [$forceCode]         = oauth_console_command(new OAuthServerKeys(), ['--force' => true]);

        expect($shownCode)->toBe(0)
            ->and($shown)->toContain('OAUTH_SERVER_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\\n')
            ->and($writeCode)->toBe(0)
            ->and(file_get_contents(Fleetbase\Auth\OAuthServer\OAuthKeys::keyPath('private')))->toContain('PRIVATE KEY')
            ->and($againCode)->toBe(1)
            ->and($forceCode)->toBe(0);

        foreach (['private', 'public'] as $type) {
            unlink(Fleetbase\Auth\OAuthServer\OAuthKeys::keyPath($type));
        }
    })->skip(fn () => !function_exists('openssl_pkey_new'), 'needs openssl');

    it('prunes codes and tokens that expired more than a week ago', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);
        $fresh = oauth_test_code($client, oauth_test_verifier());

        OAuthAccessToken::query()->update(['expires_at' => now()->subDays(40)]);
        OAuthRefreshToken::query()->update(['expires_at' => now()->subDays(40)]);
        OAuthAuthCode::where('revoked', true)->update(['expires_at' => now()->subDays(40)]);

        [$code, $output] = oauth_console_command(new OAuthServerPrune(), ['--days' => '7']);

        expect($code)->toBe(0)
            ->and($output)->toContain('Deleted 1 codes, 1 access tokens and 1 refresh tokens.')
            ->and(OAuthAuthCode::count())->toBe(1)
            ->and($fresh)->toBeString();
    });
});
