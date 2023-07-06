<?php

namespace Fleetbase\Auth\Schemas;

class IAM
{
    /**
     * The permission schema Name.
     *
     * @var string
     */
    public string $name = 'iam';

    /**
     * The permission schema Polict Name.
     *
     * @var string
     */
    public string $policyName = 'IAM';

    /**
     * Guards these permissions should apply to.
     *
     * @var array
     */
    public array $guards = ['web'];

    /**
     * Direct permissions for the schema.
     *
     * @var array
     */
    public array $permissions = ['change-password'];

    /**
     * The permission schema resources.
     *
     * @var array
     */
    public array $resources = [
        [
            'name' => 'group',
            'actions' => ['export']
        ],
        [
            'name' => 'user',
            'actions' => ['deactivate', 'export']
        ],
        [
            'name' => 'role',
            'actions' => ['export']
        ],
        [
            'name' => 'policy',
            'actions' => []
        ]
    ];
}
