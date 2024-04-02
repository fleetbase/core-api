<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAttachment extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chat_attachments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'chat_channel_uuid',
        'sender_uuid',
        'file_uuid',
    ];

    /**
     * Get the sender of the attachment.
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_uuid', 'uuid');
    }

    /**
     * Get the chat channel associated with the attachment.
     */
    public function chatChannel()
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_uuid', 'uuid');
    }

    /**
     * Get the file associated with the attachment.
     */
    public function file()
    {
        return $this->belongsTo(File::class, 'file_uuid', 'uuid');
    }

}
