<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;

class CreatePermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetbase:create-permissions {--reset}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or reset all permissions, policies and roles';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $reset = $this->option('reset');

        if ($reset) {
            Schema::disableForeignKeyConstraints();
            Permission::truncate();
            Policy::truncate();
            DB::table('model_has_permissions')->truncate();
            DB::table('model_has_roles')->truncate();
            DB::table('model_has_policies')->truncate();
        }

        $actions = ['create', 'update', 'delete', 'view', 'list', 'see'];
        $schemas = Utils::getAuthSchemas();

        foreach ($schemas as $schema) {
            $service     = $schema->name;
            $resources   = $schema->resources ?? [];
            $permissions = $schema->permissions ?? null;
            $guard       = 'sanctum';

            // Add visibility permission for service
            $visibilityPermission = Permission::firstOrCreate(
                [
                    'name'       => $service . ' see extension',
                    'guard_name' => $guard,
                ]
            );

            // Output message for permissions creation
            $this->info('Created permission: ' . $visibilityPermission->name);

            // First create a wilcard permission for the entire schema
            $administratorPolicy = Policy::firstOrCreate(
                [
                    'name'        => 'AdministratorAccess',
                    'guard_name'  => $guard,
                    'description' => 'Provides full access to Fleetbase extensions and resources.',
                ]
            );

            // Give visibility to service
            $administratorPolicy->givePermissionTo($visibilityPermission);

            // Create wildcard permission for service
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

            // Add wildcard permissions to administrator access policy
            try {
                $administratorPolicy->givePermissionTo($permission);
            } catch (GuardDoesNotMatch $e) {
                return $this->error($e->getMessage());
            }

            // Output message for permissions creation
            $this->info('Created permission: ' . $permission->name);

            // Check if schema has direct permissions to add
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

                    // Add wildcard permissions to administrator access policy
                    try {
                        $administratorPolicy->givePermissionTo($permission);
                    } catch (GuardDoesNotMatch $e) {
                        return $this->error($e->getMessage());
                    }

                    // output message for permissions creation
                    $this->info('Created permission: ' . $permission->name);
                }
            }

            // Create a resource policy for full access
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

            // Give visibility to service
            $fullAccessPolicy->givePermissionTo($visibilityPermission);

            // Create a resource policy for read-only access
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

            // Give visibility to service
            $readOnlyPolicy->givePermissionTo($visibilityPermission);

            // Create wilcard permission for service and all resources
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

                // Add wildcard permissions to full access policy
                try {
                    $fullAccessPolicy->givePermissionTo($permission);
                } catch (GuardDoesNotMatch $e) {
                    return $this->error($e->getMessage());
                }

                // Output message for permissions creation
                $this->info('Created permission: ' . $permission->name);

                // Create action permissions
                $resourceActions = array_merge($actions, data_get($resource, 'actions', []));

                // if some actions should be excluded
                if (is_array(data_get($resource, 'remove_actions', null))) {
                    foreach (data_get($resource, 'remove_actions') as $remove) {
                        if (($key = array_search($remove, $actions)) !== false) {
                            unset($resourceActions[$key]);
                        }
                    }
                }

                // Create action permissions
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

                    // Add the permission to the read only policy
                    if ($action === 'view' || $action === 'list') {
                        try {
                            $readOnlyPolicy->givePermissionTo($permission);
                        } catch (GuardDoesNotMatch $e) {
                            return $this->error($e->getMessage());
                        }
                    }

                    // output message for permissions creation
                    $this->info('Created permission: ' . $permission->name);
                }
            }
        }

        if ($reset) {
            Schema::enableForeignKeyConstraints();
        }
    }
}
