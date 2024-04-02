<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model;

class ChatPresence extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chat_presences';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'chat_channel_uuid',
        'user_uuid',
        'last_seen_at',
        'is_online',
    ];

    /**
     * Get the user associated with the presence.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * Get the chat channel associated with the presence.
     */
    public function chatChannel()
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_uuid', 'uuid');
    }
}