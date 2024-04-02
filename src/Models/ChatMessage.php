<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chat_messages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'chat_channel_uuid',
        'sender_uuid',
        'content',
    ];

    /**
     * Get the sender of the message.
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_uuid', 'uuid');
    }

    /**
     * Get the chat channel associated with the message.
     */
    public function chatChannel()
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_uuid', 'uuid');
    }
}