<?php

require_once __DIR__ . '/OAuthServerTestSupport.php';

use Fleetbase\Auth\OAuthServer\Entities\AccessTokenEntity;
use Fleetbase\Auth\OAuthServer\Entities\AuthCodeEntity;
use Fleetbase\Auth\OAuthServer\Entities\ClientEntity;
use Fleetbase\Auth\OAuthServer\Entities\RefreshTokenEntity;
use Fleetbase\Auth\OAuthServer\Entities\ScopeEntity;
use Fleetbase\Auth\OAuthServer\Exceptions\AuthorizeError;
use Fleetbase\Auth\OAuthServer\IssueContext;
use Fleetbase\Auth\OAuthServer\OAuthKeys;
use Fleetbase\Auth\OAuthServer\OAuthOrganizations;
use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Auth\OAuthServer\PayloadCrypt;
use Fleetbase\Auth\OAuthServer\Repositories\AccessTokenRepository;
use Fleetbase\Auth\OAuthServer\Repositories\AuthCodeRepository;
use Fleetbase\Auth\OAuthServer\Repositories\ClientRepository;
use Fleetbase\Auth\OAuthServer\Repositories\RefreshTokenRepository;
use Fleetbase\Auth\OAuthServer\Repositories\ScopeRepository;
use Fleetbase\Http\Middleware\AuthenticateOAuthToken;
use Fleetbase\Models\Company;
use Fleetbase\Models\OAuthClient;
use Fleetbase\Models\OAuthGrant;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

beforeEach(fn () => oauth_test_setup());
afterEach(fn () => oauth_test_teardown());

function oauth_edge_authorize_error(array $params): AuthorizeError
{
    try {
        oauth_test_server()->validateAuthorizationRequest($params);
    } catch (AuthorizeError $e) {
        return $e;
    }

    throw new RuntimeException('Expected the authorization request to be refused.');
}

describe('authorization request checks', function () {
    it('refuses malformed parameters, with the error going back to the app', function () {
        [$client] = oauth_test_client();
        $params   = fn (array $overrides) => oauth_test_params($client, oauth_test_verifier(), $overrides);

        $cases = [
            ['scope' => ['openid']],
            ['response_type'  => 'token'],
            ['nonce'          => str_repeat('n', 256)],
            ['max_age'        => 'soon'],
            ['code_challenge' => 'too-short'],
            ['prompt'         => 'none consent'],
        ];

        $errors = array_map(fn ($overrides) => oauth_edge_authorize_error(array_merge($params([]), $overrides)), $cases);

        expect(array_map(fn ($error) => $error->error, $errors))->toBe(['invalid_request', 'unsupported_response_type', 'invalid_request', 'invalid_request', 'invalid_request', 'invalid_request'])
            ->and(array_filter($errors, fn ($error) => !$error->canRedirect()))->toBe([]);
    });

    it('asks for a sign-in when it cannot tell when the user signed in', function () {
        [$client] = oauth_test_client();
        $server   = oauth_test_server();

        expect($server->needsReauthentication($server->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier(), ['prompt' => 'login'])), null))->toBeTrue()
            ->and($server->needsReauthentication($server->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier())), null))->toBeFalse();
    });

    it('sends a declined request back to the app with access_denied and the state', function () {
        [$client] = oauth_test_client();
        $server   = oauth_test_server();

        $url = $server->deny($server->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier())));

        expect($url)->toStartWith(OAUTH_TEST_REDIRECT . '?error=access_denied')
            ->and($url)->toContain('state=state-123')
            ->and($url)->toContain('iss=');
    });

    it('keeps the query a redirect URI already has', function () {
        $error = new AuthorizeError('access_denied', null, 'https://app.example.com/cb?tenant=1', 's');

        expect($error->redirectUrl('https://api.fleetbase.test'))->toBe('https://app.example.com/cb?tenant=1&error=access_denied&state=s&iss=https%3A%2F%2Fapi.fleetbase.test');
    });
});

