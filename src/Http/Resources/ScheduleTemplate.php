<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;

class ScheduleTemplate extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return parent::toArray($request);
    }
}
