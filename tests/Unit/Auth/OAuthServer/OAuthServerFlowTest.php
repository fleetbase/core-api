<?php

require_once __DIR__ . '/OAuthServerTestSupport.php';

use Fleetbase\Auth\OAuthServer\Exceptions\AuthorizeError;
use Fleetbase\Auth\OAuthServer\Exceptions\TokenError;
use Fleetbase\Auth\OAuthServer\IdTokenResponse;
use Fleetbase\Models\Company;
use Fleetbase\Models\OAuthAccessToken;
use Fleetbase\Models\OAuthGrant;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

beforeEach(fn () => oauth_test_setup());
afterEach(fn () => oauth_test_teardown());

function oauth_flow_authorize_error(array $params): AuthorizeError
{
    try {
        oauth_test_server()->validateAuthorizationRequest($params);
    } catch (AuthorizeError $e) {
        return $e;
    }

    throw new RuntimeException('Expected the authorization request to be refused.');
}

function oauth_flow_token_error(Request $request, array $scopes = []): TokenError
{
    try {
        oauth_test_server()->authenticate($request, $scopes);
    } catch (TokenError $e) {
        return $e;
    }

    throw new RuntimeException('Expected the access token to be refused.');
}

describe('the full flow', function () {
    it('signs in, issues a signed ID token, and binds the tokens to the chosen organization', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();

        [$status, $body] = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        expect($status)->toBe(200)
            ->and($body)->toHaveKeys(['access_token', 'refresh_token', 'id_token', 'expires_in'])
            ->and($body['token_type'])->toBe('Bearer')
            ->and(explode(' ', $body['scope']))->toEqualCanonicalizing(['openid', 'profile', 'email', 'organization:read']);

        $jwt     = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText('empty', 'empty'));
        $idToken = $jwt->parser()->parse($body['id_token']);
        $claims  = $idToken->claims();

        expect($jwt->validator()->validate($idToken, new SignedWith(new Sha256(), InMemory::plainText(oauth_test_keys()['public']))))->toBeTrue()
            ->and($claims->get('iss'))->toBe('https://api.fleetbase.test')
            ->and($claims->get('aud'))->toBe([$client->id])
            ->and($claims->get('sub'))->toBe('user_owner')
            ->and($claims->get('nonce'))->toBe('nonce-abc')
            ->and($claims->get('email'))->toBe('owner@example.com')
            ->and($claims->get('email_verified'))->toBeTrue()
            ->and($claims->get('name'))->toBe('Olive Owner')
            ->and($claims->get('org_id'))->toBe('company_a')
            ->and($claims->get('at_hash'))->toBe(IdTokenResponse::accessTokenHash($body['access_token']))
            ->and($claims->has('auth_time'))->toBeTrue()
            ->and($idToken->headers()->get('kid'))->toBe(oauth_test_server()->keys()->keyId());

        $token = oauth_test_server()->authenticate(oauth_test_bearer($body['access_token']), ['organization:read']);

        expect($token->company_uuid)->toBe('company-a')
            ->and($token->user_uuid)->toBe('user-owner')
            ->and($token->grant->scopes)->toContain('organization:read');
    });

    it('sends iss and state back with the code (RFC 9207)', function () {
        [$client] = oauth_test_client();
        $server   = oauth_test_server();
        $result   = $server->approve(
            $server->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier())),
            User::where('uuid', 'user-owner')->first(),
            Company::where('uuid', 'company-a')->first(),
            null
        );

        parse_str((string) parse_url($result['redirect'], PHP_URL_QUERY), $query);

        expect($result['redirect'])->toStartWith(OAUTH_TEST_REDIRECT . '?')
            ->and($query['state'])->toBe('state-123')
            ->and($query['iss'])->toBe('https://api.fleetbase.test')
            ->and($query)->toHaveKey('code')
            ->and($result['created'])->toBeTrue();
    });

    it('signs in without an organization when only identity scopes are asked for', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        $code              = oauth_test_code($client, $verifier, ['scope' => 'openid email'], 'user-member', 'company-a');

        [$status, $body] = oauth_test_exchange($client, $secret, $code, $verifier);
        $token           = oauth_test_server()->authenticate(oauth_test_bearer($body['access_token']));

        expect($status)->toBe(200)
            ->and($token->company_uuid)->toBeNull()
            ->and(OAuthGrant::where('user_uuid', 'user-member')->first()->company_uuid)->toBeNull();
    });

    it('skips the consent screen only for a sign-in the user already approved', function () {
        [$client] = oauth_test_client();
        $server   = oauth_test_server();
        $user     = User::where('uuid', 'user-member')->first();
        $signIn   = fn (array $overrides = []) => $server->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier(), array_merge(['scope' => 'openid email'], $overrides)));

        expect($server->canSkipConsent($signIn(), $user))->toBeFalse();

        $server->approve($signIn(), $user, null, null);

        expect($server->canSkipConsent($signIn(), $user))->toBeTrue()
            ->and($server->canSkipConsent($signIn(['prompt' => 'consent']), $user))->toBeFalse()
            ->and($server->canSkipConsent($signIn(['scope' => 'openid email profile']), $user))->toBeFalse()
            ->and($server->canSkipConsent($signIn(['scope' => 'openid organization:read']), $user))->toBeFalse();
    });
});

