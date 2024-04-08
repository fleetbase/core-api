<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class ChatParticipant extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'                               => $this->when(Http::isInternalRequest(), $this->id),
            'uuid'                             => $this->when(Http::isInternalRequest(), $this->uuid),
            'chat_channel_uuid'                => $this->when(Http::isInternalRequest(), $this->chat_channel_uuid),
            'user_uuid'                        => $this->when(Http::isInternalRequest(), $this->user_uuid),
            'name'                             => $this->user->name,
            'username'                         => $this->user->username,
            'email'                            => $this->user->email,
            'phone'                            => $this->user->phone,
            'avatar_url'                       => $this->user->avatar_url,
            'updated_at'                       => $this->updated_at,
            'created_at'                       => $this->created_at,
            'deleted_at'                       => $this->deleted_at,
        ];
    }
}