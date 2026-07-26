<?php

use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Traits\HasPolicies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class HasPoliciesSubject extends Model
{
    use HasPolicies;

    public array $detachedPolicies = [];

    public array $loadedRelations = [];

    public array $syncedPolicies = [];

    public ?bool $forceDeletingForTest = null;

    public function getDefaultGuardName(): string
    {
        return 'sanctum';
    }

    public function convertPipeToArray(string $pipeString): array|string
    {
        return $this->_convertPipeToArray($pipeString);
    }

    public function resolveStoredPolicy(string|Policy $policy): Policy
    {
        return $this->getStoredPolicy($policy);
    }

    public function policies()
    {
        return new HasPoliciesRelationFake($this);
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
    }

    public function forgetCachedPermissions()
    {
        return true;
    }

    public function getModel()
    {
        return $this;
    }

    public function fireSavedEventForTest(): void
    {
        $this->fireModelEvent('saved', false);
    }

    public function isForceDeleting(): bool
    {
        return (bool) $this->forceDeletingForTest;
    }
}

class HasPoliciesPolicyRepositoryFake
{
    public array $calls = [];

    public function findByIdentifier(string $identifier, string $guard): Policy
    {
        $this->calls[] = ['findByIdentifier', $identifier, $guard];

        return has_policies_policy($identifier, 'Resolved Identifier', $guard);
    }

    public function findByName(string $name, string $guard): Policy
    {
        $this->calls[] = ['findByName', $name, $guard];

        return has_policies_policy('policy-resolved-name', $name, $guard);
    }
}

class HasPoliciesPolicySubject extends Policy
{
    use HasPolicies;
}

class HasPoliciesPermissionSubject extends Permission
{
    use HasPolicies;
}

class HasPoliciesBuilderFake extends Builder
{
    public array $whereHasCalls = [];

    public array $whereInCalls = [];

    public ?self $subQuery = null;

    public function __construct()
    {
    }

    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        $this->whereHasCalls[] = compact('relation', 'operator', 'count');

        if ($callback) {
            $callback($this->subQuery ?? $this);
        }

        return $this;
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $this->whereInCalls[] = compact('column', 'values', 'boolean', 'not');

        return $this;
    }
}

class HasPoliciesRelationFake
{
    public function __construct(private HasPoliciesSubject $subject)
    {
    }

    public function detach(mixed $policies = null): void
    {
        $this->subject->detachedPolicies[] = $policies;
    }

    public function sync(array $policies, bool $detaching = true): void
    {
        $policies = array_values($policies);

        $this->subject->syncedPolicies[] = compact('policies', 'detaching');
    }
}

function has_policies_policy(string|int $id, string $name, string $guard = 'sanctum'): Policy
{
    $policy = new Policy();
    $policy->setRawAttributes([
        'id'         => $id,
        'name'       => $name,
        'guard_name' => $guard,
    ], true);

    return $policy;
}

function has_policies_subject(): HasPoliciesSubject
{
    bind_test_container([
        'auth.defaults.guard' => 'sanctum',
        'auth.guards.sanctum' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
        'auth.providers.users' => [
            'driver' => 'eloquent',
            'model'  => HasPoliciesSubject::class,
        ],
        'permission.table_names.policies' => 'policies',
    ]);

    $subject = new HasPoliciesSubject();
    $subject->setRelation('policies', collect([
        has_policies_policy('policy-dispatch', 'Dispatch Manager'),
        has_policies_policy(22, 'Billing Manager'),
        has_policies_policy('policy-web', 'Web Only', 'web'),
    ]));
    $subject->setRelation('permissions', collect([
        new Permission(['id' => 'permission-direct', 'name' => 'fleetops view order']),
    ]));

    return $subject;
}

