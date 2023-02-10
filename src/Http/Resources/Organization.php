<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Illuminate\Support\Arr;

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
        $organization = [
            'id' => $this->public_id,
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

        if (Http::isInternalRequest()) {
            $organization = Arr::insertAfterKey($organization, ['uuid' => $this->uuid, 'public_id' => $this->public_id], 'id');
        }

        return $organization;
    }
}
