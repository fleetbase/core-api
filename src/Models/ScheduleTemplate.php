<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;
use RRule\RRule;

/**
 * Represents a reusable recurring shift pattern.
 *
 * A ScheduleTemplate stores the RRULE (RFC 5545) that defines when a shift recurs —
 * for example, "every Monday, Tuesday, and Thursday from 08:00 to 16:00".
 * It can be:
 *   (a) A company-level library template (subject_uuid = null, schedule_uuid = null) that
 *       a manager can apply to one or many drivers to quickly bootstrap their schedules.
 *   (b) A driver-specific applied template (schedule_uuid is set) that is actively used
 *       by the materialization engine to generate ScheduleItem records for that driver's Schedule.
 *
 * When a library template is applied to a driver, a copy is created with schedule_uuid set
 * to the driver's Schedule. This ensures that editing a driver's applied template does not
 * affect the original library template or other drivers using it.
 *
 * @property string $uuid
 * @property string $public_id
 * @property string $company_uuid
 * @property string|null $schedule_uuid
 * @property string|null $subject_uuid
 * @property string|null $subject_type
 * @property string $name
 * @property string|null $description
 * @property string|null $start_time  e.g. "08:00"
 * @property string|null $end_time    e.g. "16:00"
 * @property int|null $duration       shift duration in minutes
 * @property int|null $break_duration break duration in minutes
 * @property string|null $rrule       RFC 5545 RRULE string e.g. "FREQ=WEEKLY;BYDAY=MO,TU,TH"
 */
class ScheduleTemplate extends Model
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
    protected $publicIdType = 'schedule_template';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'schedule_templates';

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
        'schedule_uuid',
        'subject_uuid',
        'subject_type',
        'name',
        'description',
        'start_time',
        'end_time',
        'duration',
        'break_duration',
        'rrule',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'duration'       => 'integer',
        'break_duration' => 'integer',
        'meta'           => Json::class,
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = ['company_uuid', 'subject_type', 'subject_uuid', 'schedule_uuid'];

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * Get the company that owns the template.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid');
    }

    /**
     * Get the Schedule this template is applied to (null for library templates).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'schedule_uuid');
    }

    /**
     * Get the subject that this template belongs to (polymorphic).
     * Only populated for applied (driver-specific) templates.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * Get all ScheduleItem records generated from this template.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(ScheduleItem::class, 'template_uuid');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope: only company-level library templates (not yet applied to a specific schedule).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLibrary($query)
    {
        return $query->whereNull('schedule_uuid');
    }

    /**
     * Scope: only applied templates (linked to a specific schedule).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApplied($query)
    {
        return $query->whereNotNull('schedule_uuid');
    }

    /**
     * Scope: templates for a specific company.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $companyUuid
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCompany($query, $companyUuid)
    {
        return $query->where('company_uuid', $companyUuid);
    }

    /**
     * Scope: templates for a specific polymorphic subject.
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

    // ─── RRULE Helpers ────────────────────────────────────────────────────────

    /**
     * Determine whether this template has a valid RRULE string.
     *
     * @return bool
     */
    public function hasRrule(): bool
    {
        return !empty($this->rrule);
    }

    /**
     * Parse the RRULE string and return an RRule instance.
     * The DTSTART is synthesized from the template's start_time and the given reference date.
     *
     * @param \Carbon\Carbon|null $referenceDate  The date from which to start the rule (defaults to today)
     * @param string|null         $timezone       Timezone to use (defaults to UTC)
     *
     * @return \RRule\RRule|null
     */
    public function getRruleInstance(?\Carbon\Carbon $referenceDate = null, ?string $timezone = null): ?RRule
    {
        if (!$this->hasRrule()) {
            return null;
        }

        $tz            = $timezone ?: 'UTC';
        $referenceDate = $referenceDate ?: now($tz)->startOfDay();
        $startTime     = $this->start_time ?: '00:00';

        // Build a DTSTART from the reference date + template start_time
        $dtStart = \Carbon\Carbon::parse(
            $referenceDate->toDateString() . ' ' . $startTime,
            $tz
        );

        $rruleString = 'DTSTART=' . $dtStart->format('Ymd\THis') . "\n" . $this->rrule;

        try {
            return new RRule($rruleString);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get all occurrence dates between two Carbon dates.
     *
     * @param \Carbon\Carbon $from
     * @param \Carbon\Carbon $to
     * @param string|null    $timezone
     *
     * @return \Carbon\Carbon[]
     */
    public function getOccurrencesBetween(\Carbon\Carbon $from, \Carbon\Carbon $to, ?string $timezone = null): array
    {
        $rrule = $this->getRruleInstance($from, $timezone);

        if (!$rrule) {
            return [];
        }

        $occurrences = [];
        foreach ($rrule as $occurrence) {
            $carbon = \Carbon\Carbon::instance($occurrence);
            if ($carbon->gt($to)) {
                break;
            }
            if ($carbon->gte($from)) {
                $occurrences[] = $carbon;
            }
        }

        return $occurrences;
    }

    /**
     * Apply this library template to a given Schedule, creating a driver-specific copy.
     *
     * @param Schedule    $schedule
     * @param string|null $subjectType
     * @param string|null $subjectUuid
     *
     * @return static
     */
    public function applyToSchedule(Schedule $schedule, ?string $subjectType = null, ?string $subjectUuid = null): self
    {
        return static::create([
            'company_uuid' => $this->company_uuid,
            'schedule_uuid' => $schedule->uuid,
            'subject_type'  => $subjectType ?? $schedule->subject_type,
            'subject_uuid'  => $subjectUuid ?? $schedule->subject_uuid,
            'name'          => $this->name,
            'description'   => $this->description,
            'start_time'    => $this->start_time,
            'end_time'      => $this->end_time,
            'duration'      => $this->duration,
            'break_duration' => $this->break_duration,
            'rrule'         => $this->rrule,
            'meta'          => $this->meta,
        ]);
    }
}