test('has policies checks names ids model instances arrays collections and guard-specific matches', function () {
    $subject = has_policies_subject();

    expect($subject->hasPolicy('Dispatch Manager'))->toBeTrue()
        ->and($subject->hasPolicy('Missing Policy'))->toBeFalse()
        ->and($subject->hasPolicy('Dispatch Manager', 'sanctum'))->toBeTrue()
        ->and($subject->hasPolicy('Dispatch Manager', 'web'))->toBeFalse()
        ->and($subject->hasPolicy('Web Only', 'web'))->toBeTrue()
        ->and($subject->hasPolicy('Dispatch Manager|Missing Policy'))->toBeTrue()
        ->and($subject->hasPolicy(22))->toBeTrue()
        ->and($subject->hasPolicy(22, 'sanctum'))->toBeTrue()
        ->and($subject->hasPolicy(22, 'web'))->toBeFalse()
        ->and($subject->hasPolicy(has_policies_policy('policy-dispatch', 'Dispatch Manager')))->toBeTrue()
        ->and($subject->hasPolicy(['Missing Policy', 'Billing Manager']))->toBeTrue()
        ->and($subject->hasPolicy(['Missing Policy', 'Other Missing']))->toBeFalse()
        ->and($subject->hasPolicy(new Collection([
            has_policies_policy('policy-dispatch', 'Dispatch Manager'),
        ])))->toBeTrue()
        ->and($subject->hasAnyPolicy('Missing Policy', 'Dispatch Manager'))->toBeTrue();
});

test('has policies all policy and collection helpers expose direct relations consistently', function () {
    $subject = has_policies_subject();

    expect($subject->hasAllPolicies(['Dispatch Manager', 'Billing Manager']))->toBeTrue()
        ->and($subject->hasAllPolicies('Dispatch Manager', 'sanctum'))->toBeTrue()
        ->and($subject->hasAllPolicies('Dispatch Manager', 'web'))->toBeFalse()
        ->and($subject->hasAllPolicies('Dispatch Manager|Billing Manager'))->toBeTrue()
        ->and($subject->hasAllPolicies(['Dispatch Manager', 'Missing Policy']))->toBeFalse()
        ->and($subject->hasAllPolicies(has_policies_policy('policy-dispatch', 'Dispatch Manager')))->toBeTrue()
        ->and($subject->hasAllPolicies(has_policies_policy('policy-missing', 'Missing Policy')))->toBeFalse()
        ->and($subject->hasAllPolicies([has_policies_policy('policy-dispatch', 'Dispatch Manager')]))->toBeTrue()
        ->and($subject->hasAllPolicies(['Web Only'], 'web'))->toBeTrue()
        ->and($subject->getPolicyNames()->all())->toBe(['Dispatch Manager', 'Billing Manager', 'Web Only'])
        ->and($subject->getPolicyDirectPermissions())->toHaveCount(1)
        ->and($subject->getPolicyDirectPermissions()->first()->name)->toBe('fleetops view order');
});

test('has policies parses quoted pipe strings and resolves stored policies by uuid or name', function () {
    $repository = new HasPoliciesPolicyRepositoryFake();
    $subject    = has_policies_subject();
    app()->instance(Policy::class, $repository);

    $uuid = '6b960491-5545-496f-8ec8-d288e86cbbcf';

    expect($subject->convertPipeToArray('ab'))->toBe('ab')
        ->and($subject->convertPipeToArray('"Dispatch Manager|Billing Manager"'))->toBe([
            'Dispatch Manager',
            'Billing Manager',
        ])
        ->and($subject->convertPipeToArray("'Dispatch Manager|Billing Manager'"))->toBe([
            'Dispatch Manager',
            'Billing Manager',
        ])
        ->and($subject->convertPipeToArray('Dispatch Manager|Billing Manager'))->toBe([
            'Dispatch Manager',
            'Billing Manager',
        ])
        ->and($subject->convertPipeToArray('"Dispatch Manager|Billing Manager'))->toBe([
            '"Dispatch Manager',
            'Billing Manager',
        ])
        ->and($subject->convertPipeToArray('a|middle|a'))->toBe([
            'a',
            'middle',
            'a',
        ])
        ->and($subject->resolveStoredPolicy($uuid)->id)->toBe($uuid)
        ->and($subject->resolveStoredPolicy('Dispatch Manager')->name)->toBe('Dispatch Manager')
        ->and($subject->resolveStoredPolicy(has_policies_policy('policy-direct', 'Direct Policy'))->id)->toBe('policy-direct')
        ->and($repository->calls)->toBe([
            ['findByIdentifier', $uuid, 'sanctum'],
            ['findByName', 'Dispatch Manager', 'sanctum'],
        ]);
});