describe('PKCE', function () {
    it('is required, even for a confidential client', function () {
        [$client] = oauth_test_client();
        $error    = oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier(), ['code_challenge' => null, 'code_challenge_method' => null]));

        expect($error->error)->toBe('invalid_request')
            ->and($error->canRedirect())->toBeTrue()
            ->and($error->redirectUrl('https://api.fleetbase.test'))->toContain('state=state-123');
    });

    it('accepts only S256', function () {
        [$client] = oauth_test_client();
        $verifier = oauth_test_verifier();
        $error    = oauth_flow_authorize_error(oauth_test_params($client, $verifier, ['code_challenge' => $verifier, 'code_challenge_method' => 'plain']));

        expect($error->error)->toBe('invalid_request')
            ->and($error->description)->toContain('S256');
    });

    it('refuses a code redeemed with the wrong verifier', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();

        [$status, $body] = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), oauth_test_verifier());

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('invalid_grant');
    });

    it('refuses a code redeemed with no verifier', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();

        [$status, $body] = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier, ['code_verifier' => null]);

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('invalid_request');
    });

    it('lets a public client sign in with PKCE alone', function () {
        [$client]        = oauth_test_client(['name' => 'Native app'], confidential: false);
        $verifier        = oauth_test_verifier();
        [$status, $body] = oauth_test_exchange($client, null, oauth_test_code($client, $verifier), $verifier);

        expect($client->isConfidential())->toBeFalse()
            ->and($status)->toBe(200)
            ->and($body)->toHaveKey('access_token');
    });
});

describe('redirect URIs', function () {
    it('never redirects to an unregistered URI', function () {
        [$client] = oauth_test_client();

        foreach ([
            'https://evil.example.com/oauth/callback',
            OAUTH_TEST_REDIRECT . '?extra=1',
            OAUTH_TEST_REDIRECT . '/',
            'http://app.example.com/oauth/callback',
        ] as $uri) {
            $error = oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier(), ['redirect_uri' => $uri]));

            expect($error->error)->toBe('invalid_request')
                ->and($error->canRedirect())->toBeFalse()
                ->and($error->redirectUrl('https://api.fleetbase.test'))->toBeNull();
        }
    });

    it('requires a redirect URI on every request', function () {
        [$client] = oauth_test_client();
        $error    = oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier(), ['redirect_uri' => null]));

        expect($error->canRedirect())->toBeFalse();
    });

    it('refuses an unknown or disabled client without redirecting', function () {
        [$client] = oauth_test_client();
        $client->forceFill(['disabled_at' => now()])->save();

        expect(oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier()))->error)->toBe('invalid_client')
            ->and(oauth_flow_authorize_error(['client_id' => 'not-a-client'])->canRedirect())->toBeFalse();
    });

    it('lets a development client use any loopback port, and nobody else', function () {
        [$dev]  = oauth_test_client(['redirect_uris' => ['http://127.0.0.1:3000/callback'], 'development' => true]);
        [$prod] = oauth_test_client(['redirect_uris' => ['http://127.0.0.1:3000/callback'], 'development' => false]);

        expect($dev->allowsRedirectUri('http://127.0.0.1:51234/callback'))->toBeTrue()
            ->and($dev->allowsRedirectUri('http://127.0.0.1:51234/other'))->toBeFalse()
            ->and($prod->allowsRedirectUri('http://127.0.0.1:51234/callback'))->toBeFalse()
            ->and($prod->allowsRedirectUri('http://127.0.0.1:3000/callback'))->toBeTrue();
    });

    it('refuses a code redeemed with a different redirect URI', function () {
        [$client, $secret] = oauth_test_client(['redirect_uris' => [OAUTH_TEST_REDIRECT, 'https://app.example.com/other']]);
        $verifier          = oauth_test_verifier();

        [$status, $body] = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier, ['redirect_uri' => 'https://app.example.com/other']);

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('invalid_request');
    });
});

