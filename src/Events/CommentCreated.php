<?php

namespace Fleetbase\Events;

use Fleetbase\Http\Resources\Comment as CommentResource;
use Fleetbase\Models\Comment;
use Fleetbase\Support\ResourceTransformerContext;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Broadcasts `comment.created` when a comment is written on a subject.
 *
 * Every comment goes to the company channel (console). Comments a customer may see (anything but
 * `meta.visibility = internal`) also go to the subject's own channels, e.g. `order.order_x`, so
 * customer and driver apps following the subject receive them; internal notes never do.
 */
class CommentCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $eventId;
    public Carbon $createdAt;
    public Comment $comment;

    /**
     * Create a new event instance.
     */
    public function __construct(Comment $comment)
    {
        $this->eventId   = uniqid('event_');
        $this->createdAt = Carbon::now();
        $this->comment   = $comment;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'comment.created';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->comment->company_uuid) {
            $channels[] = new Channel('company.' . $this->comment->company_uuid);
        }

        if ($this->comment->isVisibleToCustomers()) {
            foreach ($this->subjectChannelNames() as $name) {
                $channels[] = new Channel($name);
            }
        }

        return $channels;
    }

    /**
     * The subject's channels: `{subject type}.{public_id}` and `{subject type}.{uuid}`, the same names
     * the subject's own lifecycle events use (`order.order_x`).
     *
     * @return array<int, string>
     */
    public function subjectChannelNames(): array
    {
        $subjectType = $this->comment->subject_type;
        if (!$subjectType || !$this->comment->subject_uuid) {
            return [];
        }

        $prefix   = Str::snake(class_basename(ltrim($subjectType, '\\')));
        $subject  = $this->resolveSubject();
        $publicId = $subject ? data_get($subject, 'public_id') : null;
        if (!$publicId) {
            return [];
        }

        return [$prefix . '.' . $publicId, $prefix . '.' . $this->comment->subject_uuid];
    }

    /**
     * Resolve the commented subject, ignoring global scopes and failures (the broadcast is best effort).
     */
    protected function resolveSubject(): mixed
    {
        try {
            if ($this->comment->relationLoaded('subject')) {
                return $this->comment->getRelation('subject');
            }

            $subjectType = ltrim($this->comment->subject_type, '\\');
            if (!class_exists($subjectType)) {
                return null;
            }

            return (new $subjectType())->newQuery()->withoutGlobalScopes()->where('uuid', $this->comment->subject_uuid)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $resource = new CommentResource($this->comment);

        return [
            'id'         => $this->eventId,
            'event'      => $this->broadcastAs(),
            'created_at' => $this->createdAt->toDateTimeString(),
            'subject_id' => data_get($this->resolveSubject(), 'public_id'),
            'data'       => $resource->resolveFor(ResourceTransformerContext::BROADCAST, request()),
        ];
    }
}