describe('token endpoint', function () {
    it('lets league reject a code or refresh token it cannot open', function () {
        [$client, $secret] = oauth_test_client();

        [$codeStatus, $code]       = oauth_test_token(['grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => $secret, 'code' => 'garbage', 'redirect_uri' => OAUTH_TEST_REDIRECT, 'code_verifier' => oauth_test_verifier()]);
        [$refreshStatus, $refresh] = oauth_test_token(['grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => $secret, 'refresh_token' => 'garbage']);

        expect($codeStatus)->toBe(400)
            ->and($code['error'])->toBe('invalid_request')
            ->and($refreshStatus)->toBe(401)
            ->and($refresh['error'])->toBe('invalid_request');
    });

    it('refuses a code whose grant was removed before it was redeemed', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        $code              = oauth_test_code($client, $verifier);
        OAuthGrant::query()->update(['revoked_at' => now()]);

        [$status, $body] = oauth_test_exchange($client, $secret, $code, $verifier);

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('invalid_grant');
    });

    it('refuses a code once its user is deactivated, and drops the grant', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        $code              = oauth_test_code($client, $verifier);
        User::where('uuid', 'user-owner')->update(['status' => 'inactive']);

        [$status, $body] = oauth_test_exchange($client, $secret, $code, $verifier);

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('invalid_grant')
            ->and(OAuthGrant::first()->isRevoked())->toBeTrue();
    });

    it('issues no ID token without openid', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();

        [, $body] = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier, ['scope' => 'organization:read']), $verifier);

        expect($body)->not->toHaveKey('id_token')
            ->and($body['scope'])->toBe('organization:read');
    });

    it('issues no ID token for a user who has since been deleted', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        $code              = oauth_test_code($client, $verifier, ['scope' => 'openid']);

        // Removing the user cascades to the grant in MySQL; here only the user row goes, so the
        // response type is the one that notices.
        User::where('uuid', 'user-owner')->forceDelete();
        $response = new Fleetbase\Auth\OAuthServer\IdTokenResponse(new IssueContext(), oauth_test_server()->keys(), 'https://api.fleetbase.test', new DateInterval('PT1H'));
        $token    = (new AccessTokenRepository(new IssueContext(), 'https://api.fleetbase.test'))->getNewToken(new ClientEntity($client->id, 'AppHelm', [OAUTH_TEST_REDIRECT], true), [new ScopeEntity('openid')], 'user-owner');
        $token->setPrivateKey(oauth_test_server()->keys()->privateKey());
        $token->setIdentifier('token-1');
        $token->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
        $response->setAccessToken($token);
        $response->setEncryptionKey(oauth_test_server()->keys()->encryptionKey());

        $body = json_decode((string) $response->generateHttpResponse(new Nyholm\Psr7\Response())->getBody(), true);

        expect($code)->toBeString()
            ->and($body)->not->toHaveKey('id_token');
    });
});

describe('revocation', function () {
    it('needs a token', function () {
        [$client, $secret] = oauth_test_client();

        $response = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['client_id' => $client->id, 'client_secret' => $secret]));

        expect($response->getStatusCode())->toBe(400);
    });

    it('ignores an access token signed by another key', function () {
        [$client, $secret] = oauth_test_client();
        $other             = OAuthKeys::generate(2048);
        $config            = Lcobucci\JWT\Configuration::forAsymmetricSigner(new Lcobucci\JWT\Signer\Rsa\Sha256(), Lcobucci\JWT\Signer\Key\InMemory::plainText($other['private']), Lcobucci\JWT\Signer\Key\InMemory::plainText($other['public']));
        $forged            = $config->builder()->identifiedBy('forged')->getToken($config->signer(), $config->signingKey())->toString();

        $response = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => $forged, 'client_id' => $client->id, 'client_secret' => $secret]));

        expect($response->getStatusCode())->toBe(200);
    });

    it('lets a public client revoke with its client_id alone, and refuses one that sends a secret', function () {
        [$client]        = oauth_test_client(['name' => 'Native'], confidential: false);
        $verifier        = oauth_test_verifier();
        [, $body]        = oauth_test_exchange($client, null, oauth_test_code($client, $verifier), $verifier);

        $withSecret = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => $body['refresh_token'], 'client_id' => $client->id, 'client_secret' => 'x']));
        $plain      = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => $body['refresh_token'], 'client_id' => $client->id]));

        expect($withSecret->getStatusCode())->toBe(401)
            ->and($plain->getStatusCode())->toBe(200)
            ->and(OAuthGrant::first()->isRevoked())->toBeTrue();
    });
});

describe('protected resources', function () {
    it('refuses a token whose row is gone', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        // A token row that is gone reads as revoked to league; one that is merely unusable (an
        // expiry in the past that league's clock leeway still let through) reaches the row check.
        Fleetbase\Models\OAuthAccessToken::query()->update(['expires_at' => now()->subMinute()]);

        $response = (new AuthenticateOAuthToken(oauth_test_server()))->handle(oauth_test_bearer($body['access_token']), fn () => throw new RuntimeException('must not run'));

        expect($response->getStatusCode())->toBe(401);
    });

    it('says when a route forgot the middleware or an organization scope', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier, ['scope' => 'openid'], 'user-member', null), $verifier);
        $request           = oauth_test_bearer($body['access_token']);

        expect(fn () => AuthenticateOAuthToken::token(Request::create('/x')))->toThrow(LogicException::class);

        (new AuthenticateOAuthToken(oauth_test_server()))->handle($request, fn () => null);

        expect(fn () => AuthenticateOAuthToken::company($request))->toThrow(LogicException::class)
            ->and($request->user()->uuid)->toBe('user-member');
    });
});

