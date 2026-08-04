<?php

namespace Illuminate\Foundation\Auth\Access {
    if (!trait_exists(AuthorizesRequests::class)) {
        trait AuthorizesRequests
        {
        }
    }
}

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(DispatchesJobs::class)) {
        trait DispatchesJobs
        {
        }
    }
}

namespace Illuminate\Foundation\Validation {
    if (!trait_exists(ValidatesRequests::class)) {
        trait ValidatesRequests
        {
        }
    }
}

namespace {
    use Fleetbase\Http\Controllers\Api\v1\OrganizationController;
    use Fleetbase\Http\Resources\AuthOrganization;
    use Fleetbase\Http\Resources\Organization;
    use Fleetbase\Models\Company;
    use Fleetbase\Models\User;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Eloquent\Model as EloquentModel;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Http\Request;
    use Illuminate\Routing\Route;
    use Illuminate\Session\ArraySessionHandler;
    use Illuminate\Session\Store;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Facade;

    function organization_controller_database(): Capsule
    {
        EloquentModel::clearBootedModels();

        $connection = [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ];

        $container = bind_test_container([
            'database.default'                 => 'mysql',
            'database.connections.mysql'       => $connection,
            'database.connections.sandbox'     => $connection,
            'fleetbase.connection.db'          => 'mysql',
            'fleetbase.branding.icon_url'      => 'https://assets.test/icon.png',
            'fleetbase.branding.logo_url'      => 'https://assets.test/logo.png',
        ]);

        $capsule = new Capsule($container);
        $capsule->addConnection($connection, 'mysql');
        $capsule->addConnection($connection, 'sandbox');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection('mysql')->getSchemaBuilder());
        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('db.schema');
        Facade::clearResolvedInstance('schema');

