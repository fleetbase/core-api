<?php

namespace Fleetbase\Seeders;

use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Schema::disableForeignKeyConstraints();
        Permission::truncate();
        Policy::truncate();
        DB::table('model_has_permissions')->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('model_has_policies')->truncate();

        $actions = ['create', 'update', 'delete', 'view', 'list'];
        $schemas = Utils::getAuthSchemas();

        foreach ($schemas as $schema) {
            $service     = $schema->name;
            $resources   = $schema->resources ?? [];
            $permissions = $schema->permissions ?? null;
            $guard       = 'sanctum';

            // first create a wilcard permission for the entire schema
            $administratorPolicy = Policy::firstOrCreate(
                [
                    'name'        => 'AdministratorAccess',
                    'guard_name'  => $guard,
                    'description' => 'Provides full access to Fleetbase extensions and resources.',
                ]
            );

            $permission = Permission::firstOrCreate(
                [
                    'name'       => $service . ' *',
                    'guard_name' => $guard,
                ],
                [
                    'name'       => $service . ' *',
                    'guard_name' => $guard,
                ]
            );

            // add wildcard permissions to administrator access policy
            try {
                $administratorPolicy->givePermissionTo($permission);
            } catch (\Spatie\Permission\Exceptions\GuardDoesNotMatch $e) {
                dd($e->getMessage());
            }

            // output message for permissions creation
            // $this->output('Created (' . $guard . ') permission: ' . $permission->name);

            // check if schema has direct permissions to add
            if (is_array($permissions)) {
                foreach ($permissions as $action) {
                    $permission = Permission::firstOrCreate(
                        [
                            'name'       => $service . ' ' . $action,
                            'guard_name' => $guard,
                        ],
                        [
                            'name'       => $service . ' ' . $action,
                            'guard_name' => $guard,
                        ]
                    );

                    // add wildcard permissions to administrator access policy
                    try {
                        $administratorPolicy->givePermissionTo($permission);
                    } catch (\Spatie\Permission\Exceptions\GuardDoesNotMatch $e) {
                        dd($e->getMessage());
                    }

                    // output message for permissions creation
                    // $this->output('Created (' . $guard . ') permission: ' . $permission->name);
                }
            }

            // create a resource policy for full access
            $fullAccessPolicy = Policy::firstOrCreate(
                [
                    'name'       => Str::studly(data_get($schema, 'policyName')) . 'FullAccess',
                    'guard_name' => $guard,
                ],
                [
                    'name'        => Str::studly(data_get($schema, 'policyName')) . 'FullAccess',
                    'description' => 'Provides full access to ' . Str::studly(data_get($schema, 'policyName')) . '.',
                    'guard_name'  => $guard,
                ]
            );

            // create a resource policy for read-only access
            $readOnlyPolicy = Policy::firstOrCreate(
                [
                    'name'       => Str::studly(data_get($schema, 'policyName')) . 'ReadOnly',
                    'guard_name' => $guard,
                ],
                [
                    'name'        => Str::studly(data_get($schema, 'policyName')) . 'ReadOnly',
                    'description' => 'Provides read-only access to ' . Str::studly(data_get($schema, 'policyName')) . '.',
                    'guard_name'  => $guard,
                ]
            );

            // create wilcard permission for service and all resources
            foreach ($resources as $resource) {
                $permission = Permission::firstOrCreate(
                    [
                        'name'       => $service . ' * ' . data_get($resource, 'name'),
                        'guard_name' => $guard,
                    ],
                    [
                        'name'       => $service . ' * ' . data_get($resource, 'name'),
                        'guard_name' => $guard,
                    ]
                );

                // add wildcard permissions to full access policy
                try {
                    $fullAccessPolicy->givePermissionTo($permission);
                } catch (\Spatie\Permission\Exceptions\GuardDoesNotMatch $e) {
                    dd($e->getMessage());
                }

                // output message for permissions creation
                // $this->output('Created (' . $guard . ') permission: ' . $permission->name);

                // create action permissions
                $resourceActions = array_merge($actions, data_get($resource, 'actions', []));

                // if some actions should be excluded
                if (is_array(data_get($resource, 'remove_actions', null))) {
                    foreach (data_get($resource, 'remove_actions') as $remove) {
                        if (($key = array_search($remove, $actions)) !== false) {
                            unset($resourceActions[$key]);
                        }
                    }
                }

                // create action permissions
                foreach ($resourceActions as $action) {
                    $permission = Permission::firstOrCreate(
                        [
                            'name'       => $service . ' ' . $action . ' ' . data_get($resource, 'name'),
                            'guard_name' => $guard,
                        ],
                        [
                            'name'       => $service . ' ' . $action . ' ' . data_get($resource, 'name'),
                            'guard_name' => $guard,
                        ]
                    );

                    // add the permission to the read only policy
                    if ($action === 'view' || $action === 'list') {
                        try {
                            $readOnlyPolicy->givePermissionTo($permission);
                        } catch (\Spatie\Permission\Exceptions\GuardDoesNotMatch $e) {
                            dd($e->getMessage());
                        }
                    }

                    // output message for permissions creation
                    // $this->output('Created (' . $guard . ') permission: ' . $permission->name);
                }
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Simple echo to output to CLI.
     */
    public function output(string $line = ''): void
    {
        if (app()->runningInConsole()) {
            echo $line . PHP_EOL;
        }
    }
}
