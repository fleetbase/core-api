<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleAvailability extends Model
{
    use HasUuid;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'schedule_availability';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['reason', 'notes'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subject_uuid',
        'subject_type',
        'start_at',
        'end_at',
        'is_available',
        'preference_level',
        'rrule',
        'reason',
        'notes',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_at'         => 'datetime',
        'end_at'           => 'datetime',
        'is_available'     => 'boolean',
        'preference_level' => 'integer',
        'meta'             => Json::class,
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = [
        'subject_type',
        'subject_uuid',
        'is_available',
        'start_at',
        'end_at',
    ];

    /**
     * Get the subject that this availability belongs to (polymorphic).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * Scope a query to only include availability for a specific subject.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $type
     * @param string                                $uuid
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSubject($query, $type, $uuid)
    {
        return $query->where('subject_type', $type)->where('subject_uuid', $uuid);
    }

    /**
     * Scope a query to only include available periods.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope a query to only include unavailable periods.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnavailable($query)
    {
        return $query->where('is_available', false);
    }

    /**
     * Scope a query to only include availability within a time range.
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
}
