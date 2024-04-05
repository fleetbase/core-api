<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\SendsWebhooks;
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
        return $this->hasMany(ChatAttachment::class, 'chat_channel_uuid', 'uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function presences()
    {
        return $this->hasMany(ChatPresence::class, 'chat_channel_uuid', 'uuid');
    }
}
