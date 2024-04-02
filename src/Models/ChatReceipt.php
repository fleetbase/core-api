<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model;

class ChatReceipt extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chat_receipts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'chat_message_uuid',
        'user_uuid',
        'read_at',
    ];

    /**
     * Get the user who read the message.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the chat message associated with the receipt.
     */
    public function chatMessage()
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_uuid', 'uuid');
    }
}