<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;
use Fleetbase\Support\Utils;

class ChatMessage extends FleetbaseResource
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
            'id'                                  => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                                => $this->when(Http::isInternalRequest(), $this->uuid),
            'chat_channel_uuid'                   => $this->when(Http::isInternalRequest(), $this->chat_channel_uuid),
            'chat_channel'                        => $this->when(Http::isPublicRequest(), $this->chatChannel->public_id),
            'sender_uuid'                         => $this->when(Http::isInternalRequest(), $this->sender_uuid),
            'content'                             => $this->content,
            'sender'                              => $this->sender ? new ChatParticipant($this->sender) : null,
            'system'                              => method_exists($this->resource, 'isSystem') && $this->resource->isSystem(),
            'meta'                                => data_get($this, 'meta') ?: Utils::createObject(),
            'attachments'                         => ChatAttachment::collection($this->attachments),
            'receipts'                            => ChatReceipt::collection($this->receipts),
            'updated_at'                          => $this->updated_at,
            'created_at'                          => $this->created_at,
            'deleted_at'                          => $this->deleted_at,
        ];
    }
}