        $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
        $schema->create('companies', function ($table) {
            $table->unsignedInteger('id')->nullable();
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable()->index();
            $table->string('user_id')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('phone')->nullable();
            $table->string('type')->nullable();
            $table->string('timezone')->nullable();
            $table->string('country')->nullable();
            $table->string('currency')->nullable();
            $table->string('plan')->nullable();
            $table->string('status')->nullable();
            $table->string('slug')->nullable();
            $table->json('options')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('users', function ($table) {
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('company_users', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('company_uuid')->nullable()->index();
            $table->string('user_uuid')->nullable()->index();
            $table->string('status')->nullable();
            $table->boolean('external')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('api_credentials', function ($table) {
            $table->string('uuid')->primary();
            $table->string('_key')->nullable();
            $table->string('user_uuid')->nullable();
            $table->string('company_uuid')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('key')->nullable()->index();
            $table->string('secret')->nullable()->index();
            $table->boolean('test_mode')->default(false);
            $table->string('api')->nullable();
            $table->json('browser_origins')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('personal_access_tokens', function ($table) {
            $table->increments('id');
            $table->string('tokenable_type');
            $table->string('tokenable_id');
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        $schema->create('settings', function ($table) {
            $table->increments('id');
            $table->string('key')->nullable()->index();
            $table->text('value')->nullable();
        });
        $schema->create('files', function ($table) {
            $table->string('uuid')->primary();
            $table->string('url')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $now = '2026-07-18 00:00:00';
        $capsule->getConnection('mysql')->table('users')->insert([
            ['uuid' => 'user-owner', 'public_id' => 'user_owner', 'company_uuid' => 'company-visible', 'email' => 'owner@example.com', 'name' => 'Owner User', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'user-member', 'public_id' => 'user_member', 'company_uuid' => 'company-visible', 'email' => 'member@example.com', 'name' => 'Member User', 'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'user-token', 'public_id' => 'user_token', 'company_uuid' => 'company-visible', 'email' => 'token@example.com', 'name' => 'Token User', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $capsule->getConnection('mysql')->table('companies')->insert([
            [
                'id'            => 11, 'uuid' => 'company-visible', 'public_id' => 'org_visible', 'user_id' => 'user-owner', 'owner_uuid' => 'user-owner',
                'name'          => 'Visible Logistics', 'description' => 'Primary organization', 'phone' => '+1 555 0100',
                'type'          => 'business', 'timezone' => 'UTC', 'country' => 'US', 'currency' => 'USD', 'plan' => 'starter',
                'status'        => 'active', 'slug' => 'visible-logistics', 'options' => '{"dispatch":true}', 'meta' => null,
                'trial_ends_at' => null, 'onboarding_completed_at' => '2026-07-01 00:00:00', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id'      => 12, 'uuid' => 'company-other', 'public_id' => 'org_other', 'user_id' => null, 'owner_uuid' => null,
                'name'    => 'Other Freight', 'description' => null, 'phone' => null, 'type' => null, 'timezone' => 'UTC',
                'country' => 'US', 'currency' => 'USD', 'plan' => null, 'status' => 'active', 'slug' => 'other-freight',
                'options' => null, 'meta' => null, 'trial_ends_at' => null, 'onboarding_completed_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id'      => 13, 'uuid' => 'company-hidden', 'public_id' => 'org_hidden', 'user_id' => null, 'owner_uuid' => null,
                'name'    => 'Hidden Empty Org', 'description' => null, 'phone' => null, 'type' => null, 'timezone' => 'UTC',
                'country' => 'US', 'currency' => 'USD', 'plan' => null, 'status' => 'active', 'slug' => 'hidden-empty-org',
                'options' => null, 'meta' => null, 'trial_ends_at' => null, 'onboarding_completed_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        $capsule->getConnection('mysql')->table('company_users')->insert([
            ['uuid' => 'company-user-owner', '_key' => null, 'company_uuid' => 'company-visible', 'user_uuid' => 'user-owner', 'status' => 'active', 'external' => false, 'created_at' => '2026-07-02 00:00:00', 'updated_at' => '2026-07-02 00:00:00'],
            ['uuid' => 'company-user-member', '_key' => null, 'company_uuid' => 'company-visible', 'user_uuid' => 'user-member', 'status' => 'active', 'external' => false, 'created_at' => '2026-07-03 00:00:00', 'updated_at' => '2026-07-03 00:00:00'],
            ['uuid' => 'company-user-other', '_key' => null, 'company_uuid' => 'company-other', 'user_uuid' => 'user-member', 'status' => 'active', 'external' => false, 'created_at' => '2026-07-04 00:00:00', 'updated_at' => '2026-07-04 00:00:00'],
        ]);
        $capsule->getConnection('mysql')->table('api_credentials')->insert([
            ['uuid' => 'credential-live', '_key' => null, 'user_uuid' => 'user-owner', 'company_uuid' => 'company-visible', 'name' => 'Live Key', 'key' => 'flb_live_visible', 'secret' => '$secret_visible', 'test_mode' => false, 'api' => 'fleetbase', 'browser_origins' => null, 'last_used_at' => null, 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $capsule->getConnection('mysql')->table('personal_access_tokens')->insert([
            ['id' => 1, 'tokenable_type' => User::class, 'tokenable_id' => 'user-token', 'name' => 'organization-current', 'token' => hash('sha256', 'plain-current-org-token'), 'abilities' => json_encode(['*']), 'last_used_at' => null, 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $capsule->getConnection('mysql')->table('settings')->insert([
            ['key' => 'branding.default_theme', 'value' => 'light'],
        ]);

        return $capsule;
    }

    function organization_request(string $method, string $uri, array $parameters = [], array $server = []): Request
    {
        $request = Request::create($uri, $method, $parameters, [], [], $server);
        $route   = new Route($method, ltrim($uri, '/'), [
            'controller' => OrganizationController::class . '@' . (str_contains($uri, 'current') ? 'getCurrent' : 'listOrganizations'),
        ]);
        $request->setRouteResolver(fn () => $route);
        app()->instance('request', $request);

        return $request;
    }

    afterEach(function () {
        session()->flush();
        config([
            'database.default'        => null,
            'database.connections'    => [],
            'fleetbase.connection.db' => null,
        ]);
        EloquentModel::clearBootedModels();
        Container::setInstance(new FleetbaseTestContainer());
        Facade::clearResolvedInstances();
    });

    test('public organizations listing filters by name limits results and excludes empty organizations', function () {
        organization_controller_database();

        $response = (new OrganizationController())->listOrganizations(organization_request('GET', '/v1/organizations', [
            'query' => 'Visible',
            'limit' => 1000,
        ]));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe([
                ['name' => 'Visible Logistics', 'public_id' => 'org_visible'],
            ]);
    });

    test('current organization resolves from API key and returns public resource shape', function () {
        organization_controller_database();

        $resource = (new OrganizationController())->getCurrent(organization_request('GET', '/v1/organizations/current', [], [
            'HTTP_AUTHORIZATION' => 'Bearer flb_live_visible',
        ]));
        $payload = $resource->resolve(app('request'));

        expect($resource)->toBeInstanceOf(Organization::class)
            ->and($payload['id'])->toBe('org_visible')
            ->and($payload['name'])->toBe('Visible Logistics')
            ->and($payload['branding'])->toMatchArray([
                'icon_url'      => 'https://assets.test/icon.png',
                'logo_url'      => 'https://assets.test/logo.png',
                'default_theme' => 'light',
            ])
            ->and($payload['owner'])->not->toBeNull()
            ->and($payload)->not->toHaveKeys(['uuid', 'owner_uuid', 'public_id', 'users_count', 'billing_status']);
    });

    test('current organization resolves from secret keys and sanctum fallback tokens', function () {
        organization_controller_database();

        $secretResource = (new OrganizationController())->getCurrent(organization_request('GET', '/v1/organizations/current', [], [
            'HTTP_AUTHORIZATION' => 'Bearer $secret_visible',
        ]));
        $sanctumResource = (new OrganizationController())->getCurrent(organization_request('GET', '/v1/organizations/current', [], [
            'HTTP_AUTHORIZATION' => 'Bearer 1|plain-current-org-token',
        ]));
        $invalidResponse = (new OrganizationController())->getCurrent(organization_request('GET', '/v1/organizations/current', [], [
            'HTTP_AUTHORIZATION' => 'Bearer missing-token',
        ]));

        expect($secretResource)->toBeInstanceOf(Organization::class)
            ->and($secretResource->resource->uuid)->toBe('company-visible')
            ->and($sanctumResource)->toBeInstanceOf(Organization::class)
            ->and($sanctumResource->resource->uuid)->toBe('company-visible')
            ->and($invalidResponse->getStatusCode())->toBe(400)
            ->and($invalidResponse->getData(true))->toBe([
                'errors' => ['No API key found to fetch company details with.'],
            ]);
    });

    test('organization resource includes internal identifiers counts billing and joined at for internal requests', function () {
        organization_controller_database();
        session(['user' => 'user-member']);

        $company = Company::where('uuid', 'company-visible')->first();
        $request = organization_request('GET', '/int/v1/organizations/current');
        $request->setLaravelSession(new Store('organization-resource', new ArraySessionHandler(120)));
        $request->session()->put('user', 'user-member');
        $payload = (new Organization($company))->resolve($request);

        expect($payload['id'])->toBe(11)
            ->and($payload['uuid'])->toBe('company-visible')
            ->and($payload['owner_uuid'])->toBe('user-owner')
            ->and($payload['public_id'])->toBe('org_visible')
            ->and($payload['users_count'])->toBe(2)
            ->and($payload['plan'])->toBe('starter')
            ->and($payload['billing_status'])->toBe('legacy')
            ->and($payload['onboarding_completed'])->toBeTrue()
            ->and((string) $payload['joined_at'])->toContain('2026-07-03');

        $company->joined_at  = Carbon::parse('2026-07-09 12:30:00');
        $directJoinPayload   = (new Organization($company))->resolve($request);

        expect((string) $directJoinPayload['joined_at'])->toContain('2026-07-09');
    });

    test('auth organization resource returns authenticated organization response contract', function () {
        organization_controller_database();

        $company = Company::where('uuid', 'company-visible')->first();
        $company->setAttribute('users_count', 2);
        $company->setAttribute('joined_at', '2026-07-03 00:00:00');
        $payload = (new AuthOrganization($company))->resolve(organization_request('GET', '/int/v1/auth/organizations'));

        expect($payload['id'])->toBe(11)
            ->and($payload['uuid'])->toBe('company-visible')
            ->and($payload['public_id'])->toBe('org_visible')
            ->and($payload['name'])->toBe('Visible Logistics')
            ->and($payload['users_count'])->toBe(2)
            ->and($payload['branding'])->toMatchArray([
                'icon_url'      => 'https://assets.test/icon.png',
                'logo_url'      => 'https://assets.test/logo.png',
                'default_theme' => 'light',
            ])
            ->and($payload['owner'])->toBe([
                'uuid'  => 'user-owner',
                'name'  => 'Owner User',
                'email' => 'owner@example.com',
            ])
            ->and($payload['billing_status'])->toBe('legacy')
            ->and($payload['onboarding_completed'])->toBeTrue()
            ->and($payload['joined_at'])->toBe('2026-07-03 00:00:00');
    });

    test('current organization returns a structured error when no API key is provided', function () {
        organization_controller_database();

        $response = (new OrganizationController())->getCurrent(organization_request('GET', '/v1/organizations/current'));

        expect($response->getStatusCode())->toBe(400)
            ->and($response->getData(true))->toBe([
                'errors' => ['No API key found to fetch company details with.'],
            ]);
    });
}
