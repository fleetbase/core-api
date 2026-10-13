<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Events\CommentCreated;
use Fleetbase\Support\Http;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Comment extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;

    /**
     * Visible to the company's own users only (console notes).
     */
    public const VISIBILITY_INTERNAL = 'internal';

    /**
     * Visible to the customer as well (customer facing APIs and channels).
     */
    public const VISIBILITY_CUSTOMER = 'customer';

    /**
     * The values `meta.visibility` accepts.
     */
    public const VISIBILITIES = [self::VISIBILITY_INTERNAL, self::VISIBILITY_CUSTOMER];

    /**
     * Subject types (snake cased class basenames) whose comments default to `internal` when they are
     * written through the internal (console) API, and to `customer` otherwise. Comments on any other
     * subject keep no visibility unless one is given, which is treated as visible.
     *
     * @var array<int, string>
     */
    public static array $visibilityAwareSubjectTypes = ['order'];

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
     * Default the comment visibility and broadcast new comments.
     */
    protected static function booted(): void
    {
        static::creating(function (Comment $comment) {
            $comment->applyDefaultVisibility();
        });

        static::created(function (Comment $comment) {
            // A broadcast failure (socket server down, missing broadcaster) must never fail the comment.
            try {
                Event::dispatch(new CommentCreated($comment));
            } catch (\Throwable $e) {
                Log::warning('Unable to broadcast comment.created', ['comment' => $comment->public_id, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Only comments a customer may see: everything except `internal` ones. Comments with no
     * visibility (written before visibility existed, or on subjects without a default) stay visible.
     */
    public function scopeVisibleToCustomers(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('meta')
                ->orWhereNull('meta->visibility')
                ->orWhere('meta->visibility', '!=', self::VISIBILITY_INTERNAL);
        });
    }

    /**
     * Only `internal` comments.
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('meta->visibility', self::VISIBILITY_INTERNAL);
    }

    /**
     * The effective visibility: `internal` when marked so, `customer` otherwise.
     */
    public function getVisibility(): string
    {
        return data_get($this->meta, 'visibility') === self::VISIBILITY_INTERNAL ? self::VISIBILITY_INTERNAL : self::VISIBILITY_CUSTOMER;
    }

    public function isInternal(): bool
    {
        return $this->getVisibility() === self::VISIBILITY_INTERNAL;
    }

    public function isVisibleToCustomers(): bool
    {
        return !$this->isInternal();
    }

    /**
     * Whether a subject type (class name or short type) defaults its comments' visibility.
     */
    public static function isVisibilityAwareSubjectType(?string $subjectType): bool
    {
        if (!$subjectType) {
            return false;
        }

        return in_array(Str::snake(class_basename(ltrim($subjectType, '\\'))), static::$visibilityAwareSubjectTypes, true);
    }

    /**
     * Fills `meta.visibility` when it is missing or not a known value: replies follow their parent,
     * comments on visibility aware subjects (orders) are `internal` from the console and `customer`
     * from everywhere else, and other comments are left without one (visible).
     */
    public function applyDefaultVisibility(): void
    {
        $meta       = is_array($this->meta) ? $this->meta : [];
        $visibility = $meta['visibility'] ?? null;

        if (in_array($visibility, self::VISIBILITIES, true)) {
            return;
        }

        $default = null;
        if ($this->parent_comment_uuid) {
            $parentMeta = static::query()
                ->withoutGlobalScopes()
                ->where('uuid', $this->parent_comment_uuid)
                ->value('meta');
            $default = data_get(Json::decode($parentMeta), 'visibility');
        }

        if (!in_array($default, self::VISIBILITIES, true)) {
            $default = static::isVisibilityAwareSubjectType($this->subject_type)
                ? (Http::isInternalRequest() ? self::VISIBILITY_INTERNAL : self::VISIBILITY_CUSTOMER)
                : null;
        }

        if ($default === null && $visibility === null) {
            // Nothing to set: leave meta exactly as given (a null column stays null).
            return;
        }

        unset($meta['visibility']);
        if ($default !== null) {
            $meta['visibility'] = $default;
        }

        $this->meta = $meta;
    }

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

        // `visibility` is accepted as a shorthand for `meta.visibility`, it is not a column.
        if (array_key_exists('visibility', $attributes)) {
            $visibility = $attributes['visibility'];
            unset($attributes['visibility']);

            if (in_array($visibility, self::VISIBILITIES, true)) {
                $meta = $attributes['meta'] ?? [];
                $meta = is_string($meta) ? (json_decode($meta, true) ?: []) : (array) $meta;

                $meta['visibility'] = $visibility;
                $attributes['meta'] = $meta;
            }
        }

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
