<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model;

class ChatChannel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chat_channels';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'uuid';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'name',
    ];

    /**
     * Get the participants of the chat channel.
     */
    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'chat_channel_uuid', 'uuid');
    }
}