describe('authorization codes', function () {
    it('expire', function () {
        config(['oauth-server.ttl.auth_code' => 'PT1S']);
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        $code              = oauth_test_code($client, $verifier);

        sleep(2);
        [$status, $body] = oauth_test_exchange($client, $secret, $code, $verifier);

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('invalid_request')
            ->and($body['hint'] ?? $body['error_description'])->toContain('expired');
    });

    it('work once, and a replay revokes the tokens already issued from it', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        $code              = oauth_test_code($client, $verifier);

        [, $first]       = oauth_test_exchange($client, $secret, $code, $verifier);
        [$status, $body] = oauth_test_exchange($client, $secret, $code, $verifier);

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('invalid_request')
            ->and(oauth_flow_token_error(oauth_test_bearer($first['access_token']))->error)->toBe('invalid_token');

        [$refreshStatus] = oauth_test_token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client->id, 'client_secret' => $secret]);
        expect($refreshStatus)->toBe(401);
    });

    it('belong to the client they were issued to', function () {
        [$client]                = oauth_test_client();
        [$other, $otherSecret]   = oauth_test_client(['name' => 'Other app']);
        $verifier                = oauth_test_verifier();

        [$status] = oauth_test_exchange($other, $otherSecret, oauth_test_code($client, $verifier), $verifier);

        expect($status)->toBe(400);
    });
});

describe('scopes', function () {
    it('refuses scopes the client was not registered for, or that do not exist', function () {
        [$client] = oauth_test_client(['allowed_scopes' => ['openid', 'email']]);

        expect(oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier(), ['scope' => 'openid organization:read']))->error)->toBe('invalid_scope')
            ->and(oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier(), ['scope' => 'openid admin:everything']))->error)->toBe('invalid_scope')
            ->and(oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier(), ['scope' => '']))->error)->toBe('invalid_scope');
    });

    it('refuses an endpoint that needs a scope the token does not have', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        $error = oauth_flow_token_error(oauth_test_bearer($body['access_token']), ['storefront:read']);

        expect($error->error)->toBe('insufficient_scope')
            ->and($error->status)->toBe(403)
            ->and($error->toResponse()->headers->get('WWW-Authenticate'))->toContain('scope="storefront:read"');
    });

    it('loses a scope at once when an admin removes it from the app', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        $client->forceFill(['allowed_scopes' => ['openid', 'profile', 'email']])->save();

        expect(oauth_flow_token_error(oauth_test_bearer($body['access_token']), ['organization:read'])->error)->toBe('insufficient_scope');
    });

    it('cannot widen scopes on refresh', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        [$status, $refreshed] = oauth_test_token(['grant_type' => 'refresh_token', 'refresh_token' => $body['refresh_token'], 'client_id' => $client->id, 'client_secret' => $secret, 'scope' => 'openid storefront:read']);

        expect($status)->toBe(400)
            ->and($refreshed['error'])->toBe('invalid_scope');
    });

    it('needs an organization the user administers for organization scopes', function () {
        [$client] = oauth_test_client();
        $server   = oauth_test_server();
        $request  = fn () => $server->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier()));
        $member   = User::where('uuid', 'user-member')->first();
        $admin    = User::where('uuid', 'user-admin')->first();
        $companyA = Company::where('uuid', 'company-a')->first();
        $companyB = Company::where('uuid', 'company-b')->first();

        expect(fn () => $server->approve($request(), $member, null, null))->toThrow(AuthorizeError::class)
            ->and(fn () => $server->approve($request(), $member, $companyA, null))->toThrow(AuthorizeError::class)
            ->and(fn () => $server->approve($request(), $admin, $companyB, null))->toThrow(AuthorizeError::class)
            ->and($server->approve($request(), $admin, $companyA, null)['grant']->company_uuid)->toBe('company-a')
            ->and($server->approve($request(), $member, $companyB, null)['grant']->company_uuid)->toBe('company-b');
    });
});

