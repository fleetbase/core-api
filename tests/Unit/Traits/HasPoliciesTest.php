<?php

use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Traits\HasPolicies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class HasPoliciesSubject extends Model
{
    use HasPolicies;

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
        ->and($subject->resolveStoredPolicy($uuid)->id)->toBe($uuid)
        ->and($subject->resolveStoredPolicy('Dispatch Manager')->name)->toBe('Dispatch Manager')
        ->and($subject->resolveStoredPolicy(has_policies_policy('policy-direct', 'Direct Policy'))->id)->toBe('policy-direct')
        ->and($repository->calls)->toBe([
            ['findByIdentifier', $uuid, 'sanctum'],
            ['findByName', 'Dispatch Manager', 'sanctum'],
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
