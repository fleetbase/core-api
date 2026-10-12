<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

class Comment extends Model
{
    use HasUuid;
    use HasPublicId;
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
    protected $with = ['author', 'replies'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['content', 'tags', 'meta', 'created_at', 'updated_at'];

    /**
     * The attributes that are guarded.
     *
     * @var array
     */
    protected $guarded = ['company_uuid', 'subject_uuid', 'subject_type', 'author_uuid', 'parent_comment_uuid'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'meta'             => Json::class,
        'tags'             => Json::class,
        'subject_type'     => PolymorphicType::class,
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
    public function company()
    {
        return $this->belongsTo(Company::class);
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
        return $this->hasMany(Comment::class, 'parent_comment_uuid')->latest()->without(['parent']);
    }

    /**
     * Publish a new comment.
     */
    public static function publish(array $attributes): Comment
    {
        // If replies or parent is set somehow unset it
        unset($attributes['replies'], $attributes['parent']);

        // set author if unset
        if (empty($attributes['author_uuid'])) {
            $attributes['author_uuid'] = session('user');
        }

        // set company if unset
        if (empty($attributes['company_uuid'])) {
            $attributes['company_uuid'] = session('company');
        }

        // set timestamps manually
        // not sure why this is needed but no time
        $attributes['created_at'] = now();
        $attributes['updated_at'] = now();

        // Unguard only for this insert: `unguarded()` restores the guard even when the insert
        // throws. A bare unguard() left every model in a long-lived (Octane) worker unguarded
        // after a failed insert, so request params became where clauses and stray payload keys
        // became insert columns on unrelated models.
        return static::unguarded(fn () => static::create($attributes));
    }
}
