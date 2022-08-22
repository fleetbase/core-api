<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Exports\GroupExport;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\GroupUser;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class GroupController extends FleetbaseController
{
    /**
     * The resource to query
     *
     * @var string
     */
   public $resource = 'group';

    /**
     * Creates a record with request payload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function createRecord(Request $request)
    {
        return $this->model::createRecordFromRequest($request, null, function (&$request, &$group) {
            $users = $request->input('group.users');

            foreach ($users as $user) {
                GroupUser::firstOrCreate([
                    'group_uuid' => $group->uuid,
                    'user_uuid' => Utils::get($user, 'uuid'),
                ]);
            }

            $group->load(['users']);
        });
    }


    /**
     * Export the groups to excel or csv
     *
     * @param  \Illuminate\Http\Request  $query
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format = $request->input('format', 'xlsx');
        $fileName = trim(Str::slug('groups-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new GroupExport(), $fileName);
    }
}
