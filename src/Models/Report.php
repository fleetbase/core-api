<?php

namespace Fleetbase\Models;

use Carbon\Carbon;
use Fleetbase\Casts\Json;
use Fleetbase\Support\Reporting\ReportQueryConverter;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Report extends Model
{
    use HasUuid;
    use HasPublicId;
    use SoftDeletes;
    use Searchable;
    use Filterable;
    use HasApiModelBehavior;

    /**
     * The database table used by the model.
     */
    protected $table = 'reports';

    /**
     * The type of public Id to generate.
     */
    protected $publicIdType = 'report';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'category_uuid',
        'created_by_uuid',
        'updated_by_uuid',
        'subject_uuid',
        'subject_type',
        'title',
        'description',
        'period_start',
        'period_end',
        'result_columns',
        'last_executed_at',
        'execution_time',
        'row_count',
        'is_scheduled',
        'schedule_config',
        'export_formats',
        'is_generated',
        'generation_progress',
        'options',
        'tags',
        'query_config',
        'result_columns',
        'data',
        'meta',
        'status',
        'type',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'query_config'              => Json::class,
        'meta'                      => Json::class,
        'options'                   => Json::class,
        'result_columns'            => Json::class,
        'data'                      => Json::class,
        'tags'                      => Json::class,
        'schedule_config'           => Json::class,
        'is_generated'              => 'boolean',
        'is_scheduled'              => 'boolean',
        'period_start'              => 'datetime',
        'period_end'                => 'datetime',
        'last_executed_at'          => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * The attributes that are searchable.
     */
    protected $searchableColumns = ['title', 'description', 'category'];

    /**
     * The attributes that are filterable.
     */
    protected $filterableColumns = ['category', 'status', 'is_scheduled', 'is_public', 'created_by_uuid'];

    /**
     * Dynamic relationships.
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_uuid');
    }

    public function executions()
    {
        return $this->hasMany(ReportExecution::class, 'report_uuid');
    }

    public function auditLogs()
    {
        return $this->hasMany(ReportAuditLog::class, 'report_uuid');
    }

    /**
     * Get available tables for reporting.
     */
    public static function getAvailableTables(string $extension = 'core', ?string $category = null): array
    {
        $registry = app(ReportSchemaRegistry::class);

        return $registry->getAvailableTables($extension, $category);
    }

    /**
     * Get columns for a specific table.
     */
    public static function getTableColumns(string $tableName): array
    {
        $registry = app(ReportSchemaRegistry::class);

        return $registry->getTableColumns($tableName);
    }

    /**
     * Get table relationships.
     */
    public static function getTableRelationships(string $tableName): array
    {
        $registry = app(ReportSchemaRegistry::class);

        return $registry->getTableRelationships($tableName);
    }

    /**
     * Get table schema including columns and relationships.
     */
    public static function getTableSchema(string $tableName): array
    {
        $registry = app(ReportSchemaRegistry::class);

        return $registry->getTableSchema($tableName);
    }

    /**
     * Execute the report query using the new schema format.
     */
    public function execute(array $options = []): array
    {
        $startTime = microtime(true);

        try {
            // Validate query configuration
            $this->validateQueryConfig();

            // Get the query converter
            $registry  = app(ReportSchemaRegistry::class);
            $converter = new ReportQueryConverter($registry, $this->query_config);

            // Execute the query
            $result = $converter->execute();

            if (!$result['success']) {
                throw new \Exception($result['error']);
            }

            // Calculate execution time
            $executionTime = $result['meta']['execution_time_ms'];

            // Update execution statistics
            $this->updateExecutionStats($executionTime, $result['meta']['total_rows']);

            // Cache results if configured
            if (config('reports.cache_results', true)) {
                $this->cacheResults($result['data'], $result['columns'], $executionTime);
            }

            // Log execution
            $this->logExecution($executionTime, $result['meta']['total_rows']);

            return [
                'success' => true,
                'results' => $result['data'],
                'columns' => $result['columns'],
                'meta'    => [
                    'execution_time'   => $executionTime,
                    'total_rows'       => $result['meta']['total_rows'],
                    'query_sql'        => $result['meta']['query_sql'],
                    'selected_columns' => $result['meta']['selected_columns'],
                    'joined_tables'    => $result['meta']['joined_tables'],
                    'query_config'     => $this->query_config,
                ],
            ];
        } catch (\Exception $e) {
            // Log error
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->logExecution($executionTime, 0, $e->getMessage());

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'meta'    => [
                    'execution_time' => $executionTime,
                ],
            ];
        }
    }

    /**
     * Export report results in specified format.
     */
    public function export(string $format = 'csv', array $options = []): array
    {
        try {
            // Validate query configuration
            $this->validateQueryConfig();

            // Get the query converter
            $registry  = app(ReportSchemaRegistry::class);
            $converter = new ReportQueryConverter($registry, $this->query_config);

            // Export the data
            $result = $converter->export($format);

            // Log export
            $this->logExport($format, $result['rows'] ?? 0);

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate the new query configuration format.
     */
    protected function validateQueryConfig(): void
    {
        if (!$this->query_config) {
            throw new \InvalidArgumentException('Query configuration is required');
        }

        // Validate required structure
        $required = ['table', 'columns'];
        foreach ($required as $key) {
            if (!isset($this->query_config[$key])) {
                throw new \InvalidArgumentException("Query configuration missing required key: {$key}");
            }
        }

        // Validate table structure
        if (!isset($this->query_config['table']['name'])) {
            throw new \InvalidArgumentException('Table name is required in query configuration');
        }

        // Validate columns structure
        if (empty($this->query_config['columns'])) {
            throw new \InvalidArgumentException('At least one column must be selected');
        }
    }

    /**
     * Get query complexity based on new schema.
     */
    public function getQueryComplexity(): string
    {
        if (!$this->query_config) {
            return 'invalid';
        }

        $complexity = 'simple';

        // Check for joins
        if (isset($this->query_config['joins']) && !empty($this->query_config['joins'])) {
            $complexity = 'moderate';
        }

        // Check for conditions
        if (isset($this->query_config['conditions']) && !empty($this->query_config['conditions'])) {
            if ($complexity === 'moderate') {
                $complexity = 'complex';
            } else {
                $complexity = 'moderate';
            }
        }

        // Check for grouping
        if (isset($this->query_config['groupBy']) && !empty($this->query_config['groupBy'])) {
            $complexity = 'complex';
        }

        // Check for many columns
        if (count($this->query_config['columns']) > 10) {
            $complexity = 'complex';
        }

        return $complexity;
    }

    /**
     * Get selected columns count including joins.
     */
    public function getSelectedColumnsCount(): int
    {
        $count = count($this->query_config['columns'] ?? []);

        // Add joined columns
        if (isset($this->query_config['joins'])) {
            foreach ($this->query_config['joins'] as $join) {
                $count += count($join['selectedColumns'] ?? []);
            }
        }

        return $count;
    }

    /**
     * Get source table information.
     */
    public function getSourceTable(): array
    {
        return $this->query_config['table'] ?? [];
    }

    /**
     * Check if report has filters.
     */
    public function hasFilters(): bool
    {
        return isset($this->query_config['conditions']) && !empty($this->query_config['conditions']);
    }

    /**
     * Check if report has joins.
     */
    public function hasJoins(): bool
    {
        return isset($this->query_config['joins']) && !empty($this->query_config['joins']);
    }

    /**
     * Check if report has grouping.
     */
    public function hasGrouping(): bool
    {
        return isset($this->query_config['groupBy']) && !empty($this->query_config['groupBy']);
    }

    /**
     * Check if report has sorting.
     */
    public function hasSorting(): bool
    {
        return isset($this->query_config['sortBy']) && !empty($this->query_config['sortBy']);
    }

    /**
     * Get filters summary.
     */
    public function getFiltersSummary(): array
    {
        if (!$this->hasFilters()) {
            return [];
        }

        $summary = [];
        foreach ($this->query_config['conditions'] as $condition) {
            $summary[] = [
                'field'    => $condition['field']['label'] ?? $condition['field']['name'],
                'operator' => $condition['operator']['label'] ?? $condition['operator']['value'],
                'value'    => $condition['value'],
            ];
        }

        return $summary;
    }

    /**
     * Get joins summary.
     */
    public function getJoinsSummary(): array
    {
        if (!$this->hasJoins()) {
            return [];
        }

        $summary = [];
        foreach ($this->query_config['joins'] as $join) {
            $summary[] = [
                'table'         => $join['table'],
                'label'         => $join['label'],
                'type'          => strtoupper($join['type']),
                'columns_count' => count($join['selectedColumns'] ?? []),
            ];
        }

        return $summary;
    }

    /**
     * Update execution statistics.
     */
    protected function updateExecutionStats(float $executionTime, int $resultCount): void
    {
        $this->execution_count = ($this->execution_count ?? 0) + 1;

        // Calculate new average execution time
        if ($this->average_execution_time) {
            $this->average_execution_time = (($this->average_execution_time * ($this->execution_count - 1)) + $executionTime) / $this->execution_count;
        } else {
            $this->average_execution_time = $executionTime;
        }

        $this->last_result_count = $resultCount;
        $this->last_executed_at  = now();

        $this->save();
    }

    /**
     * Cache query results.
     */
    protected function cacheResults(array $results, array $columns, float $executionTime): void
    {
        $cacheKey  = "report_results_{$this->uuid}";
        $cacheData = [
            'results'        => $results,
            'columns'        => $columns,
            'execution_time' => $executionTime,
            'cached_at'      => now()->toISOString(),
        ];

        // Cache for configured time (default 1 hour)
        $cacheTtl = config('reports.cache_ttl', 3600);
        Cache::put($cacheKey, $cacheData, $cacheTtl);
    }

    /**
     * Get cached results.
     */
    public function getCachedResults(): ?array
    {
        $cacheKey = "report_results_{$this->uuid}";

        return Cache::get($cacheKey);
    }

    /**
     * Clear cached results.
     */
    public function clearCache(): void
    {
        $cacheKey = "report_results_{$this->uuid}";
        Cache::forget($cacheKey);
    }

    /**
     * Log report execution.
     */
    protected function logExecution(float $executionTime, int $resultCount, ?string $error = null): void
    {
        $this->executions()->create([
            'executed_at'    => now(),
            'execution_time' => $executionTime,
            'result_count'   => $resultCount,
            'error_message'  => $error,
            'query_config'   => $this->query_config,
        ]);
    }

    /**
     * Log report export.
     */
    protected function logExport(string $format, int $rowCount): void
    {
        $this->auditLogs()->create([
            'action'  => 'export',
            'details' => [
                'format'      => $format,
                'row_count'   => $rowCount,
                'exported_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get report performance metrics.
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'execution_count'        => $this->execution_count ?? 0,
            'average_execution_time' => $this->average_execution_time ?? 0,
            'last_result_count'      => $this->last_result_count ?? 0,
            'last_executed_at'       => $this->last_executed_at?->toISOString(),
            'complexity'             => $this->getQueryComplexity(),
            'selected_columns_count' => $this->getSelectedColumnsCount(),
            'has_joins'              => $this->hasJoins(),
            'has_filters'            => $this->hasFilters(),
            'has_grouping'           => $this->hasGrouping(),
            'has_sorting'            => $this->hasSorting(),
        ];
    }

    /**
     * Clone report with new configuration.
     */
    public function cloneWithConfig(array $newQueryConfig, ?string $newTitle = null): self
    {
        $clone                         = $this->replicate();
        $clone->query_config           = $newQueryConfig;
        $clone->title                  = $newTitle ?? ($this->title . ' (Copy)');
        $clone->execution_count        = 0;
        $clone->average_execution_time = null;
        $clone->last_result_count      = null;
        $clone->last_executed_at       = null;
        $clone->save();

        return $clone;
    }

    /**
     * Schedule report execution.
     */
    public function schedule(string $frequency, string $time, string $timezone = 'UTC'): void
    {
        $this->update([
            'is_scheduled'       => true,
            'schedule_frequency' => $frequency,
            'schedule_time'      => $time,
            'schedule_timezone'  => $timezone,
            'next_scheduled_run' => $this->calculateNextRun($frequency, $time, $timezone),
        ]);
    }

    /**
     * Calculate next scheduled run time.
     */
    protected function calculateNextRun(string $frequency, string $time, string $timezone): Carbon
    {
        $now = Carbon::now($timezone);

        switch ($frequency) {
            case 'daily':
                $next = $now->copy()->setTimeFromTimeString($time);
                if ($next->isPast()) {
                    $next->addDay();
                }
                break;
            case 'weekly':
                $next = $now->copy()->next(Carbon::MONDAY)->setTimeFromTimeString($time);
                break;
            case 'monthly':
                $next = $now->copy()->startOfMonth()->addMonth()->setTimeFromTimeString($time);
                break;
            default:
                $next = $now->copy()->addHour();
                break;
        }

        return $next->utc();
    }

    /**
     * Get auto-join columns from query config.
     */
    public function getAutoJoinColumns(): array
    {
        $autoJoinColumns = [];

        foreach ($this->query_config['columns'] ?? [] as $column) {
            if (isset($column['auto_join_path'])) {
                $autoJoinColumns[] = $column;
            }
        }

        return $autoJoinColumns;
    }

    /**
     * Check if report uses auto-joins.
     */
    public function hasAutoJoins(): bool
    {
        return !empty($this->getAutoJoinColumns());
    }
}
