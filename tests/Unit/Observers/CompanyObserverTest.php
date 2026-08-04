<?php

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Observers\CompanyObserver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

class CompanyObserverCacheFake
{
    public array $forgotten = [];

    public function forget(string $key): bool
    {
        $this->forgotten[] = $key;

        return true;
    }
}

class CompanyObserverCompanySpy extends Company
{
    public array $loaded = [];

    public function loadMissing($relations): static
    {
        $this->loaded[] = $relations;

        return $this;
    }
}

function company_observer_user(string $uuid): User
{
    $user = new User();
    $user->setRawAttributes(['uuid' => $uuid], true);

    return $user;
}

function company_observer_subject(): array
{
    bind_test_container();

    $cache = new CompanyObserverCacheFake();
    Cache::swap($cache);

    $company = new CompanyObserverCompanySpy();
    $company->setRelation('users', new Collection([
        company_observer_user('user-1'),
        company_observer_user('user-2'),
    ]));

    return [$company, $cache, new CompanyObserver()];
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

it('clears both organization cache versions for each company user across lifecycle events', function (string $event) {
    [$company, $cache, $observer] = company_observer_subject();

    $observer->{$event}($company);

    expect($company->loaded)->toBe([['users:uuid']])
        ->and($cache->forgotten)->toBe([
            'user_organizations_user-1',
            'user_organizations_v2_user-1',
            'user_organizations_user-2',
            'user_organizations_v2_user-2',
        ]);
})->with([
    'created',
    'updated',
    'deleted',
    'restored',
    'forceDeleted',
]);
