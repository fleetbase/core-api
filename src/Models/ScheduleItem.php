<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleItem extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use Searchable;
    use SoftDeletes;

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'schedule_item';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'schedule_items';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'public_id',
        'schedule_uuid',
        'assignee_uuid',
        'assignee_type',
        'resource_uuid',
        'resource_type',
        'start_at',
        'end_at',
        'duration',
        'break_start_at',
        'break_end_at',
        'status',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_at'       => 'datetime',
        'end_at'         => 'datetime',
        'break_start_at' => 'datetime',
        'break_end_at'   => 'datetime',
        'duration'       => 'integer',
        'meta'           => Json::class,
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = [
        'schedule_uuid',
        'assignee_type',
        'assignee_uuid',
        'resource_type',
        'resource_uuid',
        'status',
        'start_at',
        'end_at',
    ];

    /**
     * Get the schedule that owns the item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'schedule_uuid');
    }

    /**
     * Get the assignee (polymorphic).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function assignee()
    {
        return $this->morphTo(__FUNCTION__, 'assignee_type', 'assignee_uuid');
    }

    /**
     * Get the resource (polymorphic).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function resource()
    {
        return $this->morphTo(__FUNCTION__, 'resource_type', 'resource_uuid');
    }

    /**
     * Scope a query to only include items for a specific assignee.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $type
     * @param string                                $uuid
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAssignee($query, $type, $uuid)
    {
        return $query->where('assignee_type', $type)->where('assignee_uuid', $uuid);
    }

    /**
     * Scope a query to only include items within a time range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $startAt
     * @param string                                $endAt
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithinTimeRange($query, $startAt, $endAt)
    {
        return $query->where(function ($q) use ($startAt, $endAt) {
            $q->whereBetween('start_at', [$startAt, $endAt])
              ->orWhereBetween('end_at', [$startAt, $endAt])
              ->orWhere(function ($q) use ($startAt, $endAt) {
                  $q->where('start_at', '<=', $startAt)
                    ->where('end_at', '>=', $endAt);
              });
        });
    }

    /**
     * Scope a query to only include upcoming items.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_at', '>', now())->orderBy('start_at', 'asc');
    }

    /**
     * Scope a query to only include items by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|array                          $status
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, $status)
    {
        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }

        return $query->where('status', $status);
    }

    /**
     * Calculate the duration in minutes if not set.
     *
     * @return int
     */
    public function calculateDuration()
    {
        if ($this->start_at && $this->end_at) {
            return $this->start_at->diffInMinutes($this->end_at);
        }

        return 0;
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            if (!$item->duration && $item->start_at && $item->end_at) {
                $item->duration = $item->calculateDuration();
            }
        });
    }
}
