<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents an explicit deviation from a driver's (or any subject's) recurring schedule.
 *
 * Examples include approved time off, sick leave, public holiday overrides, and shift swaps.
 * When the materialization engine generates ScheduleItem records from a ScheduleTemplate's RRULE,
 * it checks this table and skips (or cancels) any occurrence that falls within an approved exception window.
 *
 * @property string $uuid
 * @property string $public_id
 * @property string $company_uuid
 * @property string $subject_uuid
 * @property string $subject_type
 * @property string|null $schedule_uuid
 * @property \Carbon\Carbon|null $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property string|null $type
 * @property string $status
 * @property string|null $reason
 * @property string|null $notes
 * @property string|null $reviewed_by_uuid
 * @property \Carbon\Carbon|null $reviewed_at
 */
class ScheduleException extends Model
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
    protected $publicIdType = 'schedule_exception';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'schedule_exceptions';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['reason', 'notes', 'type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'subject_uuid',
        'subject_type',
        'schedule_uuid',
        'start_at',
        'end_at',
        'type',
        'status',
        'reason',
        'notes',
        'reviewed_by_uuid',
        'reviewed_at',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_at'    => 'datetime',
        'end_at'      => 'datetime',
        'reviewed_at' => 'datetime',
        'meta'        => Json::class,
    ];

    /**
     * Attributes that are filterable on this model.
     *
     * @var array
     */
    protected $filterParams = [
        'company_uuid',
        'subject_uuid',
        'subject_type',
        'schedule_uuid',
        'type',
        'status',
        'start_at',
        'end_at',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['type_label', 'is_pending'];

    /**
     * Valid exception types.
     */
    const TYPES = ['time_off', 'sick', 'holiday', 'swap', 'training', 'other'];

    /**
     * Valid workflow statuses.
     */
    const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * Get the subject this exception applies to (polymorphic).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * Get the parent schedule this exception belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'schedule_uuid');
    }

    /**
     * Get the user who reviewed (approved/rejected) this exception.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_uuid');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope: only approved exceptions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope: only pending exceptions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: filter by exception type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $type
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: filter by subject (polymorphic).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $type
     * @param string                                $uuid
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSubject($query, string $type, string $uuid)
    {
        return $query->where('subject_type', $type)->where('subject_uuid', $uuid);
    }

    /**
     * Scope: exceptions that overlap with a given time range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|\Carbon\Carbon                 $startAt
     * @param string|\Carbon\Carbon                 $endAt
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverlapping($query, $startAt, $endAt)
    {
        return $query->where(function ($q) use ($startAt, $endAt) {
            $q->where('start_at', '<', $endAt)
              ->where('end_at', '>', $startAt);
        });
    }

    /**
     * Scope: exceptions that cover a specific date.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|\Carbon\Carbon                 $date
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCoveringDate($query, $date)
    {
        return $query->where('start_at', '<=', $date)
                     ->where('end_at', '>=', $date);
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    /**
     * Get a human-readable label for the exception type.
     *
     * @return string
     */
    public function getTypeLabelAttribute(): string
    {
        $labels = [
            'time_off'  => 'Time Off',
            'sick'      => 'Sick Leave',
            'holiday'   => 'Holiday',
            'swap'      => 'Shift Swap',
            'training'  => 'Training',
            'other'     => 'Other',
        ];

        return $labels[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type ?? ''));
    }

    /**
     * Determine whether this exception is in pending status.
     *
     * @return bool
     */
    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Approve this exception.
     *
     * @param string|null $reviewerUuid
     *
     * @return $this
     */
    public function approve(?string $reviewerUuid = null): self
    {
        $this->update([
            'status'           => 'approved',
            'reviewed_by_uuid' => $reviewerUuid ?? auth()->id(),
            'reviewed_at'      => now(),
        ]);

        return $this;
    }

    /**
     * Reject this exception.
     *
     * @param string|null $reviewerUuid
     *
     * @return $this
     */
    public function reject(?string $reviewerUuid = null): self
    {
        $this->update([
            'status'           => 'rejected',
            'reviewed_by_uuid' => $reviewerUuid ?? auth()->id(),
            'reviewed_at'      => now(),
        ]);

        return $this;
    }

    /**
     * Determine whether this exception is currently active (approved and covering now).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'approved'
            && $this->start_at <= now()
            && $this->end_at >= now();
    }
}
