<?php

namespace Fleetbase\Auth\Schemas;

class Developers
{
    /**
     * The permission schema Name.
     *
     * @var string
     */
    public string $name = 'developers';

    /**
     * The permission schema Polict Name.
     *
     * @var string
     */
    public string $policyName = 'Developers';

    /**
     * Guards these permissions should apply to.
     *
     * @var array
     */
    public array $guards = ['web'];

    /**
     * The permission schema resources.
     *
     * @var array
     */
    public array $resources = [
        [
            'name' => 'api-key',
            'actions' => ['roll', 'export']
        ],
        [
            'name' => 'webhook',
            'actions' => []
        ],
        [
            'name' => 'socket',
            'actions' => [],
            'remove_actions' => ['create', 'update', 'delete']
        ],
        [
            'name' => 'log',
            'actions' => [],
            'remove_actions' => ['create', 'update', 'delete', 'export']
        ],
        [
            'name' => 'event',
            'actions' => [],
            'remove_actions' => ['create', 'update', 'delete']
        ]
    ];
}
