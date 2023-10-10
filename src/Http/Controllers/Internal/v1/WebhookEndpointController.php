<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;

class WebhookEndpointController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'webhook_endpoint';

    /**
     * Get all webhook events applicable.
     *
     * @return \Illuminate\Http\Response
     */
    public static function events()
    {
        return response()->json(config('api.events'));
    }

    /**
     * Get all webhook versions applicable.
     *
     * @return \Illuminate\Http\Response
     */
    public static function versions()
    {
        return response()->json(config('api.versions'));
    }
}
