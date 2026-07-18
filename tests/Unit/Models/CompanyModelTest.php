<?php

use Fleetbase\Models\Company;
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

it('exposes stable logo backdrop ownership and notification response values', function () {
    company_model_container();

    $owner = new User();
    $owner->setRawAttributes(['uuid' => 'owner-1'], true);

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

    expect($company->isOwner($owner))->toBeTrue()
        ->and($company->routeNotificationForTwilio())->toBe('+15555550100')
        ->and($company->logo_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png')
        ->and($company->backdrop_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/default-storefront-backdrop.png')
        ->and($brandedCompany->logo_url)->toBe('https://cdn.example.test/logo.png')
        ->and($brandedCompany->backdrop_url)->toBe('https://cdn.example.test/backdrop.png');
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
