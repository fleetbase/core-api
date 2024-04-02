<?php

namespace Fleetbase\Models;
use Illuminate\Database\Eloquent\Model;

class ChatParticipant extends Model
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = "chat_participants";

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ["chat_channel_uuid", "user_uuid"];

    /**
     * Get the user that is a participant in the chat.
     */
    public function user()
    {
        return $this->belongsTo(User::class, "user_uuid", "uuid");
    }

    /**
     * Get the chat channel that the participant belongs to.
     */
    public function chatChannel()
    {
        return $this->belongsTo(
            ChatChannel::class,
            "chat_channel_uuid",
            "uuid"
        );
    }
}
