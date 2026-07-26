<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class CompanyModelSaveSpy extends Company
{
    public int $saves         = 0;
    public array $roleChanges = [];

    public function save(array $options = []): bool
    {
        $this->saves++;
        $this->syncOriginal();

        return true;
    }

    public function changeUserRole(User $user, string $roleName): bool
    {
        $this->roleChanges[] = [$user->uuid, $roleName];

        return true;
    }
}

class CompanyModelCompanyUserSpy extends CompanyUser
{
    public array $assignedRoles = [];

    public function __construct(private bool $shouldFail = false)
    {
        parent::__construct();
        $this->setRawAttributes(['uuid' => 'company-user-1'], true);
    }

    public function assignSingleRole($role): CompanyUser
    {
        if ($this->shouldFail) {
            throw new RuntimeException('role backend unavailable');
        }

        $this->assignedRoles[] = $role;

        return $this;
    }
}

class CompanyModelRoleSpy extends Company
{
    public function __construct(private ?CompanyUser $companyUser = null, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setRawAttributes(['uuid' => 'company-1'], true);
    }

    public function getCompanyUserPivot(string|User $user): ?CompanyUser
    {
        return $this->companyUser;
    }
}

class CompanyModelAssignUserSpy extends Company
{
    public array $addedUsers = [];
    private CompanyUser $createdCompanyUser;

    public function __construct(?CompanyUser $companyUser = null)
    {
        parent::__construct();
        $this->createdCompanyUser = $companyUser ?? new CompanyUser();
        $this->setRawAttributes(['uuid' => 'company-1'], true);
    }

    public function addUser(User $user, string $role = 'Administrator', string $status = 'active'): CompanyUser
    {
        $this->addedUsers[] = [$user->uuid, $role, $status];

        return $this->createdCompanyUser;
    }
}