describe('repositories', function () {
    it('only know registered, enabled clients and the two supported grants', function () {
        [$client, $secret] = oauth_test_client();
        [$public]          = oauth_test_client(['name' => 'Native'], confidential: false);
        $repository        = new ClientRepository();

        expect($repository->getClientEntity('00000000-0000-0000-0000-000000000000'))->toBeNull()
            ->and($repository->getClientEntity('not-a-uuid'))->toBeNull()
            ->and($repository->validateClient($client->id, $secret, 'client_credentials'))->toBeFalse()
            ->and($repository->validateClient($client->id, $secret, 'authorization_code'))->toBeTrue()
            ->and($repository->validateClient($public->id, null, 'refresh_token'))->toBeTrue()
            ->and($repository->validateClient($public->id, 'secret', 'refresh_token'))->toBeFalse();
    });

    it('finalize no scopes for a client that no longer exists', function () {
        $client = new ClientEntity('00000000-0000-0000-0000-000000000000', 'Gone', [], true);

        expect((new ScopeRepository())->finalizeScopes([new ScopeEntity('openid')], 'authorization_code', $client))->toBe([]);
    });

    it('never issue a code or token outside a grant', function () {
        [$client] = oauth_test_client();
        $entity   = new ClientEntity($client->id, 'AppHelm', [OAUTH_TEST_REDIRECT], true);

        $code = new AuthCodeEntity();
        $code->setClient($entity);
        $access = new AccessTokenEntity('https://api.fleetbase.test');
        $access->setClient($entity);
        $refresh = new RefreshTokenEntity();
        $refresh->setAccessToken($access);

        expect(fn () => (new AuthCodeRepository(new IssueContext()))->persistNewAuthCode($code))->toThrow(LogicException::class)
            ->and(fn () => (new AccessTokenRepository(new IssueContext(), 'https://api.fleetbase.test'))->persistNewAccessToken($access))->toThrow(LogicException::class)
            ->and(fn () => (new RefreshTokenRepository(new IssueContext()))->persistNewRefreshToken($refresh))->toThrow(LogicException::class);
    });

    it('treat an unknown code or refresh token as revoked', function () {
        expect((new AuthCodeRepository(new IssueContext()))->isAuthCodeRevoked('nope'))->toBeTrue()
            ->and((new RefreshTokenRepository(new IssueContext()))->isRefreshTokenRevoked('nope'))->toBeTrue();
    });

    it('open nothing from a payload that is not theirs', function () {
        $crypt = new PayloadCrypt(oauth_test_server()->keys()->encryptionKey());

        expect($crypt->open(null))->toBeNull()
            ->and($crypt->open(''))->toBeNull()
            ->and($crypt->open('not-encrypted'))->toBeNull();
    });
});

describe('scopes registry', function () {
    it('validates names, describes scopes with the organization, and maps review types', function () {
        expect(fn () => OAuthScopes::register('Not Valid', []))->toThrow(InvalidArgumentException::class)
            ->and(OAuthScopes::get('openid')['organization'])->toBeFalse()
            ->and(OAuthScopes::get('missing'))->toBeNull()
            ->and(OAuthScopes::scopeForReviewAccessType('storefront-store'))->toBe('storefront:review-access')
            ->and(OAuthScopes::scopeForReviewAccessType('unknown'))->toBeNull()
            ->and(OAuthScopes::describe(['storefront:read', 'missing'], 'Acme'))->toBe([
                ['scope' => 'storefront:read', 'description' => "See Acme's storefronts", 'organization' => true],
            ])
            ->and(OAuthScopes::describe(['storefront:read'])[0]['description'])->toBe("See your organization's storefronts");
    });
});

