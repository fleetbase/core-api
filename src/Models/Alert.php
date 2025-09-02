<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Alert.
 *
 * Represents a rule-triggered signal from telemetry, sensor context, or system events.
 * Alerts can be triggered by various conditions like temperature out of range,
 * maintenance due, low stock, etc. This model belongs to the Core API.
 */
class Alert extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'alerts';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'alert';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['type', 'message', 'severity', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['type', 'severity', 'status', 'subject_type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'type',
        'severity',
        'status',
        'subject_type',
        'subject_uuid',
        'message',
        'rule',
        'context',
        'triggered_at',
        'acknowledged_at',
        'resolved_at',
        'acknowledged_by_uuid',
        'resolved_by_uuid',
        'meta',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'subject_name',
        'acknowledged_by_name',
        'resolved_by_name',
        'is_acknowledged',
        'is_resolved',
        'duration_minutes',
        'age_minutes',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['subject', 'acknowledgedBy', 'resolvedBy'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'rule'            => Json::class,
        'context'         => Json::class,
        'triggered_at'    => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at'     => 'datetime',
        'meta'            => Json::class,
    ];

    /**
     * Properties which activity needs to be logged.
     *
     * @var array
     */
    protected static $logAttributes = '*';

    /**
     * Do not log empty changed.
     *
     * @var bool
     */
    protected static $submitEmptyLogs = false;

    /**
     * The name of the subject to log.
     *
     * @var string
     */
    protected static $logName = 'alert';

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_uuid', 'uuid');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_uuid', 'uuid');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subject name.
     */
    public function getSubjectNameAttribute(): ?string
    {
        if ($this->subject) {
            return $this->subject->name ?? $this->subject->display_name ?? null;
        }

        return null;
    }

    /**
     * Get the name of who acknowledged the alert.
     */
    public function getAcknowledgedByNameAttribute(): ?string
    {
        return $this->acknowledgedBy?->name;
    }

    /**
     * Get the name of who resolved the alert.
     */
    public function getResolvedByNameAttribute(): ?string
    {
        return $this->resolvedBy?->name;
    }

    /**
     * Check if the alert has been acknowledged.
     */
    public function getIsAcknowledgedAttribute(): bool
    {
        return !is_null($this->acknowledged_at);
    }

    /**
     * Check if the alert has been resolved.
     */
    public function getIsResolvedAttribute(): bool
    {
        return $this->status === 'resolved';
    }

    /**
     * Get the duration in minutes from triggered to resolved.
     */
    public function getDurationMinutesAttribute(): ?int
    {
        if ($this->triggered_at && $this->resolved_at) {
            return $this->triggered_at->diffInMinutes($this->resolved_at);
        }

        return null;
    }

    /**
     * Get the age of the alert in minutes.
     */
    public function getAgeMinutesAttribute(): int
    {
        $startTime = $this->triggered_at ?? $this->created_at;

        return $startTime->diffInMinutes(now());
    }

    /**
     * Scope to get alerts by type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get alerts by severity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope to get alerts by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get open alerts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope to get acknowledged alerts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAcknowledged($query)
    {
        return $query->whereNotNull('acknowledged_at');
    }

    /**
     * Scope to get unacknowledged alerts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    /**
     * Scope to get critical alerts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope to get high priority alerts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', ['critical', 'high']);
    }

    /**
     * Acknowledge the alert.
     */
    public function acknowledge(?User $user = null): bool
    {
        if ($this->is_acknowledged) {
            return false;
        }

        $user = $user ?? auth()->user();

        $updated = $this->update([
            'acknowledged_at'      => now(),
            'acknowledged_by_uuid' => $user?->uuid,
        ]);

        if ($updated) {
            activity('alert_acknowledged')
                ->performedOn($this)
                ->withProperties([
                    'acknowledged_by' => $user?->name,
                ])
                ->log('Alert acknowledged');
        }

        return $updated;
    }

    /**
     * Resolve the alert.
     */
    public function resolve(?User $user = null, ?string $resolution = null): bool
    {
        if ($this->is_resolved) {
            return false;
        }

        $user = $user ?? auth()->user();

        $updateData = [
            'status'           => 'resolved',
            'resolved_at'      => now(),
            'resolved_by_uuid' => $user?->uuid,
        ];

        if ($resolution) {
            $meta               = $this->meta ?? [];
            $meta['resolution'] = $resolution;
            $updateData['meta'] = $meta;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('alert_resolved')
                ->performedOn($this)
                ->withProperties([
                    'resolved_by' => $user?->name,
                    'resolution'  => $resolution,
                ])
                ->log('Alert resolved');
        }

        return $updated;
    }

    /**
     * Escalate the alert to a higher severity.
     */
    public function escalate(string $newSeverity, ?string $reason = null): bool
    {
        $severityLevels = ['low', 'medium', 'high', 'critical'];
        $currentLevel   = array_search($this->severity, $severityLevels);
        $newLevel       = array_search($newSeverity, $severityLevels);

        if ($newLevel === false || $newLevel <= $currentLevel) {
            return false;
        }

        $updateData = ['severity' => $newSeverity];

        if ($reason) {
            $meta                         = $this->meta ?? [];
            $meta['escalation_history']   = $meta['escalation_history'] ?? [];
            $meta['escalation_history'][] = [
                'from'         => $this->severity,
                'to'           => $newSeverity,
                'reason'       => $reason,
                'escalated_at' => now(),
                'escalated_by' => auth()->id(),
            ];
            $updateData['meta'] = $meta;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('alert_escalated')
                ->performedOn($this)
                ->withProperties([
                    'from_severity' => $this->getOriginal('severity'),
                    'to_severity'   => $newSeverity,
                    'reason'        => $reason,
                ])
                ->log('Alert escalated');
        }

        return $updated;
    }

    /**
     * Snooze the alert for a specified duration.
     */
    public function snooze(int $minutes, ?string $reason = null): bool
    {
        $snoozeUntil = now()->addMinutes($minutes);

        $meta                  = $this->meta ?? [];
        $meta['snoozed_until'] = $snoozeUntil;
        $meta['snooze_reason'] = $reason;

        $updated = $this->update(['meta' => $meta]);

        if ($updated) {
            activity('alert_snoozed')
                ->performedOn($this)
                ->withProperties([
                    'snoozed_for_minutes' => $minutes,
                    'snoozed_until'       => $snoozeUntil,
                    'reason'              => $reason,
                ])
                ->log('Alert snoozed');
        }

        return $updated;
    }

    /**
     * Check if the alert is currently snoozed.
     */
    public function isSnoozed(): bool
    {
        $meta        = $this->meta ?? [];
        $snoozeUntil = $meta['snoozed_until'] ?? null;

        if (!$snoozeUntil) {
            return false;
        }

        return now()->lt($snoozeUntil);
    }

    /**
     * Get the severity level as a numeric value for sorting.
     */
    public function getSeverityLevel(): int
    {
        switch ($this->severity) {
            case 'critical':
                return 4;
            case 'high':
                return 3;
            case 'medium':
                return 2;
            case 'low':
                return 1;
            default:
                return 0;
        }
    }

    /**
     * Get the priority score for sorting alerts.
     */
    public function getPriorityScore(): int
    {
        $severityScore       = $this->getSeverityLevel() * 100;
        $ageScore            = min($this->age_minutes, 1440); // Cap at 24 hours
        $acknowledgedPenalty = $this->is_acknowledged ? -50 : 0;

        return $severityScore + $ageScore + $acknowledgedPenalty;
    }

    /**
     * Check if the alert matches a specific rule.
     */
    public function matchesRule(array $ruleData): bool
    {
        $alertRule = $this->rule ?? [];

        // Simple rule matching - in practice, this would be more sophisticated
        foreach ($ruleData as $key => $value) {
            if (!isset($alertRule[$key]) || $alertRule[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get related alerts based on subject and type.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRelatedAlerts(int $limit = 5)
    {
        return static::where('subject_type', $this->subject_type)
            ->where('subject_uuid', $this->subject_uuid)
            ->where('type', $this->type)
            ->where('uuid', '!=', $this->uuid)
            ->orderBy('triggered_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Create a notification for the alert.
     */
    public function createNotification(array $recipients = []): bool
    {
        // This would integrate with the notification system
        // For now, just log the notification attempt

        activity('alert_notification_sent')
            ->performedOn($this)
            ->withProperties([
                'recipients' => $recipients,
                'severity'   => $this->severity,
                'type'       => $this->type,
            ])
            ->log('Alert notification sent');

        return true;
    }
}
