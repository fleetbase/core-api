<?php

require_once __DIR__ . '/OAuthServerTestSupport.php';

use Fleetbase\Console\Commands\OAuthServerPrune;
use Fleetbase\Http\Controllers\Internal\v1\OAuthAppController;
use Fleetbase\Http\Controllers\OAuthServer\RegistrationController;
use Fleetbase\Http\Controllers\OAuthServer\ResourceController;
use Fleetbase\Http\Middleware\AuthenticateOAuthToken;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\OAuthClient;
use Fleetbase\Models\OAuthGrant;
use Fleetbase\Observers\ApiCredentialObserver;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const OAUTH_REGISTRATION_REDIRECT = 'https://shop.example.com/wp-admin/admin-post.php?action=fleetbase_wc_oauth';

beforeEach(function () {
    oauth_test_setup();
    // The api_credentials table as it is before this release, then the real migration on top.
    Capsule::connection('mysql')->getSchemaBuilder()->create('api_credentials', function ($table) {
        $table->increments('id');
        $table->string('_key')->nullable();
        $table->string('uuid')->nullable()->unique();
        $table->string('user_uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('key')->nullable();
        $table->string('secret')->nullable();
        $table->integer('test_mode')->default(0);
        $table->string('api')->nullable();
        $table->json('browser_origins')->nullable();
        $table->dateTime('last_used_at')->nullable();
        $table->dateTime('expires_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    (require __DIR__ . '/../../../../migrations/2026_10_27_000001_add_oauth_registration_columns.php')->up();
    config([
        'oauth-server.registration' => [
            'enabled'             => true,
            'unused_client_hours' => 24,
            'software'            => [
                'fleetbase-woocommerce' => [
                    'name'        => 'Fleetbase for WooCommerce',
                    'description' => 'Delivery for WooCommerce.',
                    'website_url' => 'https://github.com/fleetbase/woocommerce',
                    'scopes'      => ['organization:read', 'api-key:create', 'not-a-registered-scope'],
                ],
            ],
        ],
    ]);
});
afterEach(function () {
    Carbon::setTestNow();
    oauth_test_teardown();
});

/**
 * POST /oauth/register. Returns [status, decoded body].
 */
function oauth_register(array $body): array
{
    $response = (new RegistrationController())->register(Request::create('/oauth/register', 'POST', $body));

    return [$response->getStatusCode(), $response->getData(true)];
}

/**
 * The observer that generates keys on api_credentials, as in production.
 */
function oauth_registration_api_credentials(): void
{
    Container::getInstance()->instance('hash', new class {
        public function make(string $value): string
        {
            return 'hashed:' . $value;
        }
    });
    ApiCredential::flushEventListeners();
    ApiCredential::created(fn (ApiCredential $credential) => (new ApiCredentialObserver())->created($credential));
}

/**
 * Run a registered public client through consent and the token endpoint, as a plugin does.
 */
function oauth_registration_tokens(OAuthClient $client, string $userUuid = 'user-owner', string $companyUuid = 'company-a'): array
{
    $verifier = oauth_test_verifier();
    $code     = oauth_test_code($client, $verifier, [
        'redirect_uri' => OAUTH_REGISTRATION_REDIRECT,
        'scope'        => 'organization:read api-key:create',
        'nonce'        => null,
    ], $userUuid, $companyUuid);

    return oauth_test_exchange($client, null, $code, $verifier, ['redirect_uri' => OAUTH_REGISTRATION_REDIRECT]);
}

/**
 * Call POST /oauth/v1/api-key the way the route does: the middleware first, then the controller.
 */
function oauth_registration_api_key(string $accessToken, array $headers = []): array
{
    $server  = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create('/oauth/v1/api-key', 'POST', [], [], [], array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken], $server));
    Container::getInstance()->instance('request', $request);

    $response = (new AuthenticateOAuthToken(oauth_test_server()))->handle($request, fn (Request $request) => (new ResourceController())->apiKey($request), 'api-key:create');

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
}

describe('registration', function () {
    it('registers a public client for one site, named after its host and never official', function () {
        [$status, $body] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);

        $client = OAuthClient::find($body['client_id']);

        expect($status)->toBe(201)
            ->and($body)->toMatchArray([
                'client_name'                => 'Fleetbase for WooCommerce (shop.example.com)',
                'software_id'                => 'fleetbase-woocommerce',
                'redirect_uris'              => [OAUTH_REGISTRATION_REDIRECT],
                'grant_types'                => ['authorization_code', 'refresh_token'],
                'response_types'             => ['code'],
                'token_endpoint_auth_method' => 'none',
                'scope'                      => 'organization:read api-key:create',
            ])
            ->and($body['client_id_issued_at'])->toBeInt()
            ->and($client->isConfidential())->toBeFalse()
            ->and($client->official)->toBeFalse()
            ->and($client->development)->toBeFalse()
            ->and($client->isRegistered())->toBeTrue()
            ->and($client->allowed_scopes)->toBe(['organization:read', 'api-key:create'])
            ->and($client->website_url)->toBe('https://github.com/fleetbase/woocommerce');
    });

    it('returns the same client when the same site registers again', function () {
        [, $first]            = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        [$status, $second]    = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        [, $otherSite]        = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => ['https://other.example.com/wp-admin/admin-post.php?action=fleetbase_wc_oauth']]);

        expect($status)->toBe(200)
            ->and($second['client_id'])->toBe($first['client_id'])
            ->and($otherSite['client_id'])->not->toBe($first['client_id'])
            ->and(OAuthClient::whereNotNull('software_id')->count())->toBe(2);
    });

    it('lets a loopback http site register as a development client', function () {
        [$status, $body] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => ['http://localhost:8800/wp-admin/admin-post.php?action=fleetbase_wc_oauth']]);

        expect($status)->toBe(201)
            ->and($body['client_name'])->toBe('Fleetbase for WooCommerce (localhost)')
            ->and(OAuthClient::find($body['client_id'])->development)->toBeTrue();
    });

    it('refuses unknown software and bad redirect URIs', function (array $body, int $status, string $error) {
        [$code, $response] = oauth_register($body);

        expect($code)->toBe($status)
            ->and($response['error'])->toBe($error)
            ->and($response['error_description'])->toBeString()
            ->and(OAuthClient::whereNotNull('software_id')->count())->toBe(0);
    })->with([
        'unknown software'      => [['software_id' => 'someone-else', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]], 400, 'invalid_client_metadata'],
        'no software'           => [['redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]], 400, 'invalid_client_metadata'],
        'no redirect'           => [['software_id' => 'fleetbase-woocommerce'], 400, 'invalid_redirect_uri'],
        'two redirects'         => [['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT, 'https://b.example.com/cb']], 400, 'invalid_redirect_uri'],
        'plain http'            => [['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => ['http://shop.example.com/cb']], 400, 'invalid_redirect_uri'],
        'custom scheme'         => [['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => ['myapp://callback']], 400, 'invalid_redirect_uri'],
        'fragment'              => [['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => ['https://shop.example.com/cb#x']], 400, 'invalid_redirect_uri'],
        'redirect not a string' => [['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [['nested']]], 400, 'invalid_redirect_uri'],
    ]);

    it('is turned off by configuration, and discovery stops advertising it', function () {
        expect(oauth_test_server()->discovery()['registration_endpoint'])->toBe('https://api.fleetbase.test/oauth/register');

        config(['oauth-server.registration.enabled' => false]);

        expect(oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]))
            ->toBe([404, ['error' => 'registration_disabled', 'error_description' => 'Client registration is turned off on this server.']])
            ->and(oauth_test_server()->discovery())->not->toHaveKey('registration_endpoint');
    });

    it('refuses a site whose registered client an admin disabled', function () {
        [, $body] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        OAuthClient::find($body['client_id'])->forceFill(['disabled_at' => now()])->save();

        [$status, $again] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);

        expect($status)->toBe(400)
            ->and($again['error'])->toBe('invalid_client_metadata')
            ->and(OAuthClient::whereNotNull('software_id')->count())->toBe(1);
    });
});

describe('connecting a registered site', function () {
    it('signs in with PKCE alone and only to the registered redirect', function () {
        [, $body] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        $client   = OAuthClient::find($body['client_id']);

        [$status, $tokens] = oauth_registration_tokens($client);

        expect($status)->toBe(200)
            ->and(explode(' ', $tokens['scope']))->toEqualCanonicalizing(['organization:read', 'api-key:create'])
            ->and($tokens)->not->toHaveKey('id_token');

        // Never redirected: the error is shown on Fleetbase, not sent to the unregistered site.
        expect(oauth_flow_registration_error($client, 'https://attacker.example.com/cb'))->not->toEndWith(':redirect')
            ->and(oauth_flow_registration_error($client, 'https://attacker.example.com/cb'))->not->toBe('accepted');
    });

    it('creates one live API key per site and organization, and returns it again', function () {
        oauth_registration_api_credentials();
        [, $body]   = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        $client     = OAuthClient::find($body['client_id']);
        [, $tokens] = oauth_registration_tokens($client);

        [$status, $first] = oauth_registration_api_key($tokens['access_token'], ['Access-Console-Sandbox' => 'true']);
        [$again, $second] = oauth_registration_api_key($tokens['access_token']);

        $credential = ApiCredential::where('oauth_client_id', $client->id)->first();

        expect($status)->toBe(201)
            ->and($first['api_key']['created'])->toBeTrue()
            ->and($first['api_key']['name'])->toBe('Fleetbase for WooCommerce (shop.example.com)')
            ->and($first['api_key']['key'])->toMatch('/^flb_live_[A-Za-z0-9]{32}$/')
            ->and($again)->toBe(200)
            ->and($second['api_key']['created'])->toBeFalse()
            ->and($second['api_key']['key'])->toBe($first['api_key']['key'])
            ->and($credential->company_uuid)->toBe('company-a')
            ->and($credential->user_uuid)->toBe('user-owner')
            ->and((bool) $credential->test_mode)->toBeFalse()
            ->and(ApiCredential::query()->count())->toBe(1);
    });

    it('never returns a key the app did not create, even with the same name', function () {
        oauth_registration_api_credentials();
        [, $body] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        $client   = OAuthClient::find($body['client_id']);
        Capsule::connection('mysql')->table('api_credentials')->insert([
            'uuid' => 'manual-key', 'company_uuid' => 'company-a', 'name' => $client->name, 'key' => 'flb_live_handmade', 'test_mode' => 0,
        ]);
        [, $tokens] = oauth_registration_tokens($client);

        [$status, $minted] = oauth_registration_api_key($tokens['access_token']);

        expect($status)->toBe(201)
            ->and($minted['api_key']['key'])->not->toBe('flb_live_handmade')
            ->and(ApiCredential::query()->count())->toBe(2);
    });

    it('needs the api-key:create scope', function () {
        oauth_registration_api_credentials();
        [, $body]   = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        $client     = OAuthClient::find($body['client_id']);
        $verifier   = oauth_test_verifier();
        $code       = oauth_test_code($client, $verifier, ['redirect_uri' => OAUTH_REGISTRATION_REDIRECT, 'scope' => 'organization:read', 'nonce' => null]);
        [, $tokens] = oauth_test_exchange($client, null, $code, $verifier, ['redirect_uri' => OAUTH_REGISTRATION_REDIRECT]);

        [$status, $error] = oauth_registration_api_key($tokens['access_token']);

        expect($status)->toBe(403)
            ->and($error['error'])->toBe('insufficient_scope')
            ->and(ApiCredential::query()->count())->toBe(0);
    });
});

describe('revoking a site', function () {
    it('expires its key once no admin\'s grant for that organization is left', function () {
        oauth_registration_api_credentials();
        [, $body]  = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        $client    = OAuthClient::find($body['client_id']);
        [, $owner] = oauth_registration_tokens($client, 'user-owner');
        [, $admin] = oauth_registration_tokens($client, 'user-admin');
        oauth_registration_api_key($owner['access_token']);

        $ownerGrant = OAuthGrant::where('client_id', $client->id)->where('user_uuid', 'user-owner')->first();
        $adminGrant = OAuthGrant::where('client_id', $client->id)->where('user_uuid', 'user-admin')->first();

        $expiresAt = fn () => Capsule::connection('mysql')->table('api_credentials')->value('expires_at');

        $ownerGrant->revoke();
        expect($expiresAt())->toBeNull()
            ->and(ApiCredential::query()->count())->toBe(1);

        $adminGrant->revoke();
        // Expired keys drop out of ApiCredential queries (Expirable), so the key is unusable.
        expect($expiresAt())->not->toBeNull()
            ->and(ApiCredential::query()->count())->toBe(0);
    });

    it('expires keys when an admin disables the app, and a fresh key is minted after reconnecting', function () {
        oauth_registration_api_credentials();
        Container::getInstance()->instance('validator', new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), Container::getInstance()));
        [, $body]   = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        $client     = OAuthClient::find($body['client_id']);
        [, $tokens] = oauth_registration_tokens($client);
        [, $first]  = oauth_registration_api_key($tokens['access_token']);

        $admin   = Fleetbase\Models\User::where('uuid', 'user-owner')->first();
        $request = Fleetbase\Http\Requests\AdminRequest::create('/int/v1/oauth-server/apps/' . $client->id . '/disable', 'POST');
        $request->setUserResolver(fn () => $admin);
        (new OAuthAppController())->disable($request, $client->id);

        Carbon::setTestNow(now()->addMinute());
        $client->refresh()->forceFill(['disabled_at' => null])->save();
        [, $tokens]        = oauth_registration_tokens($client);
        [$status, $second] = oauth_registration_api_key($tokens['access_token']);

        expect($status)->toBe(201)
            ->and($second['api_key']['key'])->not->toBe($first['api_key']['key']);
    });

    it('leaves keys alone on a database without api_credentials.oauth_client_id', function () {
        [, $body] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        $client   = OAuthClient::find($body['client_id']);
        oauth_registration_tokens($client);
        Capsule::connection('mysql')->getSchemaBuilder()->drop('api_credentials');

        OAuthGrant::first()->revoke();
        OAuthGrant::expireMintedKeysFor([]);

        expect(OAuthGrant::first()->isRevoked())->toBeTrue();
    });
});

describe('pruning', function () {
    it('deletes registered clients that never got a grant after a day, and keeps the rest', function () {
        Carbon::setTestNow('2026-10-27 12:00:00');
        [, $unused]   = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => ['https://unused.example.com/cb']]);
        [, $used]     = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => [OAUTH_REGISTRATION_REDIRECT]]);
        [$official]   = oauth_test_client();
        oauth_registration_tokens(OAuthClient::find($used['client_id']));

        Carbon::setTestNow('2026-10-28 13:00:00');
        [, $recent] = oauth_register(['software_id' => 'fleetbase-woocommerce', 'redirect_uris' => ['https://recent.example.com/cb']]);

        $command = new OAuthServerPrune();
        $command->setLaravel(Container::getInstance());
        $output = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $code = $command->handle();

        expect($code)->toBe(0)
            ->and($output->fetch())->toContain('and 1 unused registered clients.')
            ->and(OAuthClient::find($unused['client_id']))->toBeNull()
            ->and(OAuthClient::find($used['client_id']))->not->toBeNull()
            ->and(OAuthClient::find($recent['client_id']))->not->toBeNull()
            ->and(OAuthClient::find($official->id))->not->toBeNull();
    });
});

/**
 * The error code an authorization request gets when it names a redirect the client doesn't have.
 */
function oauth_flow_registration_error(OAuthClient $client, string $redirect): string
{
    try {
        oauth_test_server()->validateAuthorizationRequest(oauth_test_params($client, oauth_test_verifier(), [
            'redirect_uri' => $redirect,
            'scope'        => 'organization:read api-key:create',
        ]));
    } catch (Fleetbase\Auth\OAuthServer\Exceptions\AuthorizeError $e) {
        return $e->error . ($e->canRedirect() ? ':redirect' : '');
    }

    return 'accepted';
}
