<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Http\Resources\ChatAttachment as ChatAttachmentResource;
use Fleetbase\Http\Resources\ChatLog as ChatLogResource;
use Fleetbase\Http\Resources\ChatMessage as ChatMessageResource;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
use Illuminate\Support\Collection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class ChatChannel extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasSlug;
    use HasMetaAttributes;
    use Searchable;
    use SendsWebhooks;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chat_channels';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'chat';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'created_by_uuid',
        'name',
        'slug',
        'meta',
    ];

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name'];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = ['title', 'last_message'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'          => Json::class,
    ];

    /**
     * @var SlugOptions
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /** on boot make creator a participant */
    public static function boot()
    {
        parent::boot();
        static::created(function ($model) {
            ChatParticipant::create([
                'company_uuid'      => $model->company_uuid,
                'user_uuid'         => $model->created_by_uuid,
                'chat_channel_uuid' => $model->uuid,
            ]);
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lastMessage()
    {
        return $this->hasOne(ChatMessage::class, 'chat_channel_uuid', 'uuid')->latest();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'chat_channel_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'chat_channel_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments()
    {
        return $this->hasMany(ChatAttachment::class, 'chat_channel_uuid', 'uuid')->whereNull('chat_message_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function logs()
    {
        return $this->hasMany(ChatLog::class, 'chat_channel_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function presences()
    {
        return $this->hasMany(ChatPresence::class, 'chat_channel_uuid', 'uuid');
    }

    /**
     * Accessor to get the 'last_message' attribute for the chat.
     *
     * This method retrieves the last message of the chat. It uses the 'lastMessage'
     * relationship, which should be defined to return the latest message in the chat.
     *
     * @return ChatMessage|null the content of the last message in the chat, or null if no messages are available
     */
    public function getLastMessageAttribute(): ?ChatMessage
    {
        return $this->lastMessage()->first();
    }

    /**
     * Accessor to get the 'title' attribute for the chat.
     *
     * This method returns the name of the chat if it's set. If the name is not set, it constructs
     * the title by concatenating the names of the chat's participants, up to a specified limit to avoid performance issues
     * with large numbers of participants. In cases where there are no participant names or the chat name is empty,
     * a default title ('Untitled Chat') is provided.
     *
     * @return string the title of the chat based on its name or its participants' names
     */
    public function getTitleAttribute(): string
    {
        $title = $this->name;
        if (empty($title)) {
            $participants = $this->relationLoaded('participants') ?
            $this->participants :
            $this->participants()->get();

            $participantNames = $participants->map(function ($chatParticipant) {
                return $chatParticipant->name;
            })->filter()->slice(0, 4);

            $title = $participantNames->implode(', ');

            if (empty($title)) {
                $title = 'Untitled Chat';
            }
        }

        return $title;
    }

    /**
     * Accessor to get the 'feed' attribute for the chat.
     *
     * This method aggregates and returns a feed of different types of chat-related
     * data, including messages, attachments, and logs, sorted in chronological order.
     * It provides a unified view of the chat channel activity.
     *
     * @return Collection the aggregated feed of chat activities
     */
    public function getFeedAttribute(): Collection
    {
        $messages = $this->messages()->get()->map(function ($message) {
            return ['type' => 'message', 'data' => $message, 'created_at' => $message->created_at];
        });

        $attachments = $this->attachments()->get()->map(function ($attachment) {
            return ['type' => 'attachment', 'data' => $attachment, 'created_at' => $attachment->created_at];
        });

        $logs = $this->logs()->get()->map(function ($log) {
            return ['type' => 'log', 'data' => $log, 'created_at' => $log->created_at];
        });

        $feed = collect([...$messages, ...$attachments, ...$logs])->sortBy('created_at');
        return $feed;
    }

    /**
     * Accessor to get the 'resource_feed' attribute for the chat.
     *
     * This method aggregates messages, attachments, and logs related to the chat,
     * transforms them into their respective HTTP resources, and returns them as a unified feed.
     * Each item in the feed is an array containing the type (message, attachment, log),
     * the corresponding HTTP resource, and the creation timestamp. The feed is sorted in
     * descending order of creation time, ensuring the most recent activities are listed first.
     * This method is particularly useful for generating standardized API responses
     * that encapsulate the diverse activities within a chat channel.
     *
     * @return Collection the aggregated and resource-formatted feed of chat activities
     */
    public function getResourceFeedAttribute(): Collection
    {
        $messages = $this->messages()->get()->map(function ($message) {
            return ['type' => 'message', 'data' => new ChatMessageResource($message), 'created_at' => $message->created_at];
        });

        $attachments = $this->attachments()->get()->map(function ($attachment) {
            return ['type' => 'attachment', 'data' => new ChatAttachmentResource($attachment), 'created_at' => $attachment->created_at];
        });

        $logs = $this->logs()->get()->map(function ($log) {
            return ['type' => 'log', 'data' => new ChatLogResource($log), 'created_at' => $log->created_at];
        });

        $feed = collect([...$messages, ...$attachments, ...$logs])->sortBy('created_at');
        return $feed;
    }
}
