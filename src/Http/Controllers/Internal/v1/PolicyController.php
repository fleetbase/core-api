<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\Policy;
use Fleetbase\Support\Resp;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;

class PolicyController extends FleetbaseController
{
    /**
     * The resource to query
     *
     * @var string
     */
   public $resource = 'policy';

    /**
     * Creates a record by an identifier with request payload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        return $this->model::createRecordFromRequest($request, null, function ($request, &$policy) {
            if ($request->isArray('policy.permissions')) {
                $permissions = collect($request->input('policy.permissions'))->map(function($permission) {
                    return Utils::get($permission, 'name');
                })->toArray();

                $policy->syncPermissions($permissions);
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
        return $this->model::updateRecordFromRequest($request, function ($request, &$policy) {
            if ($request->isArray('policy.permissions')) {
                $permissions = collect($request->input('policy.permissions'))->map(function($permission) {
                    return Utils::get($permission, 'name');
                })->toArray();

                $policy->syncPermissions($permissions);
            }
        });
    }

    /**
     * Deletes a policy record
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function deleteRecord(Request $request)
    {
        $id = $request->segment(4);
        $policy = Policy::find($id);

        if (!$policy) {
            return response()->error('Unable to find policy for deletion.');
        }

        $policy->delete();

        return response()->json(['status' => 'OK', 'message' => 'Policy deleted.']);
    }
}