describe('refresh tokens', function () {
    it('rotate, and a replayed one revokes the whole grant', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $first]         = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);
        $refresh           = fn (string $token) => oauth_test_token(['grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => $client->id, 'client_secret' => $secret]);

        [$status, $second] = $refresh($first['refresh_token']);
        expect($status)->toBe(200)
            ->and($second['refresh_token'])->not->toBe($first['refresh_token'])
            ->and(oauth_test_server()->authenticate(oauth_test_bearer($second['access_token']))->company_uuid)->toBe('company-a')
            ->and(oauth_flow_token_error(oauth_test_bearer($first['access_token']))->error)->toBe('invalid_token');

        [$replayStatus] = $refresh($first['refresh_token']);
        expect($replayStatus)->toBe(401)
            ->and(OAuthGrant::first()->isRevoked())->toBeTrue()
            ->and(oauth_flow_token_error(oauth_test_bearer($second['access_token']))->error)->toBe('invalid_token');

        [$afterStatus] = $refresh($second['refresh_token']);
        expect($afterStatus)->toBe(401);
    });

    it('stop working when the user no longer administers the organization', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier, [], 'user-admin'), $verifier);

        Fleetbase\Auth\OAuthServer\OAuthOrganizations::$administratorCheck = fn () => false;
        [$status, $error]                                                  = oauth_test_token(['grant_type' => 'refresh_token', 'refresh_token' => $body['refresh_token'], 'client_id' => $client->id, 'client_secret' => $secret]);

        expect($status)->toBe(400)
            ->and($error['error'])->toBe('invalid_grant')
            ->and(OAuthGrant::first()->isRevoked())->toBeTrue();
    });
});

describe('access to the organization', function () {
    it('ends when the user leaves the organization', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        Fleetbase\Models\CompanyUser::where('uuid', 'cu-owner-a')->delete();

        expect(oauth_flow_token_error(oauth_test_bearer($body['access_token']))->error)->toBe('invalid_token')
            ->and(OAuthGrant::first()->isRevoked())->toBeTrue();
    });

    it('ends when the account is deactivated', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        User::where('uuid', 'user-owner')->update(['status' => 'inactive']);

        expect(oauth_flow_token_error(oauth_test_bearer($body['access_token']))->error)->toBe('invalid_token');
    });

    it('is refused for a forged or tampered token', function () {
        $other   = Fleetbase\Auth\OAuthServer\OAuthKeys::generate(2048);
        $config  = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($other['private']), InMemory::plainText($other['public']));
        $now     = new DateTimeImmutable('@' . time());
        $forged  = $config->builder()->identifiedBy('forged')->permittedFor('x')->relatedTo('user-owner')->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($now->modify('+1 hour'))->withClaim('scopes', ['organization:read'])->getToken($config->signer(), $config->signingKey());

        expect(oauth_flow_token_error(oauth_test_bearer($forged->toString()))->error)->toBe('invalid_token')
            ->and(oauth_flow_token_error(Request::create('/oauth/v1/organization'))->error)->toBe('invalid_token');
    });
});

