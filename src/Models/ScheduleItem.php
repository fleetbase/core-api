<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a concrete, materialized shift instance on a specific date.
 *
 * ScheduleItem records are either:
 *   (a) Generated automatically by the ScheduleService materialization engine from a
 *       ScheduleTemplate's RRULE (in which case template_uuid is set), or
 *   (b) Created manually by a dispatcher as a one-off standalone shift
 *       (in which case template_uuid is null).
 *
 * When a dispatcher manually edits a materialized item, is_exception is set to true
 * and exception_for_date records the original RRULE occurrence date. The materialization
 * engine will never overwrite items where is_exception = true.
 *
 * @property string              $uuid
 * @property string              $public_id
 * @property string|null         $schedule_uuid
 * @property string|null         $template_uuid
 * @property string|null         $assignee_uuid
 * @property string|null         $assignee_type
 * @property string|null         $resource_uuid
 * @property string|null         $resource_type
 * @property \Carbon\Carbon|null $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property int|null            $duration
 * @property \Carbon\Carbon|null $break_start_at
 * @property \Carbon\Carbon|null $break_end_at
 * @property string              $status
 * @property bool                $is_exception
 * @property string|null         $exception_for_date
 */
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
        'company_uuid',
        'schedule_uuid',
        'template_uuid',
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
        'is_exception',
        'exception_for_date',
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
        'is_exception'   => 'boolean',
        'meta'           => Json::class,
        'assignee_type'  => PolymorphicType::class,
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = [
        'company_uuid',
        'schedule_uuid',
        'template_uuid',
        'assignee_type',
        'assignee_uuid',
        'resource_type',
        'resource_uuid',
        'status',
        'is_exception',
        'start_at',
        'end_at',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * Get the schedule that owns this item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'schedule_uuid');
    }

    /**
     * Get the ScheduleTemplate that generated this item (null for standalone shifts).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function template()
    {
        return $this->belongsTo(ScheduleTemplate::class, 'template_uuid');
    }

    /**
     * Get the assignee (polymorphic — e.g. a Driver).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function assignee()
    {
        return $this->morphTo(__FUNCTION__, 'assignee_type', 'assignee_uuid');
    }

    /**
     * Get the resource (polymorphic — e.g. a Vehicle).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function resource()
    {
        return $this->morphTo(__FUNCTION__, 'resource_type', 'resource_uuid');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope: items for a specific polymorphic assignee.
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
     * Scope: items generated from a specific template.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $templateUuid
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromTemplate($query, $templateUuid)
    {
        return $query->where('template_uuid', $templateUuid);
    }

    /**
     * Scope: only manually-created or manually-edited exception items.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExceptions($query)
    {
        return $query->where('is_exception', true);
    }

    /**
     * Scope: only auto-generated (non-exception) items.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGenerated($query)
    {
        return $query->where('is_exception', false)->whereNotNull('template_uuid');
    }

    /**
     * Scope: items within a time range (overlapping).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|\Carbon\Carbon                 $startAt
     * @param string|\Carbon\Carbon                 $endAt
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
     * Scope: items that start on a specific date.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|\Carbon\Carbon                 $date
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnDate($query, $date)
    {
        return $query->whereDate('start_at', $date);
    }

    /**
     * Scope: only upcoming items.
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
     * Scope: filter by status (single or array).
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

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Calculate the duration in minutes from start_at and end_at.
     */
    public function calculateDuration(): int
    {
        if ($this->start_at && $this->end_at) {
            return (int) $this->start_at->diffInMinutes($this->end_at);
        }

        return 0;
    }

    /**
     * Mark this item as a manual exception so the materialization engine
     * will not overwrite it on subsequent runs.
     *
     * @return $this
     */
    public function markAsException(): self
    {
        if (!$this->is_exception) {
            $this->update([
                'is_exception'       => true,
                'exception_for_date' => $this->start_at ? $this->start_at->toDateString() : null,
            ]);
        }

        return $this;
    }

    /**
     * Determine whether this item is currently active (in progress).
     */
    public function isActive(): bool
    {
        return $this->status === 'in_progress'
            || ($this->start_at <= now() && $this->end_at >= now());
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-populate company_uuid from session or parent schedule
        static::creating(function ($item) {
            if (empty($item->company_uuid)) {
                if ($item->schedule_uuid) {
                    $schedule = Schedule::where('uuid', $item->schedule_uuid)->first();
                    if ($schedule) {
                        $item->company_uuid = $schedule->company_uuid;
                    }
                }
                if (empty($item->company_uuid)) {
                    $item->company_uuid = session('company');
                }
            }
        });

        // Auto-calculate duration on save if not explicitly provided
        static::saving(function ($item) {
            if (!$item->duration && $item->start_at && $item->end_at) {
                $item->duration = $item->calculateDuration();
            }
        });

        // When a materialized item is manually updated, flag it as an exception
        static::updating(function ($item) {
            if ($item->template_uuid && !$item->is_exception) {
                $dirty          = $item->getDirty();
                $scheduleFields = ['start_at', 'end_at', 'break_start_at', 'break_end_at', 'status'];
                if (count(array_intersect(array_keys($dirty), $scheduleFields)) > 0) {
                    $item->is_exception       = true;
                    $item->exception_for_date = $item->getOriginal('start_at')
                        ? \Carbon\Carbon::parse($item->getOriginal('start_at'))->toDateString()
                        : null;
                }
            }
        });
    }
}
