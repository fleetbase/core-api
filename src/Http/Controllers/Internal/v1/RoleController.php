<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\Permission;
use Illuminate\Http\Request;

class RoleController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'role';

    /**
     * Creates a record by an identifier with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        try {
            $record = $this->model->createRecordFromRequest($request, null, function ($request, &$role) {
                if ($request->isArray('role.permissions')) {
                    $permissions = Permission::whereIn('id', $request->array('role.permissions'))->get();
                    $role->syncPermissions($permissions);
                }
            });

            return ['role' => new $this->resource($record)];
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    /**
     * Updates a record by an identifier with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateRecord(Request $request, string $id)
    {
        try {
            $record = $this->model->updateRecordFromRequest($request, $id, function ($request, &$role) {
                if ($request->isArray('role.permissions')) {
                    $permissions = Permission::whereIn('id', $request->array('role.permissions'))->get();
                    $role->syncPermissions($permissions);
                }
            });

            return ['role' => new $this->resource($record)];
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }
}