describe('keys', function () {
    it('read key files from storage when no key is configured, and say when there are none', function () {
        $keys   = new OAuthKeys();
        $remove = function () {
            foreach (['private', 'public'] as $type) {
                if (file_exists(OAuthKeys::keyPath($type))) {
                    unlink(OAuthKeys::keyPath($type));
                }
            }
        };
        config(['oauth-server.private_key' => null, 'oauth-server.public_key' => null]);
        if (!is_dir(dirname(OAuthKeys::keyPath('private')))) {
            mkdir(dirname(OAuthKeys::keyPath('private')), 0777, true);
        }
        $remove();

        expect($keys->configured())->toBeFalse()
            ->and(fn () => $keys->privateKeyContents())->toThrow(RuntimeException::class);

        file_put_contents(OAuthKeys::keyPath('private'), oauth_test_keys()['private']);
        file_put_contents(OAuthKeys::keyPath('public'), oauth_test_keys()['public']);

        expect($keys->configured())->toBeTrue()
            ->and($keys->publicKeyContents())->toBe(oauth_test_keys()['public']);

        $remove();
    });

    it('need APP_KEY for the payload key', function () {
        config(['app.key' => '']);

        expect(fn () => (new OAuthKeys())->encryptionKey())->toThrow(RuntimeException::class);
    });
});

describe('clients and grants', function () {
    it('accept only https redirect URIs, or loopback http on a development app', function () {
        expect(OAuthClient::isAcceptableRedirectUri('https://app.example.com/cb'))->toBeTrue()
            ->and(OAuthClient::isAcceptableRedirectUri('https://app.example.com/cb#x'))->toBeFalse()
            ->and(OAuthClient::isAcceptableRedirectUri('not a url'))->toBeFalse()
            ->and(OAuthClient::isAcceptableRedirectUri('http://app.example.com/cb', true))->toBeFalse()
            ->and(OAuthClient::isAcceptableRedirectUri('http://127.0.0.1:3000/cb'))->toBeFalse()
            ->and(OAuthClient::isAcceptableRedirectUri('http://127.0.0.1:3000/cb', true))->toBeTrue()
            ->and(OAuthClient::isAcceptableRedirectUri('myapp://callback', true))->toBeFalse()
            ->and(OAuthClient::isLoopbackUri('https://127.0.0.1/cb'))->toBeFalse();
    });

    it('match no empty redirect URI, and a public client has no secret to verify', function () {
        [$client] = oauth_test_client(['name' => 'Native'], confidential: false);

        expect($client->allowsRedirectUri(null))->toBeFalse()
            ->and($client->allowsRedirectUri(''))->toBeFalse()
            ->and($client->verifySecret('anything'))->toBeFalse()
            ->and($client->toArray()['confidential'])->toBeFalse();
    });

    it('relate grants to their user, client and organization', function () {
        [$client] = oauth_test_client();
        $grant    = OAuthGrant::create(['user_uuid' => 'user-owner', 'client_id' => $client->id, 'company_uuid' => 'company-a', 'scopes' => ['openid']]);

        expect($grant->user->uuid)->toBe('user-owner')
            ->and($grant->client->id)->toBe($client->id)
            ->and($grant->company->uuid)->toBe('company-a')
            ->and($client->grants()->count())->toBe(1);

        OAuthGrant::revokeTokensFor([]);
        expect($grant->fresh()->isRevoked())->toBeFalse();
    });
});

describe('organizations', function () {
    it('can be shared only by an active member who administers them', function () {
        OAuthOrganizations::$administratorCheck = null;
        oauth_test_roles(['cu-admin-a' => 'Administrator', 'cu-member-a' => 'Dispatcher']);

        $companyA = Company::where('uuid', 'company-a')->first();
        $admin    = User::where('uuid', 'user-admin')->first();
        $member   = User::where('uuid', 'user-member')->first();
        $owner    = User::where('uuid', 'user-owner')->first();

        expect(OAuthOrganizations::canShare($admin, $companyA))->toBeTrue()
            ->and(OAuthOrganizations::canShare($member, $companyA))->toBeFalse()
            ->and(OAuthOrganizations::canShare($owner, Company::where('uuid', 'company-b')->first()))->toBeFalse();

        $admin->status = 'inactive';
        expect(OAuthOrganizations::canShare($admin, $companyA))->toBeFalse();
    });

    it('list the user\'s organizations for the picker and find one by public id', function () {
        $member = User::where('uuid', 'user-member')->first();

        expect(OAuthOrganizations::forPicker($member)->all())->toBe([
            ['id' => 'company_a', 'uuid' => 'company-a', 'name' => 'Acme Deliveries', 'logo_url' => Company::where('uuid', 'company-a')->first()->logo_url, 'can_share' => false],
            ['id' => 'company_b', 'uuid' => 'company-b', 'name' => 'Bravo Freight', 'logo_url' => Company::where('uuid', 'company-b')->first()->logo_url, 'can_share' => true],
        ])
            ->and(OAuthOrganizations::find($member, 'company_b')?->uuid)->toBe('company-b')
            ->and(OAuthOrganizations::find($member, null))->toBeNull()
            ->and(OAuthOrganizations::find(User::where('uuid', 'user-admin')->first(), 'company_b'))->toBeNull();
    });
});
