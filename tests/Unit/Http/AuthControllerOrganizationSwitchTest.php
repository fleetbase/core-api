<?php

use Fleetbase\Http\Controllers\Internal\v1\AuthController;
use Fleetbase\Http\Requests\SwitchOrganizationRequest;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class AuthControllerOrganizationSwitchUserSpy extends User
{
    public array $assignedCompanyIds = [];

    public function assignCompanyFromId(?string $id): self
    {
        $this->assignedCompanyIds[] = $id;
        $this->company_uuid         = $id;

        return $this;
    }
}

function auth_controller_organization_switch_container(): void
{
    $container = bind_test_container([
        'app.timezone' => 'UTC',
    ]);

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    config([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = app('db')->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('company_users');
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('user_uuid');
        $table->string('company_uuid');
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    session()->flush();
}

function auth_controller_organization_switch_user(string $companyUuid = 'company-current'): AuthControllerOrganizationSwitchUserSpy
{
    $user = new AuthControllerOrganizationSwitchUserSpy();
    $user->setRawAttributes([
        'uuid'         => '11111111-1111-4111-8111-111111111111',
        'company_uuid' => $companyUuid,
        'type'         => 'admin',
    ], true);

    return $user;
}

function auth_controller_organization_switch_request(User $user, string $next): SwitchOrganizationRequest
{
    $request = SwitchOrganizationRequest::create('/int/v1/organizations/switch', 'POST', [
        'next' => $next,
    ]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

afterEach(function () {
    session()->flush();
    Facade::clearResolvedInstances();
});

test('switch organization returns a stable response when the requested company is already active', function () {
    auth_controller_organization_switch_container();
    $user = auth_controller_organization_switch_user('company-current');

    $response = (new AuthController())->switchOrganization(
        auth_controller_organization_switch_request($user, 'company-current')
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'errors' => ['User is already on this organizations session'],
        ])
        ->and($user->assignedCompanyIds)->toBe([])
        ->and(session('company'))->toBeNull();
});

test('switch organization rejects companies outside the current users memberships', function () {
    auth_controller_organization_switch_container();
    $user = auth_controller_organization_switch_user('company-current');

    $response = (new AuthController())->switchOrganization(
        auth_controller_organization_switch_request($user, 'company-next')
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'errors' => ['You do not belong to this organization'],
        ])
        ->and($user->assignedCompanyIds)->toBe([])
        ->and($user->company_uuid)->toBe('company-current')
        ->and(session('company'))->toBeNull();
});

test('switch organization updates the user and session after membership is verified', function () {
    auth_controller_organization_switch_container();
    $user = auth_controller_organization_switch_user('company-current');

    app('db')->table('company_users')->insert([
        'uuid'         => 'company-user-1',
        'user_uuid'    => '11111111-1111-4111-8111-111111111111',
        'company_uuid' => 'company-next',
        'deleted_at'   => null,
        'created_at'   => '2026-07-18 10:00:00',
        'updated_at'   => '2026-07-18 10:00:00',
    ]);

    $response = (new AuthController())->switchOrganization(
        auth_controller_organization_switch_request($user, 'company-next')
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'ok'])
        ->and($user->assignedCompanyIds)->toBe(['company-next'])
        ->and($user->company_uuid)->toBe('company-next')
        ->and(session('company'))->toBe('company-next')
        ->and(session('user'))->toBe('11111111-1111-4111-8111-111111111111')
        ->and(session('is_admin'))->toBeTrue()
        ->and(session('is_customer'))->toBeFalse()
        ->and(session('is_driver'))->toBeFalse();
});
