<?php

namespace Fleetbase\Models;

use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;

class ChatMessage extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SendsWebhooks;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chat_messages';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'chat_message';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['company_uuid', 'chat_channel_uuid', 'sender_uuid', 'content'];

    /**
     * The relationships to always load along with the model.
     *
     * @var array
     */
    protected $with = ['sender', 'attachments', 'receipts'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sender()
    {
        return $this->belongsTo(ChatParticipant::class, 'sender_uuid', 'uuid')->withTrashed();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function chatChannel()
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments()
    {
        return $this->hasMany(ChatAttachment::class, 'chat_message_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function receipts()
    {
        return $this->hasMany(ChatReceipt::class, 'chat_message_uuid', 'uuid')->whereHas('participant');
    }

    /**
     * Notify all participants in the chat channel (excluding the sender) of a new chat message.
     *
     * This method iterates through all chat participants, filters out the sender,
     * and sends a `ChatMessageReceived` notification to each recipient.
     */
    public function notifyParticipants(): void
    {
        $this->loadMissing('chatChannel');
        if (!$this->chatChannel) {
            // Fail silently
            return;
        }

        if (!$this->chatChannel->relationLoaded('participants')) {
            $this->chatChannel->load('participants.user');
        }

        $recipients = $this->chatChannel->participants
            ->filter(function ($participant) {
                return $participant->uuid !== $this->sender_uuid && $participant->user;
            });

        foreach ($recipients as $recipient) {
            $recipient->user->notify(new ChatMessageReceived($this, $recipient));
        }
    }
}