test('has policies scope resolves policy objects identifiers and names before applying query filter', function () {
    $repository = new HasPoliciesPolicyRepositoryFake();
    $subject    = has_policies_subject();
    app()->instance(Policy::class, $repository);

    $query           = new HasPoliciesBuilderFake();
    $query->subQuery = new HasPoliciesBuilderFake();

    $result = $subject->scopePolicy($query, collect([
        has_policies_policy('policy-direct', 'Direct Policy', 'web'),
        '99',
        'Named Policy',
    ]), 'web');
    $scalarQuery           = new HasPoliciesBuilderFake();
    $scalarQuery->subQuery = new HasPoliciesBuilderFake();
    $scalarResult          = $subject->scopePolicy($scalarQuery, 'Solo Policy', 'web');

    expect($result)->toBe($query)
        ->and($scalarResult)->toBe($scalarQuery)
        ->and($query->whereHasCalls)->toBe([
            ['relation' => 'policies', 'operator' => '>=', 'count' => 1],
        ])
        ->and($scalarQuery->subQuery->whereInCalls)->toBe([
            ['column' => 'policies.id', 'values' => ['policy-resolved-name'], 'boolean' => 'and', 'not' => false],
        ])
        ->and($query->subQuery->whereInCalls)->toBe([
            ['column' => 'policies.id', 'values' => ['policy-direct', '99', 'policy-resolved-name'], 'boolean' => 'and', 'not' => false],
        ])
        ->and($repository->calls)->toBe([
            ['findByIdentifier', '99', 'web'],
            ['findByName', 'Named Policy', 'web'],
            ['findByName', 'Solo Policy', 'web'],
        ]);
});

test('has policies aggregates direct and role policy permissions without querying unloaded relations', function () {
    $directPermission = new Permission();
    $directPermission->setRawAttributes(['id' => 'permission-direct', 'name' => 'core direct'], true);
    $rolePermission = new Permission();
    $rolePermission->setRawAttributes(['id' => 'permission-role', 'name' => 'core role'], true);

    $directPolicy = has_policies_policy('policy-direct', 'Direct Policy');
    $directPolicy->setRelation('permissions', collect([$directPermission]));

    $rolePolicy = has_policies_policy('policy-role', 'Role Policy');
    $rolePolicy->setRelation('permissions', collect([$rolePermission]));

    $role = new Role();
    $role->setRawAttributes(['id' => 'role-dispatch', 'name' => 'Dispatch Role', 'guard_name' => 'sanctum'], true);
    $role->setRelation('policies', collect([$rolePolicy]));

    $subject = has_policies_subject();
    $subject->setRelation('policies', collect([$directPolicy]));
    $subject->setRelation('roles', collect([$role]));

    expect($subject->getPermissionsViaPolicies()->pluck('name')->all())->toBe(['core direct'])
        ->and($subject->getPermissionsViaRolePolicies()->pluck('name')->all())->toBe(['core role'])
        ->and($subject->getAllPolicies()->pluck('name')->all())->toBe(['Direct Policy', 'Role Policy'])
        ->and($subject->hasPolicyAssigned($rolePolicy))->toBeTrue()
        ->and($subject->hasPolicyAssigned(has_policies_policy('policy-missing', 'Missing Policy')))->toBeFalse();
});