function company_model_container(): void
{
    $container = bind_test_container();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    config([
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
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
}

function company_model_create_users_table(): void
{
    app('db')->connection('mysql')->getSchemaBuilder()->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('email')->nullable();
        $table->string('name')->nullable();
        $table->timestamp('last_login')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
}

it('sets owner status and role assignment contracts without requiring database writes', function () {
    company_model_container();

    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-1'], true);

    $company = new CompanyModelSaveSpy();
    $company->setRawAttributes(['uuid' => 'company-1', 'status' => 'pending'], true);

    expect($company->setOwner($user))->toBe($company)
        ->and($company->owner_uuid)->toBe('user-1')
        ->and($company->onboarding_completed_by_uuid)->toBeNull()
        ->and($company->setOwner($user, true))->toBe($company)
        ->and($company->onboarding_completed_by_uuid)->toBe('user-1')
        ->and($company->setStatus('suspended'))->toBe($company)
        ->and($company->status)->toBe('suspended')
        ->and($company->activate())->toBe($company)
        ->and($company->status)->toBe('active')
        ->and($company->assignOwner($user))->toBe($user)
        ->and($company->owner_uuid)->toBe('user-1')
        ->and($company->saves)->toBe(1)
        ->and($company->roleChanges)->toBe([['user-1', 'Administrator']]);
});

it('resolves owner relationships from loaded owner or creator relations', function () {
    company_model_container();

    $owner = new User();
    $owner->setRawAttributes(['uuid' => 'owner-1'], true);
    $creator = new User();
    $creator->setRawAttributes(['uuid' => 'creator-1'], true);

    $companyWithOwner = new Company();
    $companyWithOwner->setRawAttributes(['uuid' => 'company-1', 'owner_uuid' => 'owner-1'], true);
    $companyWithOwner->setRelation('owner', $owner);
    $companyWithOwner->setRelation('creator', null);

    $companyWithCreator = new Company();
    $companyWithCreator->setRawAttributes(['uuid' => 'company-2', 'owner_uuid' => null], true);
    $companyWithCreator->setRelation('owner', null);
    $companyWithCreator->setRelation('creator', $creator);

    expect($companyWithOwner->loadCompanyOwner())->toBe($companyWithOwner)
        ->and($companyWithOwner->owner)->toBe($owner)
        ->and($companyWithCreator->loadCompanyOwner())->toBe($companyWithCreator)
        ->and($companyWithCreator->owner)->toBe($creator);
});

it('resolves company owner from the database only for valid owner uuids', function () {
    company_model_container();
    company_model_create_users_table();

    app('db')->table('users')->insert([
        'uuid'       => '11111111-1111-4111-8111-111111111111',
        'email'      => 'owner@example.test',
        'name'       => 'Owner User',
        'deleted_at' => null,
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);

    $company = new Company();
    $company->setRawAttributes([
        'uuid'       => 'company-1',
        'owner_uuid' => '11111111-1111-4111-8111-111111111111',
    ], true);
    $company->setRelation('owner', null);
    $company->setRelation('creator', null);

    $missing = new Company();
    $missing->setRawAttributes(['uuid' => 'company-2', 'owner_uuid' => 'not-a-uuid'], true);
    $missing->setRelation('owner', null);
    $missing->setRelation('creator', null);

    expect($company->loadCompanyOwner())->toBe($company)
        ->and($company->owner)->toBeInstanceOf(User::class)
        ->and($company->owner->uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($missing->loadCompanyOwner())->toBe($missing)
        ->and($missing->owner)->toBeNull();
});

it('exposes company relation contracts with stable keys and related models', function () {
    company_model_container();

    if (!class_exists(Fleetbase\Billing\Models\Subscription::class)) {
        eval('namespace Fleetbase\Billing\Models; class Subscription extends \Illuminate\Database\Eloquent\Model {}');
    }
    if (!class_exists(Fleetbase\FleetOps\Models\Driver::class)) {
        eval('namespace Fleetbase\FleetOps\Models; class Driver extends \Illuminate\Database\Eloquent\Model {}');
    }

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1'], true);

    expect($company->creator()->getRelated())->toBeInstanceOf(User::class)
        ->and($company->owner()->getRelated())->toBeInstanceOf(User::class)
        ->and($company->billingSubscriptions()->getRelated())->toBeInstanceOf(Fleetbase\Billing\Models\Subscription::class)
        ->and($company->users()->getRelated())->toBeInstanceOf(User::class)
        ->and($company->companyUsers()->getRelated())->toBeInstanceOf(User::class)
        ->and($company->logo()->getRelated())->toBeInstanceOf(Fleetbase\Models\File::class)
        ->and($company->backdrop()->getRelated())->toBeInstanceOf(Fleetbase\Models\File::class)
        ->and($company->drivers()->getRelated())->toBeInstanceOf(Fleetbase\FleetOps\Models\Driver::class)
        ->and($company->apiCredentials()->getRelated())->toBeInstanceOf(Fleetbase\Models\ApiCredential::class);
});

it('reports the latest user login across company users', function () {
    company_model_container();
    company_model_create_users_table();

    app('db')->connection('mysql')->getSchemaBuilder()->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid');
        $table->string('user_uuid');
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    app('db')->table('users')->insert([
        [
            'uuid'       => 'user-1',
            'email'      => 'first@example.test',
            'name'       => 'First User',
            'last_login' => '2026-07-17 10:00:00',
            'deleted_at' => null,
            'created_at' => '2026-07-17 00:00:00',
            'updated_at' => '2026-07-17 00:00:00',
        ],
        [
            'uuid'       => 'user-2',
            'email'      => 'second@example.test',
            'name'       => 'Second User',
            'last_login' => '2026-07-18 11:30:00',
            'deleted_at' => null,
            'created_at' => '2026-07-17 00:00:00',
            'updated_at' => '2026-07-17 00:00:00',
        ],
    ]);
    app('db')->table('company_users')->insert([
        [
            'uuid'         => 'company-user-1',
            'company_uuid' => 'company-1',
            'user_uuid'    => 'user-1',
            'deleted_at'   => null,
            'created_at'   => '2026-07-17 00:00:00',
            'updated_at'   => '2026-07-17 00:00:00',
        ],
        [
            'uuid'         => 'company-user-2',
            'company_uuid' => 'company-1',
            'user_uuid'    => 'user-2',
            'deleted_at'   => null,
            'created_at'   => '2026-07-17 00:00:00',
            'updated_at'   => '2026-07-17 00:00:00',
        ],
    ]);

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1'], true);

    expect($company->getLastUserLogin())->toBe('2026-07-18 11:30:00');
});

it('exposes stable logo backdrop ownership and notification response values', function () {
    company_model_container();

    $owner = new User();
    $owner->setRawAttributes(['uuid' => 'owner-1'], true);
    $otherUser = new User();
    $otherUser->setRawAttributes(['uuid' => 'other-user'], true);

    $company = new Company();
    $company->setRawAttributes([
        'uuid'       => 'company-1',
        'owner_uuid' => 'owner-1',
        'phone'      => '+15555550100',
    ], true);
    $company->setRelation('logo', null);
    $company->setRelation('backdrop', null);

    $logo           = (object) ['url' => 'https://cdn.example.test/logo.png'];
    $backdrop       = (object) ['url' => 'https://cdn.example.test/backdrop.png'];
    $brandedCompany = new Company();
    $brandedCompany->setRawAttributes(['uuid' => 'company-2'], true);
    $brandedCompany->setRelation('logo', $logo);
    $brandedCompany->setRelation('backdrop', $backdrop);
    session(['user' => $owner]);

    expect($company->isOwner($owner))->toBeTrue()
        ->and($company->isOwner($otherUser))->toBeFalse()
        ->and($company->is_owner)->toBeTrue()
        ->and($company->routeNotificationForTwilio())->toBe('+15555550100')
        ->and($company->logo_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png')
        ->and($company->backdrop_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png')
        ->and($brandedCompany->logo_url)->toBe('https://cdn.example.test/logo.png')
        ->and($brandedCompany->backdrop_url)->toBe('https://cdn.example.test/backdrop.png');

    session(['user' => $otherUser]);

    expect($company->is_owner)->toBeFalse();
});

it('changes company user roles and reports missing or failing pivot assignments', function () {
    company_model_container();

    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-1'], true);
    $companyUser = new CompanyModelCompanyUserSpy();
    $company     = new CompanyModelRoleSpy($companyUser);

    expect($company->changeUserRole($user, 'Dispatcher'))->toBeTrue()
        ->and($companyUser->assignedRoles)->toBe(['Dispatcher']);

    expect(fn () => (new CompanyModelRoleSpy(null))->changeUserRole($user, 'Dispatcher'))
        ->toThrow(InvalidArgumentException::class, 'The specified user is not associated with the company.');

    expect(fn () => (new CompanyModelRoleSpy(new CompanyModelCompanyUserSpy(true)))->changeUserRole($user, 'Dispatcher'))
        ->toThrow(Exception::class, 'Role assignment failed. Please try again later.');
});

it('assigns users through company membership and active company helpers', function () {
    company_model_container();

    $user = new class extends User {
        public array $assignedCompanies = [];

        public function assignCompany(Company $company, string $role = 'Administrator'): User
        {
            $this->assignedCompanies[] = [$company->uuid, $role];

            return $this;
        }
    };
    $user->setRawAttributes(['uuid' => 'user-1'], true);

    $companyUser = new CompanyModelCompanyUserSpy();
    $company     = new CompanyModelAssignUserSpy($companyUser);

    expect($company->assignUser($user, 'Dispatcher'))->toBe($companyUser)
        ->and($company->addedUsers)->toBe([['user-1', 'Dispatcher', 'active']])
        ->and($user->assignedCompanies)->toBe([['company-1', 'Administrator']]);
});

it('resolves the current company from session using the configured connection', function () {
    company_model_container();

    app('db')->connection('mysql')->getSchemaBuilder()->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    app('db')->table('companies')->insert([
        'uuid'       => 'company-1',
        'name'       => 'Acme Logistics',
        'public_id'  => 'company_public_1',
        'deleted_at' => null,
        'created_at' => '2026-07-17 10:00:00',
        'updated_at' => '2026-07-17 10:00:00',
    ]);

    session(['company' => 'company-1']);

    $company = Company::currentSession();

    expect($company)->toBeInstanceOf(Company::class)
        ->and($company->uuid)->toBe('company-1')
        ->and($company->name)->toBe('Acme Logistics');

    session()->flush();

    expect(Company::currentSession())->toBeNull();
});
