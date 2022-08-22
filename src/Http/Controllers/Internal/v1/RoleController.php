<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;

class RoleController extends FleetbaseController
{
    /**
     * The resource to query
     *
     * @var string
     */
   public $resource = 'role';

    /**
     * Creates a record by an identifier with request payload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        return $this->model::createRecordFromRequest($request, null, function ($request, &$role) {
            if ($request->isArray('role.permissions')) {
                $permissions = collect($request->input('role.permissions'))->map(function($permission) {
                    return Utils::get($permission, 'name');
                })->toArray();

                $role->syncPermissions($permissions);
            }
        });
    }

    /**
     * Updates a record by an identifier with request payload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateRecord(Request $request)
    {
        return $this->model::updateRecordFromRequest($request, function ($request, &$role) {
            if ($request->isArray('role.permissions')) {
                $permissions = collect($request->input('role.permissions'))->map(function($permission) {
                    return Utils::get($permission, 'name');
                })->toArray();

                $role->syncPermissions($permissions);
            }
        });
    }
}
