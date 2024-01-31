<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\SendsWebhooks;
use Fleetbase\Traits\TracksApiCredential;

class Comment extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use SendsWebhooks;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'comments';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'comment';

    /**
     * The custom creation method to use.
     * 
     * @var string
     */
    protected $creationMethod = 'publish';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The relationships to always load along with the model.
     *
     * @var array
     */
    protected $with = ['replies'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['content', 'tags', 'meta'];

    /**
     * The attributes that are guarded.
     *
     * @var array
     */
    protected $guarded = ['subject_uuid', 'subject_type', 'author_uuid', 'parent_comment_uuid'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'             => Json::class,
        'tags'          => Json::class
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function author()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(Comment::class)->without(['replies']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_comment_uuid')->without(['parent']);
    }

    /**
     * Publish a new comment.
     *
     * @param array $attributes
     * @return \Fleetbase\Models\Comment
     */
    public static function publish(array $attributes): Comment
    {
        static::unguard();
        $comment = static::create($attributes);
        static::reguard();

        return $comment;
    }
}
