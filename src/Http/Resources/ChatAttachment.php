<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class ChatAttachment extends FleetbaseResource
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
            'id'                                                 => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                                               => $this->when(Http::isInternalRequest(), $this->uuid),
            'chat_channel_uuid'                                  => $this->when(Http::isInternalRequest(), $this->chat_channel_uuid, $this->chatChannel ? $this->chatChannel->public_id : null),
            'chat_message_uuid'                                  => $this->when(Http::isInternalRequest(), $this->chat_message_uuid, $this->message ? $this->message->public_id : null),
            'file_uuid'                                          => $this->when(Http::isInternalRequest(), $this->file_uuid, $this->file ? $this->file->public_id : null),
            'url'                                                => $this->file->url,
            'filename'                                           => $this->file->original_filename,
            'content_type'                                       => $this->file->content_type,
            'updated_at'                                         => $this->updated_at,
            'created_at'                                         => $this->created_at,
            'deleted_at'                                         => $this->deleted_at,
        ];
    }
}
