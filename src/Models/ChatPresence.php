<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;

class ChatPresence extends Model
{
    use HasUuid;
    use SendsWebhooks;

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
        'company_uuid',
        'chat_channel_uuid',
        'user_uuid',
        'last_seen_at',
        'is_online',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function chatChannel()
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_uuid', 'uuid');
    }
}
