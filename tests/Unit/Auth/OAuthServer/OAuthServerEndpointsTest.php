<?php

require_once __DIR__ . '/OAuthServerTestSupport.php';

use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Http\Controllers\OAuthServer\DiscoveryController;
use Fleetbase\Http\Controllers\OAuthServer\ResourceController;
use Fleetbase\Http\Middleware\AuthenticateOAuthToken;
use Fleetbase\Models\Company;
use Fleetbase\Models\ReviewAccess;
use Fleetbase\Support\AppReview;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

beforeEach(function () {
    $capsule   = oauth_test_setup();
    $container = Illuminate\Container\Container::getInstance();
    $container->instance('hash', new class {
        public function make($value, array $options = []): string
        {
            return password_hash((string) $value, PASSWORD_BCRYPT);
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return is_string($hashedValue) && password_verify((string) $value, $hashedValue);
        }
    });
    $container->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    $container->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Illuminate\Support\Facades\Facade::clearResolvedInstance('hash');
    Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');

    (require __DIR__ . '/../../../../migrations/2026_10_24_000000_create_review_accesses_table.php')->up();
    ReviewAccess::creating(fn ($access) => $access->uuid ??= (string) Str::uuid());

    // An organization-level review type, the way fleetops registers Navigator, covered by a scope.
    AppReview::register('test-navigator', ['model' => Company::class, 'phone' => '+12025550144', 'name' => 'Navigator']);
    OAuthScopes::register('navigator:review-access', ['description' => 'Turn App Review sign-in on or off', 'organization' => true, 'review_access_types' => ['test-navigator']]);
});

afterEach(function () {
    AppReview::forget('test-navigator');
    oauth_test_teardown();
});

/**
 * Sign in user-owner to company A with these scopes; returns the access token.
 */
function oauth_endpoint_token(string $scope, string $userUuid = 'user-owner', string $companyUuid = 'company-a'): string
{
    [$client, $secret] = oauth_test_client(['allowed_scopes' => array_keys(OAuthScopes::all())]);
    $verifier          = oauth_test_verifier();
    [, $body]          = oauth_test_exchange($client, $secret, oauth_test_code($client, $verifier, ['scope' => $scope], $userUuid, $companyUuid), $verifier);

    return $body['access_token'];
}

/**
 * Run a request through the middleware and controller action, as the router would.
 */
function oauth_endpoint_call(Request $request, array $scopes, Closure $action)
{
    return (new AuthenticateOAuthToken(oauth_test_server()))->handle($request, $action, ...$scopes);
}

it('publishes discovery metadata that matches the endpoints and the signing key', function () {
    $metadata = (new DiscoveryController())->configuration(oauth_test_server())->getData(true);
    $jwks     = (new DiscoveryController())->jwks(oauth_test_server())->getData(true);

    expect($metadata['issuer'])->toBe('https://api.fleetbase.test')
        ->and($metadata['authorization_endpoint'])->toBe('https://api.fleetbase.test/oauth/authorize')
        ->and($metadata['token_endpoint'])->toBe('https://api.fleetbase.test/oauth/token')
        ->and($metadata['code_challenge_methods_supported'])->toBe(['S256'])
        ->and($metadata['grant_types_supported'])->toBe(['authorization_code', 'refresh_token'])
        ->and($metadata['scopes_supported'])->toContain('openid', 'organization:read', 'storefront:read')
        ->and($jwks['keys'][0]['kid'])->toBe(oauth_test_server()->keys()->keyId())
        ->and($jwks['keys'][0])->toHaveKeys(['n', 'e'])
        ->and($jwks['keys'][0])->not->toHaveKey('d');
});

it('returns the organization on the token, not the user\'s current one', function () {
    $token = oauth_endpoint_token('openid organization:read');
    Fleetbase\Models\User::where('uuid', 'user-owner')->update(['company_uuid' => 'company-b']);

    $response = oauth_endpoint_call(oauth_test_bearer($token), ['organization:read'], fn ($request) => (new ResourceController())->organization($request));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['organization'])->toMatchArray([
            'id'       => 'company_a',
            'name'     => 'Acme Deliveries',
            'currency' => 'USD',
            'country'  => 'US',
        ]);
});

it('answers userinfo with the claims the scopes allow', function () {
    $token = oauth_endpoint_token('openid email');

    $claims = oauth_endpoint_call(oauth_test_bearer($token, '/oauth/userinfo'), ['openid'], fn ($request) => (new ResourceController())->userinfo($request))->getData(true);

    expect($claims)->toBe(['sub' => 'user_owner', 'email' => 'owner@example.com', 'email_verified' => true]);
});

it('refuses a request without the route\'s scope, with an RFC 6750 challenge', function () {
    $token = oauth_endpoint_token('openid email');

    $response = oauth_endpoint_call(oauth_test_bearer($token), ['organization:read'], fn () => throw new RuntimeException('must not run'));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['error'])->toBe('insufficient_scope')
        ->and($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer error="insufficient_scope"');
});

describe('review access', function () {
    function oauth_review_call(string $token, string $method, string $id = 'company_a', array $input = [])
    {
        $request = Request::create('/oauth/v1/review-access/test-navigator/' . $id, $method, $input, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return oauth_endpoint_call($request, [], fn ($request) => (new ResourceController())->reviewAccess($request, 'test-navigator', $id));
    }

    it('turns access on with a code shown once, reads it without the code, and turns it off', function () {
        $token = oauth_endpoint_token('openid navigator:review-access');

        $on = oauth_review_call($token, 'PUT')->getData(true)['review_access'];
        expect($on['enabled'])->toBeTrue()
            ->and($on['phone'])->toBe('+12025550144')
            ->and($on['code'])->toMatch('/^\d{6}$/');

        $again = oauth_review_call($token, 'PUT')->getData(true)['review_access'];
        $read  = oauth_review_call($token, 'GET')->getData(true)['review_access'];
        expect($again)->not->toHaveKey('code')
            ->and($read)->not->toHaveKey('code')
            ->and($read['has_code'])->toBeTrue();

        $regenerated = oauth_review_call($token, 'PUT', 'company_a', ['regenerate_code' => true])->getData(true)['review_access'];
        expect($regenerated['code'])->toMatch('/^\d{6}$/');

        $off = oauth_review_call($token, 'DELETE')->getData(true)['review_access'];
        expect($off['enabled'])->toBeFalse()
            ->and($off['has_code'])->toBeTrue();
    });

    it('needs the scope that covers the type', function () {
        $token = oauth_endpoint_token('openid organization:read');

        $response = oauth_review_call($token, 'PUT');

        expect($response->getStatusCode())->toBe(403)
            ->and($response->getData(true)['error'])->toBe('insufficient_scope');
    });

    it('does not know a type nobody registered', function () {
        $token   = oauth_endpoint_token('openid navigator:review-access');
        $request = Request::create('/oauth/v1/review-access/unknown/company_a', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $response = oauth_endpoint_call($request, [], fn ($request) => (new ResourceController())->reviewAccess($request, 'unknown', 'company_a'));

        expect($response->getStatusCode())->toBe(404);
    });

    it('cannot reach another organization', function () {
        $token = oauth_endpoint_token('openid navigator:review-access');

        expect(oauth_review_call($token, 'PUT', 'company_b')->getStatusCode())->toBe(404)
            ->and(ReviewAccess::count())->toBe(0);
    });
});
