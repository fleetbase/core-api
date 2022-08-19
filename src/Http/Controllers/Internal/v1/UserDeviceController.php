<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\ApiController;
use Fleetbase\Models\UserDevice;
use Fleetbase\Support\Resp;
use Illuminate\Http\Request;

class UserDeviceController extends ApiController
{
    /**
     * The resource to query
     *
     * @var string
     */
   public $resource = 'user_device';

    /**
     * Register or sync a user device
     *
     * @param  \Illuminate\Http\CreateDriverRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        $data = $request->all();
        $device = UserDevice::firstOrCreate(['token' => $data['token']], $data);

        return Resp::json([ 'status' => 'OK', 'device' => $device->uuid ]);
    }
}
