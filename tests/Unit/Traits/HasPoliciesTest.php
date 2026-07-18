<?php

use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
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
}

function has_policies_policy(string|int $id, string $name, string $guard = 'sanctum'): Policy
{
    $policy = new Policy();
    $policy->setRawAttributes([
        'id' => $id,
        'name' => $name,
        'guard_name' => $guard,
    ], true);

    return $policy;
}

function has_policies_subject(): HasPoliciesSubject
{
    bind_test_container([
        'auth.defaults.guard' => 'sanctum',
        'auth.guards.sanctum' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'auth.providers.users' => [
            'driver' => 'eloquent',
            'model' => HasPoliciesSubject::class,
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
        ->and($subject->hasAllPolicies('Dispatch Manager|Billing Manager'))->toBeTrue()
        ->and($subject->hasAllPolicies(['Dispatch Manager', 'Missing Policy']))->toBeFalse()
        ->and($subject->hasAllPolicies([has_policies_policy('policy-dispatch', 'Dispatch Manager')]))->toBeTrue()
        ->and($subject->getPolicyNames()->all())->toBe(['Dispatch Manager', 'Billing Manager', 'Web Only'])
        ->and($subject->getPolicyDirectPermissions())->toHaveCount(1)
        ->and($subject->getPolicyDirectPermissions()->first()->name)->toBe('fleetops view order');
});
