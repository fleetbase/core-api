<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class User extends FleetbaseResource
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
        return [
            'id'                                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'company_uuid'                                  => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'public_id'                                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'company'                                       => $this->when(Http::isPublicRequest(), $this->company->public_id),
            'name'                                          => $this->name,
            'username'                                      => $this->username,
            'email'                                         => $this->email,
            'phone'                                         => $this->phone,
            'country'                                       => $this->country,
            'timezone'                                      => $this->timezone,
            'avatar_url'                                    => $this->avatar_url,
            'meta'                                          => $this->meta,
            'type'                                          => $this->type,
            'types'                                         => $this->when(Http::isInternalRequest(), $this->types ?? []),
            'company_name'                                  => $this->when(Http::isInternalRequest(), $this->company_name),
            'session_status'                                => $this->when(Http::isInternalRequest(), $this->session_status),
            'is_admin'                                      => $this->when(Http::isInternalRequest(), $this->is_admin),
            'is_online'                                     => $this->is_online,
            'last_seen_at'                                  => $this->last_seen_at,
            'last_login'                                    => $this->last_login,
            'updated_at'                                    => $this->updated_at,
            'created_at'                                    => $this->created_at,
        ];
    }
}