describe('revocation', function () {
    it('of a refresh token disconnects the app', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        $response = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => $body['refresh_token'], 'token_type_hint' => 'refresh_token'], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($client->id . ':' . $secret),
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and(OAuthGrant::first()->isRevoked())->toBeTrue()
            ->and(oauth_flow_token_error(oauth_test_bearer($body['access_token']))->error)->toBe('invalid_token');
    });

    it('of an access token revokes only that token', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        $response = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => $body['access_token'], 'client_id' => $client->id, 'client_secret' => $secret]));

        [$status] = oauth_test_token(['grant_type' => 'refresh_token', 'refresh_token' => $body['refresh_token'], 'client_id' => $client->id, 'client_secret' => $secret]);

        expect($response->getStatusCode())->toBe(200)
            ->and(oauth_flow_token_error(oauth_test_bearer($body['access_token']))->error)->toBe('invalid_token')
            ->and(OAuthGrant::first()->isRevoked())->toBeFalse()
            ->and($status)->toBe(200);
    });

    it('needs the client to authenticate, and ignores other clients\' tokens', function () {
        [$client, $secret]     = oauth_test_client();
        [$other, $otherSecret] = oauth_test_client(['name' => 'Other app']);
        $verifier              = oauth_test_verifier();
        [, $body]              = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        $unauthenticated = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => $body['access_token'], 'client_id' => $client->id, 'client_secret' => 'wrong']));
        $otherClient     = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => $body['refresh_token'], 'client_id' => $other->id, 'client_secret' => $otherSecret]));
        $unknown         = oauth_test_server()->revoke(Request::create('/oauth/revoke', 'POST', ['token' => 'not-a-token', 'client_id' => $client->id, 'client_secret' => $secret]));

        expect($unauthenticated->getStatusCode())->toBe(401)
            ->and($otherClient->getStatusCode())->toBe(200)
            ->and($unknown->getStatusCode())->toBe(200)
            ->and(OAuthGrant::first()->isRevoked())->toBeFalse()
            ->and(oauth_test_server()->authenticate(oauth_test_bearer($body['access_token']))->user_uuid)->toBe('user-owner');
    });

    it('of a grant from Connected apps stops every token under it', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier), $verifier);

        OAuthGrant::first()->revoke();

        [$status] = oauth_test_token(['grant_type' => 'refresh_token', 'refresh_token' => $body['refresh_token'], 'client_id' => $client->id, 'client_secret' => $secret]);

        expect(oauth_flow_token_error(oauth_test_bearer($body['access_token']))->error)->toBe('invalid_token')
            ->and($status)->toBe(401)
            ->and(OAuthAccessToken::where('revoked', false)->count())->toBe(0);
    });
});

describe('clients', function () {
    it('authenticate with their secret, which rotation replaces', function () {
        [$client, $secret] = oauth_test_client();
        $verifier          = oauth_test_verifier();
        $code              = oauth_test_code($client, $verifier);

        [$wrongStatus, $wrong] = oauth_test_exchange($client, 'not-the-secret', $code, $verifier);
        expect($wrongStatus)->toBe(401)->and($wrong['error'])->toBe('invalid_client');

        $newSecret = $client->issueSecret();
        $client->save();

        [$oldStatus] = oauth_test_exchange($client, $secret, $code, $verifier);
        [$newStatus] = oauth_test_exchange($client, $newSecret, $code, $verifier);

        expect($oldStatus)->toBe(401)
            ->and($newStatus)->toBe(200)
            ->and($client->fresh()->secret)->not->toBe($newSecret)
            ->and($client->fresh()->toArray())->not->toHaveKey('secret');
    });

    it('cannot use grants other than authorization_code and refresh_token', function () {
        [$client, $secret] = oauth_test_client();

        [$status, $body] = oauth_test_token(['grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $secret]);

        expect($status)->toBe(400)
            ->and($body['error'])->toBe('unsupported_grant_type');
    });
});

describe('prompt and max_age', function () {
    it('asks for a fresh sign-in when prompt=login or max_age says so', function () {
        [$client] = oauth_test_client();
        $server   = oauth_test_server();
        $request  = fn (array $overrides) => $server->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier(), $overrides));

        expect($server->needsReauthentication($request(['prompt' => 'login']), new DateTimeImmutable('-10 minutes')))->toBeTrue()
            ->and($server->needsReauthentication($request(['prompt' => 'login']), new DateTimeImmutable('-1 minute')))->toBeFalse()
            ->and($server->needsReauthentication($request(['max_age' => '60']), new DateTimeImmutable('-2 minutes')))->toBeTrue()
            ->and($server->needsReauthentication($request([]), new DateTimeImmutable('-1 day')))->toBeFalse();
    });

    it('answers prompt=none with interaction_required', function () {
        [$client] = oauth_test_client();

        expect(oauth_flow_authorize_error(oauth_test_params($client, oauth_test_verifier(), ['prompt' => 'none']))->error)->toBe('interaction_required');
    });
});
