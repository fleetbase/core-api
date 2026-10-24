<?php

use Fleetbase\Auth\OAuthServer\OAuthKeys;
use Fleetbase\Auth\OAuthServer\OAuthOrganizations;
use Fleetbase\Auth\OAuthServer\OAuthScopes;
use Fleetbase\Auth\OAuthServer\OAuthServer;
use Fleetbase\Models\OAuthClient;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/*
 * Shared setup for the OAuth provider tests: an in-memory sqlite database with the real
 * migration, two organizations, three users and two registered apps, and league's server
 * wired to Fleetbase's repositories exactly as in production.
 */

const OAUTH_TEST_REDIRECT = 'https://app.example.com/oauth/callback';

function oauth_test_keys(): array
{
    static $keys = null;

    return $keys ??= OAuthKeys::generate(2048);
}

function oauth_test_setup(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $keys       = oauth_test_keys();

    $container = bind_test_container([
        'app.key'                    => 'base64:' . base64_encode(str_repeat('k', 32)),
        'app.url'                    => 'https://api.fleetbase.test',
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
        'oauth-server.private_key'   => $keys['private'],
        'oauth-server.public_key'    => $keys['public'],
        'oauth-server.ttl'           => ['auth_code' => 'PT10M', 'access_token' => 'PT1H', 'refresh_token' => 'P30D', 'id_token' => 'PT1H'],
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');

    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('email')->nullable();
        $table->string('name')->nullable();
        $table->string('timezone')->nullable();
        $table->string('avatar_uuid')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name');
        $table->string('logo_uuid')->nullable();
        $table->string('currency')->nullable();
        $table->string('country')->nullable();
        $table->string('timezone')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('url')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    (require __DIR__ . '/../../../../migrations/2026_10_25_000001_create_oauth_server_tables.php')->up();

    $db  = $capsule->getConnection('mysql');
    $now = '2026-10-01 00:00:00';
    $db->table('users')->insert([
        ['uuid' => 'user-owner', 'public_id' => 'user_owner', 'type' => 'user', 'status' => 'active', 'email' => 'owner@example.com', 'name' => 'Olive Owner', 'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-admin', 'public_id' => 'user_admin', 'type' => 'user', 'status' => 'active', 'email' => 'admin@example.com', 'name' => 'Ada Admin', 'email_verified_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-member', 'public_id' => 'user_member', 'type' => 'user', 'status' => 'active', 'email' => 'member@example.com', 'name' => 'Mo Member', 'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now],
    ]);
    $db->table('companies')->insert([
        ['uuid' => 'company-a', 'public_id' => 'company_a', 'owner_uuid' => 'user-owner', 'name' => 'Acme Deliveries', 'currency' => 'USD', 'country' => 'US', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-b', 'public_id' => 'company_b', 'owner_uuid' => 'user-member', 'name' => 'Bravo Freight', 'currency' => 'SGD', 'country' => 'SG', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $db->table('company_users')->insert([
        ['uuid' => 'cu-owner-a', 'company_uuid' => 'company-a', 'user_uuid' => 'user-owner', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'cu-admin-a', 'company_uuid' => 'company-a', 'user_uuid' => 'user-admin', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'cu-member-a', 'company_uuid' => 'company-a', 'user_uuid' => 'user-member', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'cu-member-b', 'company_uuid' => 'company-b', 'user_uuid' => 'user-member', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
    ]);

    // Only user-admin holds company A's Administrator role.
    OAuthOrganizations::$administratorCheck = fn ($membership) => $membership->uuid === 'cu-admin-a';

    OAuthScopes::flush();
    OAuthScopes::registerDefaults();
    OAuthScopes::register('storefront:read', ['description' => "See {organization}'s storefronts", 'organization' => true]);
    OAuthScopes::register('storefront:review-access', ['description' => 'Turn App Review sign-in on or off', 'organization' => true, 'review_access_types' => ['storefront-store']]);

    return $capsule;
}

/**
 * Real spatie/permission roles for company memberships, keyed by company_users.uuid.
 *
 * @param array<string, string> $roles
 */
function oauth_test_roles(array $roles): void
{
    config([
        'auth.defaults.guard'                          => 'sanctum',
        'permission.models.permission'                 => Fleetbase\Models\Permission::class,
        'permission.models.role'                       => Fleetbase\Models\Role::class,
        'permission.table_names.roles'                 => 'roles',
        'permission.table_names.permissions'           => 'permissions',
        'permission.table_names.model_has_permissions' => 'model_has_permissions',
        'permission.table_names.model_has_roles'       => 'model_has_roles',
        'permission.table_names.role_has_permissions'  => 'role_has_permissions',
        'permission.column_names.model_morph_key'      => 'model_uuid',
    ]);
    Container::getInstance()->instance(Spatie\Permission\PermissionRegistrar::class, new class {
        public string $pivotRole       = 'role_id';
        public string $pivotPermission = 'permission_id';
        public bool $teams             = false;
        public string $teamsKey        = 'team_id';

        public function getRoleClass(): string
        {
            return Fleetbase\Models\Role::class;
        }

        public function getPermissionClass(): string
        {
            return Fleetbase\Models\Permission::class;
        }

        public function getPermissions(array $params = [], bool $onlyOne = false): Illuminate\Database\Eloquent\Collection
        {
            return new Illuminate\Database\Eloquent\Collection();
        }
    });

    $schema = Capsule::connection('mysql')->getSchemaBuilder();
    $schema->create('roles', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('model_has_roles', function ($table) {
        $table->string('role_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('permissions', function ($table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('guard_name')->default('sanctum');
        $table->timestamps();
    });
    $schema->create('model_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('model_type');
        $table->string('model_uuid');
    });
    $schema->create('role_has_permissions', function ($table) {
        $table->string('permission_id');
        $table->string('role_id');
    });

    $db = Capsule::connection('mysql');
    foreach (array_values(array_unique($roles)) as $index => $name) {
        $db->table('roles')->insert(['id' => 'role-' . $index, 'name' => $name, 'guard_name' => 'sanctum']);
    }
    $ids = $db->table('roles')->pluck('id', 'name');
    foreach ($roles as $membership => $name) {
        $db->table('model_has_roles')->insert(['role_id' => $ids[$name], 'model_type' => Fleetbase\Models\CompanyUser::class, 'model_uuid' => $membership]);
    }
}

/**
 * Register an app. Returns [client, plain secret or null].
 */
function oauth_test_client(array $attributes = [], bool $confidential = true): array
{
    $client = new OAuthClient(array_merge([
        'name'           => 'AppHelm',
        'redirect_uris'  => [OAUTH_TEST_REDIRECT],
        'allowed_scopes' => ['openid', 'profile', 'email', 'organization:read', 'storefront:read'],
        'official'       => true,
    ], $attributes));
    $secret = $confidential ? $client->issueSecret() : null;
    $client->save();

    return [$client, $secret];
}

function oauth_test_server(): OAuthServer
{
    return new OAuthServer();
}

function oauth_test_verifier(): string
{
    return OAuthKeys::base64url(random_bytes(48));
}

function oauth_test_challenge(string $verifier): string
{
    return OAuthKeys::base64url(hash('sha256', $verifier, true));
}

function oauth_test_params(OAuthClient $client, string $verifier, array $overrides = []): array
{
    return array_filter(array_merge([
        'response_type'         => 'code',
        'client_id'             => $client->id,
        'redirect_uri'          => OAUTH_TEST_REDIRECT,
        'scope'                 => 'openid profile email organization:read',
        'state'                 => 'state-123',
        'nonce'                 => 'nonce-abc',
        'code_challenge'        => oauth_test_challenge($verifier),
        'code_challenge_method' => 'S256',
    ], $overrides), fn ($value) => $value !== null);
}

/**
 * Run the consent step and return the code from the redirect.
 */
function oauth_test_code(OAuthClient $client, string $verifier, array $overrides = [], string $userUuid = 'user-owner', ?string $companyUuid = 'company-a'): string
{
    $server        = oauth_test_server();
    $authorization = $server->validateAuthorizationRequest(oauth_test_params($client, $verifier, $overrides));
    $user          = Fleetbase\Models\User::where('uuid', $userUuid)->first();
    $company       = $companyUuid ? Fleetbase\Models\Company::where('uuid', $companyUuid)->first() : null;
    $result        = $server->approve($authorization, $user, $company, new DateTimeImmutable('-1 minute'));

    parse_str((string) parse_url($result['redirect'], PHP_URL_QUERY), $query);

    return $query['code'];
}

/**
 * POST to the token endpoint. Returns [status, decoded body].
 */
function oauth_test_token(array $params): array
{
    $request  = Request::create('/oauth/token', 'POST', $params);
    $response = oauth_test_server()->respondToTokenRequest($request);

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
}

function oauth_test_exchange(OAuthClient $client, ?string $secret, string $code, string $verifier, array $overrides = []): array
{
    return oauth_test_token(array_filter(array_merge([
        'grant_type'    => 'authorization_code',
        'client_id'     => $client->id,
        'client_secret' => $secret,
        'code'          => $code,
        'redirect_uri'  => OAUTH_TEST_REDIRECT,
        'code_verifier' => $verifier,
    ], $overrides), fn ($value) => $value !== null));
}

function oauth_test_bearer(string $accessToken, string $uri = '/oauth/v1/organization'): Request
{
    return Request::create($uri, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken]);
}

function oauth_test_teardown(): void
{
    OAuthOrganizations::$administratorCheck = null;
    OAuthScopes::flush();
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
}