test('has policies avoids recursive policy permission lookups for policy role and permission models', function () {
    $policy = new HasPoliciesPolicySubject();
    $policy->setRawAttributes(['id' => 'policy-direct', 'name' => 'Direct Policy', 'guard_name' => 'sanctum'], true);
    $role   = new Role();
    $role->setRawAttributes(['id' => 'role-dispatch', 'name' => 'Dispatch Role', 'guard_name' => 'sanctum'], true);
    $permission = new HasPoliciesPermissionSubject();
    $permission->setRawAttributes(['id' => 'permission-direct', 'name' => 'core direct'], true);

    expect($policy->getPermissionsViaPolicies())->toBeEmpty()
        ->and($permission->getPermissionsViaPolicies())->toBeEmpty()
        ->and($policy->getPermissionsViaRolePolicies())->toBeEmpty()
        ->and($role->getPermissionsViaRolePolicies())->toBeEmpty()
        ->and($permission->getPermissionsViaRolePolicies())->toBeEmpty();
});

test('has policies assign remove and sync mutate policy relations through stable side effects', function () {
    $repository = new HasPoliciesPolicyRepositoryFake();
    $subject    = has_policies_subject();
    app()->instance(Policy::class, $repository);

    $subject->exists = true;

    expect($subject->assignPolicy('', null, 'Dispatch Manager', has_policies_policy('policy-direct', 'Direct Policy')))->toBe($subject)
        ->and($subject->syncedPolicies)->toBe([
            ['policies' => ['policy-resolved-name', 'policy-direct'], 'detaching' => false],
        ])
        ->and($subject->loadedRelations)->toBe(['policies'])
        ->and($repository->calls)->toBe([
            ['findByName', 'Dispatch Manager', 'sanctum'],
        ]);

    $subject->removePolicy('Dispatch Manager');

    expect($subject->detachedPolicies[0])->toBeInstanceOf(Policy::class)
        ->and($subject->detachedPolicies[0]->name)->toBe('Dispatch Manager')
        ->and($subject->loadedRelations)->toBe(['policies', 'policies']);

    $subject->syncPolicies('Billing Manager');

    expect($subject->detachedPolicies[1])->toBeNull()
        ->and($subject->syncedPolicies[1])->toBe([
            'policies'  => ['policy-resolved-name'],
            'detaching' => false,
        ]);
});

test('has policies defers assignment for unsaved models and detaches on force delete only', function () {
    $repository = new HasPoliciesPolicyRepositoryFake();
    $subject    = has_policies_subject();
    app()->instance(Policy::class, $repository);

    HasPoliciesSubject::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));

    $subject->exists = false;
    $subject->assignPolicy('Dispatch Manager');

    expect($subject->syncedPolicies)->toBe([]);

    $subject->fireSavedEventForTest();

    expect($subject->syncedPolicies)->toBe([
        ['policies' => ['policy-resolved-name'], 'detaching' => false],
    ])
        ->and($subject->loadedRelations)->toBe(['policies']);

    $softDeleting                        = has_policies_subject();
    $softDeleting->forceDeletingForTest  = false;
    $forceDeleting                       = has_policies_subject();
    $forceDeleting->forceDeletingForTest = true;
    $dispatcher                          = new Illuminate\Events\Dispatcher(app());
    HasPoliciesSubject::flushEventListeners();
    HasPoliciesSubject::setEventDispatcher($dispatcher);
    HasPoliciesSubject::bootHasPolicies();
    $listeners = $dispatcher->getListeners('eloquent.deleting: ' . HasPoliciesSubject::class);

    $listeners[0]('eloquent.deleting: ' . HasPoliciesSubject::class, [$softDeleting]);
    $listeners[0]('eloquent.deleting: ' . HasPoliciesSubject::class, [$forceDeleting]);

    expect($softDeleting->detachedPolicies)->toBe([])
        ->and($forceDeleting->detachedPolicies)->toBe([null]);
});
