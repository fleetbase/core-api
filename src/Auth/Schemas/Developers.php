<?php

namespace Fleetbase\Auth\Schemas;

class Developers
{
    /**
     * The permission schema Name.
     */
    public string $name = 'developers';

    /**
     * The permission schema Polict Name.
     */
    public string $policyName = 'Developers';

    /**
     * Guards these permissions should apply to.
     */
    public array $guards = ['sanctum'];

    /**
     * The permission schema resources.
     */
    public array $resources = [
        [
            'name'    => 'api-key',
            'actions' => ['roll', 'export'],
        ],
        [
            'name'    => 'webhook',
            'actions' => ['enable', 'disable'],
        ],
        [
            'name'           => 'socket',
            'actions'        => [],
            'remove_actions' => ['create', 'update', 'delete'],
        ],
        [
            'name'           => 'log',
            'actions'        => ['export'],
            'remove_actions' => ['create', 'update', 'delete'],
        ],
        [
            'name'           => 'event',
            'actions'        => ['export'],
            'remove_actions' => ['create', 'update', 'delete'],
        ],
    ];
}
