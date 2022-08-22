<?php

namespace Fleetbase\Http\Resources\Internal;

use Fleetbase\Http\Resources\FleetbaseResource;

class Organization extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->public_id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'logo_url' => $this->logo_url,
            'backdrop_url' => $this->backdrop_url,
            'slug' => $this->slug,
            'created_at' => $this->created_at,
            'status' => $this->status,
        ];
    }
}
