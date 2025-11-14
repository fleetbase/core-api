<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'meta'       => Json::class,
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['subject_type', 'subject_uuid', 'status', 'start_date', 'end_date'];

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
     * Get the schedule items for this schedule.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(ScheduleItem::class, 'schedule_uuid');
    }

    /**
     * Scope a query to only include schedules for a specific subject.
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
     * Scope a query to only include active schedules.
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
     * Scope a query to only include schedules within a date range.
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
}
