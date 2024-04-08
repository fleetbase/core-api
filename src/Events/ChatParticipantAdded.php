<?php

namespace Fleetbase\Events;

use Fleetbase\Http\Resources\ChatParticipant as ChatParticipantResource;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ChatParticipantAdded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $eventId;
    public Carbon $createdAt;
    public ?ChatChannel $chatChannel;
    public ChatParticipant $chatParticipant;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(ChatParticipant $chatParticipant)
    {
        $this->eventId             = uniqid('event_');
        $this->createdAt           = Carbon::now();
        $this->chatChannel         = $chatParticipant->chatChannel;
        $this->chatParticipant     = $chatParticipant;
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'chat.added_participant';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return [
            new Channel('chat.' . $this->chatChannel->uuid),
            new Channel('chat.' . $this->chatChannel->public_id),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'id'          => $this->eventId,
            'event'       => $this->broadcastAs(),
            'created_at'  => $this->createdAt->toDateTimeString(),
            'channel_id'  => $this->chatChannel->public_id,
            'data'        => (new ChatParticipantResource($this->chatParticipant))->toArray(request()),
        ];
    }
}