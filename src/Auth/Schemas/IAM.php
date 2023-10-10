<?php

namespace Fleetbase\Auth\Schemas;

class IAM
{
    /**
     * The permission schema Name.
     */
    public string $name = 'iam';

    /**
     * The permission schema Polict Name.
     */
    public string $policyName = 'IAM';

    /**
     * Guards these permissions should apply to.
     */
    public array $guards = ['web'];

    /**
     * Direct permissions for the schema.
     */
    public array $permissions = ['change-password'];

    /**
     * The permission schema resources.
     */
    public array $resources = [
        [
            'name'    => 'group',
            'actions' => ['export'],
        ],
        [
            'name'    => 'user',
            'actions' => ['deactivate', 'export'],
        ],
        [
            'name'    => 'role',
            'actions' => ['export'],
        ],
        [
            'name'    => 'policy',
            'actions' => [],
        ],
    ];
}
