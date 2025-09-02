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
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Class Report.
 *
 * Captures time-bounded summaries and analytics over various entities in the system.
 * Reports can cover utilization, safety, fuel consumption, compliance, and other metrics.
 * This model belongs to the Core API.
 */
class Report extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasSlug;
    use LogsActivity;
    use HasMetaAttributes;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'reports';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'report';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['type', 'title', 'body', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['type', 'status', 'subject_type'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'type',
        'title',
        'subject_type',
        'subject_uuid',
        'period_start',
        'period_end',
        'data',
        'body',
        'status',
        'slug',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'subject_name',
        'period_duration_days',
        'is_generated',
        'generation_progress',
        'summary_stats',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['subject'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'period_start' => 'datetime',
        'period_end'   => 'datetime',
        'data'         => Json::class,
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
    protected static $logName = 'report';

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['title', 'type'])
            ->saveSlugsTo('slug');
    }

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
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
     * Get the period duration in days.
     */
    public function getPeriodDurationDaysAttribute(): ?int
    {
        if ($this->period_start && $this->period_end) {
            return $this->period_start->diffInDays($this->period_end);
        }

        return null;
    }

    /**
     * Check if the report has been generated.
     */
    public function getIsGeneratedAttribute(): bool
    {
        return $this->status === 'complete';
    }

    /**
     * Get the generation progress percentage.
     */
    public function getGenerationProgressAttribute(): float
    {
        switch ($this->status) {
            case 'pending':
                return 0.0;
            case 'generating':
                return 50.0;
            case 'complete':
                return 100.0;
            case 'failed':
                return 0.0;
            default:
                return 0.0;
        }
    }

    /**
     * Get summary statistics from the report data.
     */
    public function getSummaryStatsAttribute(): array
    {
        $data = $this->data ?? [];

        if (empty($data)) {
            return [];
        }

        // Extract common summary statistics
        $summary = [];

        if (isset($data['summary'])) {
            $summary = $data['summary'];
        } else {
            // Generate basic summary from data
            if (isset($data['metrics'])) {
                $metrics                  = $data['metrics'];
                $summary['total_records'] = count($metrics);

                // Calculate basic stats for numeric values
                foreach ($metrics as $key => $values) {
                    if (is_array($values) && !empty($values)) {
                        $numericValues = array_filter($values, 'is_numeric');
                        if (!empty($numericValues)) {
                            $summary[$key] = [
                                'count' => count($numericValues),
                                'sum'   => array_sum($numericValues),
                                'avg'   => array_sum($numericValues) / count($numericValues),
                                'min'   => min($numericValues),
                                'max'   => max($numericValues),
                            ];
                        }
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Scope to get reports by type.
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
     * Scope to get reports by status.
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
     * Scope to get completed reports.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'complete');
    }

    /**
     * Scope to get reports for a specific period.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForPeriod($query, \DateTime $start, \DateTime $end)
    {
        return $query->where('period_start', '>=', $start)
                    ->where('period_end', '<=', $end);
    }

    /**
     * Start generating the report.
     */
    public function startGeneration(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $updated = $this->update(['status' => 'generating']);

        if ($updated) {
            activity('report_generation_started')
                ->performedOn($this)
                ->log('Report generation started');
        }

        return $updated;
    }

    /**
     * Mark the report as completed.
     */
    public function markAsCompleted(array $data = [], ?string $body = null): bool
    {
        if ($this->status === 'complete') {
            return false;
        }

        $updateData = ['status' => 'complete'];

        if (!empty($data)) {
            $updateData['data'] = $data;
        }

        if ($body) {
            $updateData['body'] = $body;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('report_generation_completed')
                ->performedOn($this)
                ->withProperties([
                    'data_size'   => count($data),
                    'body_length' => strlen($body ?? ''),
                ])
                ->log('Report generation completed');
        }

        return $updated;
    }

    /**
     * Mark the report as failed.
     */
    public function markAsFailed(?string $error = null): bool
    {
        $updateData = ['status' => 'failed'];

        if ($error) {
            $data               = $this->data ?? [];
            $data['error']      = $error;
            $updateData['data'] = $data;
        }

        $updated = $this->update($updateData);

        if ($updated) {
            activity('report_generation_failed')
                ->performedOn($this)
                ->withProperties(['error' => $error])
                ->log('Report generation failed');
        }

        return $updated;
    }

    /**
     * Get a specific metric from the report data.
     */
    public function getMetric(string $metric, $default = null)
    {
        $data = $this->data ?? [];

        // Support dot notation for nested metrics
        $keys  = explode('.', $metric);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Set a specific metric in the report data.
     */
    public function setMetric(string $metric, $value): bool
    {
        $data = $this->data ?? [];

        // Support dot notation for nested metrics
        $keys    = explode('.', $metric);
        $current = &$data;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }

        return $this->update(['data' => $data]);
    }

    /**
     * Export the report data in a specific format.
     *
     * @return array|string
     */
    public function export(string $format = 'json')
    {
        $exportData = [
            'report_id' => $this->public_id,
            'title'     => $this->title,
            'type'      => $this->type,
            'period'    => [
                'start'         => $this->period_start?->toISOString(),
                'end'           => $this->period_end?->toISOString(),
                'duration_days' => $this->period_duration_days,
            ],
            'subject' => [
                'type' => $this->subject_type,
                'name' => $this->subject_name,
            ],
            'generated_at' => $this->updated_at?->toISOString(),
            'data'         => $this->data,
            'summary'      => $this->summary_stats,
        ];

        switch ($format) {
            case 'csv':
                // Convert to CSV format (simplified)
                return $this->convertToCsv($exportData);
            case 'xml':
                // Convert to XML format (simplified)
                return $this->convertToXml($exportData);
            case 'json':
            default:
                return $exportData;
        }
    }

    /**
     * Convert data to CSV format.
     */
    protected function convertToCsv(array $data): string
    {
        // This is a simplified CSV conversion
        // In a real implementation, you'd want more sophisticated CSV handling
        $csv = "Report: {$data['title']}\n";
        $csv .= "Type: {$data['type']}\n";
        $csv .= "Period: {$data['period']['start']} to {$data['period']['end']}\n\n";

        if (isset($data['summary']) && is_array($data['summary'])) {
            $csv .= "Summary:\n";
            foreach ($data['summary'] as $key => $value) {
                $csv .= "{$key}," . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }

        return $csv;
    }

    /**
     * Convert data to XML format.
     */
    protected function convertToXml(array $data): string
    {
        // This is a simplified XML conversion
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<report>\n";
        $xml .= "  <title>{$data['title']}</title>\n";
        $xml .= "  <type>{$data['type']}</type>\n";
        $xml .= "  <period>\n";
        $xml .= "    <start>{$data['period']['start']}</start>\n";
        $xml .= "    <end>{$data['period']['end']}</end>\n";
        $xml .= "  </period>\n";
        $xml .= '  <data>' . json_encode($data['data']) . "</data>\n";
        $xml .= "</report>\n";

        return $xml;
    }

    /**
     * Schedule a recurring report.
     */
    public function scheduleRecurring(string $frequency, array $options = []): bool
    {
        // This would integrate with a job scheduling system
        // For now, just log the scheduling attempt

        activity('report_scheduled')
            ->performedOn($this)
            ->withProperties([
                'frequency' => $frequency,
                'options'   => $options,
            ])
            ->log("Report scheduled for recurring generation ({$frequency})");

        return true;
    }
}
