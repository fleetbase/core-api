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
 * Represents a subject's personal calendar — the container for their schedule items.
 *
 * A Schedule belongs to a polymorphic subject (e.g. a Driver) and holds the collection
 * of ScheduleItem records that represent concrete, materialized shifts. The recurring
 * pattern that generates those items is defined on a ScheduleTemplate (via its rrule field).
 *
 * The materialization engine reads all active ScheduleTemplates linked to this schedule,
 * expands their RRULEs using rlanvin/php-rrule, and writes ScheduleItem rows for a rolling
 * window. The last_materialized_at and materialization_horizon columns track the engine's progress.
 *
 * @property string              $uuid
 * @property string              $public_id
 * @property string              $company_uuid
 * @property string              $subject_uuid
 * @property string              $subject_type
 * @property string|null         $name
 * @property string|null         $description
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property string|null         $timezone
 * @property string              $status
 * @property \Carbon\Carbon|null $last_materialized_at
 * @property string|null         $materialization_horizon
 */
class Schedule extends Model
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
    protected $publicIdType = 'schedule';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'schedules';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'description'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'public_id',
        'company_uuid',
        'subject_uuid',
        'subject_type',
        'name',
        'description',
        'start_date',
        'end_date',
        'timezone',
        'status',
        'last_materialized_at',
        'materialization_horizon',
        'hos_daily_limit',
        'hos_weekly_limit',
        'hos_source',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_date'              => 'date',
        'end_date'                => 'date',
        'last_materialized_at'    => 'datetime',
        'materialization_horizon' => 'date',
        'meta'                    => Json::class,
        'subject_type'            => PolymorphicType::class,
        'hos_daily_limit'         => 'integer',
        'hos_weekly_limit'        => 'integer',
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['subject_type', 'subject_uuid', 'status', 'start_date', 'end_date'];

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * Get the subject that this schedule belongs to (polymorphic).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * Get the company that owns the schedule.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid');
    }

    /**
     * Get all concrete shift items for this schedule.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(ScheduleItem::class, 'schedule_uuid');
    }

    /**
     * Get the schedule templates (recurring patterns) applied to this schedule.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function templates()
    {
        return $this->hasMany(ScheduleTemplate::class, 'schedule_uuid');
    }

    /**
     * Get all exceptions (time off, sick leave, etc.) for this schedule.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function exceptions()
    {
        return $this->hasMany(ScheduleException::class, 'schedule_uuid');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope: only schedules for a specific polymorphic subject.
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
     * Scope: only active schedules.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: schedules that overlap with a given date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $startDate
     * @param string                                $endDate
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithinDateRange($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
              ->orWhereBetween('end_date', [$startDate, $endDate])
              ->orWhere(function ($q) use ($startDate, $endDate) {
                  $q->where('start_date', '<=', $startDate)
                    ->where(function ($q) use ($endDate) {
                        $q->where('end_date', '>=', $endDate)
                          ->orWhereNull('end_date');
                    });
              });
        });
    }

    /**
     * Scope: schedules whose materialization horizon is before a given date
     * (i.e. schedules that need to be re-materialized to extend the rolling window).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|\Carbon\Carbon                 $date
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedsMaterialization($query, $date)
    {
        return $query->where(function ($q) use ($date) {
            $q->whereNull('materialization_horizon')
              ->orWhere('materialization_horizon', '<', $date);
        });
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Determine whether this schedule needs materialization up to the given date.
     */
    public function needsMaterializationUpTo(\Carbon\Carbon $upTo): bool
    {
        return is_null($this->materialization_horizon)
            || $this->materialization_horizon->lt($upTo);
    }

    /**
     * Get the effective timezone for this schedule, falling back to UTC.
     */
    public function getEffectiveTimezone(): string
    {
        return $this->timezone ?: 'UTC';
    }
}
