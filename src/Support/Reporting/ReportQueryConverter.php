<?php

namespace Fleetbase\Support\Reporting;

use Fleetbase\Support\Utils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportQueryConverter
{
    protected ReportSchemaRegistry $registry;
    protected array $queryConfig;
    protected array $autoJoins         = [];
    protected array $manualJoins       = [];
    protected array $joinAliases       = [];
    protected array $emittedAggregates = [];
    protected int $aliasCounter        = 0;

    public function __construct(ReportSchemaRegistry $registry, array $queryConfig)
    {
        $this->registry    = $registry;
        $this->queryConfig = $queryConfig;
        
        // Extract computed columns from groupBy aggregates if not already in computed_columns array
        $this->extractComputedColumnsFromAggregates();
    }
    
    /**
     * Extract computed columns from groupBy aggregates and add to computed_columns array.
     * This handles cases where the frontend sends computed column metadata in aggregateBy objects.
     */
    protected function extractComputedColumnsFromAggregates(): void
    {
        if (empty($this->queryConfig['groupBy'])) {
            return;
        }
        
        // Initialize computed_columns array if it doesn't exist
        if (!isset($this->queryConfig['computed_columns'])) {
            $this->queryConfig['computed_columns'] = [];
        }
        
        // Track which computed columns we've already added
        $existingComputedColumns = [];
        foreach ($this->queryConfig['computed_columns'] as $col) {
            $existingComputedColumns[$col['name']] = true;
        }
        
        // Extract computed columns from groupBy aggregates
        foreach ($this->queryConfig['groupBy'] as $groupBy) {
            $aggregateBy = $groupBy['aggregateBy'] ?? null;
            
            if (!$aggregateBy) {
                continue;
            }
            
            // Check if this is a computed column
            $isComputed = $aggregateBy['computed'] ?? false;
            $computation = $aggregateBy['computation'] ?? null;
            $name = $aggregateBy['name'] ?? null;
            
            if ($isComputed && $computation && $name && !isset($existingComputedColumns[$name])) {
                // Add to computed_columns array
                $this->queryConfig['computed_columns'][] = [
                    'name' => $name,
                    'expression' => $computation,
                    'type' => $aggregateBy['type'] ?? 'string',
                    'label' => $aggregateBy['label'] ?? $name,
                ];
                
                $existingComputedColumns[$name] = true;
            }
        }
    }

    /**
     * Execute the query and return results.
     */
    public function execute(): array
    {
        try {
            $startTime = microtime(true);

            // Validate query config
            $this->validateQueryConfig();

            // Build the query
            $query = $this->buildQuery();

            // Dump query
            // Utils::sqlDump($query);

            // Execute and get results
            $results = $query->get()->toArray();

            // Process results
            $processedResults = $this->processResults($results);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => true,
                'data'    => $processedResults,
                'columns' => $this->getSelectedColumns(),
                'meta'    => [
                    'total_rows'        => count($processedResults),
                    'execution_time_ms' => $executionTime,
                    'query_sql'         => $query->toSql(),
                    'query_bindings'    => $query->getBindings(),
                    'selected_columns'  => $this->getSelectedColumnNames(),
                    'joined_tables'     => array_merge($this->autoJoins, $this->manualJoins),
                    'auto_joins_used'   => $this->autoJoins,
                    'manual_joins_used' => $this->manualJoins,
                    'table_name'        => $this->queryConfig['table']['name'],
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'meta'    => [
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ],
            ];
        }
    }

    /**
     * Build the complete query.
     */
    protected function buildQuery(): Builder
    {
        $tableName = $this->queryConfig['table']['name'];
        $table     = $this->registry->getTable($tableName);

        if (!$table) {
            throw new \InvalidArgumentException("Table '{$tableName}' not found in registry");
        }

        // Start with base query
        $query = DB::table($tableName);

        // Always scope by company
        $this->applyCompanyScope($query);

        // Process auto-joins first (based on selected columns)
        $this->processAutoJoins($query, $tableName);

        // Process manual joins (from query config)
        $this->processManualJoins($query);

        // Add select clause
        $this->buildSelectClause($query);

        // Add where conditions
        $this->buildWhereClause($query);

        // Add group by
        $this->buildGroupByClause($query);

        // Add order by
        $this->buildOrderByClause($query);

        // Add limit
        $this->buildLimitClause($query);

        return $query;
    }

    protected function applyCompanyScope(Builder $query): void
    {
        $rootTable = $this->queryConfig['table']['name'];
        $uuid      = $this->resolveCompanyUuid();

        if (!$uuid) {
            throw new \RuntimeException('No active company in session; cannot scope report by company_uuid.');
        }

        $query->where("{$rootTable}.company_uuid", $uuid);
    }

    protected function resolveCompanyUuid(): ?string
    {
        $c = session('company');

        if (!$c) {
            return null;
        }

        if (is_string($c)) {
            return $c;
        }

        if (is_object($c)) {
            // Common patterns: Model { uuid }, { company_uuid }, { id }
            return $c->uuid ?? $c->company_uuid ?? $c->id ?? null;
        }

        if (is_array($c)) {
            return $c['uuid'] ?? $c['company_uuid'] ?? $c['id'] ?? null;
        }

        return null;
    }

    /**
     * Process auto-joins based on selected columns.
     */
    protected function processAutoJoins(Builder $query, string $tableName): void
    {
        $table         = $this->registry->getTable($tableName);
        $autoJoinPaths = [];

        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            if (!empty($column['auto_join_path'])) {
                // already provided by your registry as full path like "payload.pickup"
                $autoJoinPaths[] = $column['auto_join_path'];
            } elseif (Str::contains($column['name'], '.')) {
                // take all but the final segment as the relationship path
                $parts = explode('.', $column['name']);
                if (count($parts) >= 2) {
                    $relPath         = implode('.', array_slice($parts, 0, -1));
                    $autoJoinPaths[] = $relPath;
                }
            }
        }

        $this->collectAutoJoinPathsFromConditions($this->queryConfig['conditions'] ?? [], $autoJoinPaths);
        $this->collectAutoJoinPathsFromGroupBy($this->queryConfig['groupBy'] ?? [], $autoJoinPaths);
        $this->collectAutoJoinPathsFromSortBy($this->queryConfig['sortBy'] ?? [], $autoJoinPaths);

        // dedupe and sort shortest->longest so parent joins first
        $autoJoinPaths = array_values(array_unique($autoJoinPaths));
        usort($autoJoinPaths, fn ($a, $b) => substr_count($a, '.') <=> substr_count($b, '.'));

        foreach ($autoJoinPaths as $path) {
            $this->applyAutoJoinPath($query, $tableName, $path);
        }
    }

    /**
     * Collect auto-join paths from conditions recursively.
     */
    protected function collectAutoJoinPathsFromConditions(array $conditions, array &$autoJoinPaths): void
    {
        foreach ($conditions as $condition) {
            if (isset($condition['conditions'])) {
                $this->collectAutoJoinPathsFromConditions($condition['conditions'], $autoJoinPaths);
            } elseif (!empty($condition['field']['auto_join_path'])) {
                $autoJoinPaths[] = $condition['field']['auto_join_path'];
            } elseif (!empty($condition['field']['name']) && Str::contains($condition['field']['name'], '.')) {
                $parts = explode('.', $condition['field']['name']);
                if (count($parts) >= 2) {
                    $autoJoinPaths[] = implode('.', array_slice($parts, 0, -1));
                }
            }
        }
    }

    protected function collectAutoJoinPathsFromGroupBy(array $groupBy, array &$autoJoinPaths): void
    {
        foreach ($groupBy as $g) {
            if (!empty($g['groupBy']['name']) && str_contains($g['groupBy']['name'], '.')) {
                $parts = explode('.', $g['groupBy']['name']);
                if (count($parts) >= 2) {
                    $autoJoinPaths[] = implode('.', array_slice($parts, 0, -1));
                }
            }
            if (!empty($g['aggregateBy']['name']) && str_contains($g['aggregateBy']['name'], '.')) {
                $parts = explode('.', $g['aggregateBy']['name']);
                if (count($parts) >= 2) {
                    $autoJoinPaths[] = implode('.', array_slice($parts, 0, -1));
                }
            }
        }
    }

    protected function collectAutoJoinPathsFromSortBy(array $sortBy, array &$autoJoinPaths): void
    {
        foreach ($sortBy as $s) {
            if (!empty($s['column']['name']) && str_contains($s['column']['name'], '.')) {
                $parts = explode('.', $s['column']['name']);
                if (count($parts) >= 2) {
                    $autoJoinPaths[] = implode('.', array_slice($parts, 0, -1));
                }
            }
        }
    }

    protected function applyAutoJoinPath(Builder $query, string $rootTable, string $fullPath): void
    {
        // Already joined?
        if (isset($this->joinAliases[$fullPath])) {
            return;
        }

        $segments = explode('.', $fullPath);

        $currentTableOrAlias = $rootTable;
        $currentCtx          = $this->registry->getTable($rootTable);
        $cumulative          = [];

        foreach ($segments as $i => $segment) {
            $cumulative[] = $segment;
            $path         = implode('.', $cumulative);

            // Skip if this hop already joined
            if (isset($this->joinAliases[$path])) {
                $currentTableOrAlias = $this->joinAliases[$path];
                // move context to the relationship at this hop
                $currentCtx = $this->getChildContext($currentCtx, $segment);
                continue;
            }

            // Find relationship for this segment in the current context
            $relationship = $this->getRelationshipFromContext($currentCtx, $segment);
            if (!$relationship || !$relationship->isAutoJoin()) {
                // stop if not auto-joinable
                return;
            }

            // Alias: orders_payload or orders_payload_pickup
            $alias = $this->generateAliasChain($rootTable, $cumulative);

            // Join type from schema ("left", "right", "inner") – default "left"
            $joinType = $relationship->getType() ?: 'left';

            // Join: {current}.{localKey} = {alias}.{foreignKey}
            $query->join(
                "{$relationship->getTable()} as {$alias}",
                "{$currentTableOrAlias}.{$relationship->getLocalKey()}",
                '=',
                "{$alias}.{$relationship->getForeignKey()}",
                $joinType
            );

            // record
            $this->autoJoins[] = [
                'path'        => $path,
                'table'       => $relationship->getTable(),
                'alias'       => $alias,
                'type'        => $joinType,
                'local_key'   => $relationship->getLocalKey(),
                'foreign_key' => $relationship->getForeignKey(),
            ];
            $this->joinAliases[$path] = $alias;

            // advance context
            $currentTableOrAlias = $alias;
            $currentCtx          = $this->getChildContext($currentCtx, $segment);
        }
    }

    /** Resolve relationship object by name from either a Table or a Relationship context. */
    protected function getRelationshipFromContext($ctx, string $name)
    {
        if (method_exists($ctx, 'getRelationship')) {
            return $ctx->getRelationship($name);
        }
        if (method_exists($ctx, 'getAutoJoinRelationships')) {
            foreach ($ctx->getAutoJoinRelationships() as $rel) {
                if ($rel->getName() === $name) {
                    return $rel;
                }
            }
        }

        return null;
    }

    /** Move to child context after taking a relationship hop. */
    protected function getChildContext($ctx, string $name)
    {
        return $this->getRelationshipFromContext($ctx, $name);
    }

    /** Alias for a chain: orders + ['payload','pickup'] → "orders_payload_pickup" */
    protected function generateAliasChain(string $root, array $segments): string
    {
        return $root . '_' . implode('_', $segments);
    }

    /** Map "payload.pickup.street1" → ["orders_payload_pickup", "street1"] */
    protected function resolveAliasAndColumn(string $rootTable, string $columnPath): array
    {
        $parts = explode('.', $columnPath);
        if (count($parts) === 1) {
            return [$rootTable, $parts[0]];
        }

        $col          = array_pop($parts);           // "street1"
        $relPath      = implode('.', $parts);        // "payload.pickup"
        $alias        = $this->joinAliases[$relPath] ?? null;

        return [$alias ?: $rootTable, $alias ? $col : $columnPath];
    }

    /**
     * Apply an auto-join for a specific path.
     */
    protected function applyAutoJoin(Builder $query, string $tableName, string $path): void
    {
        $table        = $this->registry->getTable($tableName);
        $relationship = $table->getRelationship($path);

        if (!$relationship || !$relationship->isAutoJoin()) {
            return;
        }

        $alias    = $this->generateJoinAlias($tableName, $path);
        $joinType = $relationship->getType();

        // Apply the join
        $query->join(
            "{$relationship->getTable()} as {$alias}",
            "{$tableName}.{$relationship->getLocalKey()}",
            '=',
            "{$alias}.{$relationship->getForeignKey()}",
            $joinType
        );

        $this->autoJoins[] = [
            'path'        => $path,
            'table'       => $relationship->getTable(),
            'alias'       => $alias,
            'type'        => $joinType,
            'local_key'   => $relationship->getLocalKey(),
            'foreign_key' => $relationship->getForeignKey(),
        ];

        $this->joinAliases[$path] = $alias;
    }

    /**
     * Process manual joins from query config.
     */
    protected function processManualJoins(Builder $query): void
    {
        foreach ($this->queryConfig['joins'] ?? [] as $join) {
            $this->applyManualJoin($query, $join);
        }
    }

    /**
     * Apply a manual join.
     */
    protected function applyManualJoin(Builder $query, array $join): void
    {
        $joinTable  = $join['table'];
        $joinType   = $join['type'] ?? 'left';
        $localKey   = $join['localKey'] ?? 'uuid';
        $foreignKey = $join['foreignKey'] ?? 'uuid';
        $alias      = $join['alias'] ?? $joinTable;

        // Apply the join
        $query->join(
            "{$joinTable} as {$alias}",
            $join['localTable'] . ".{$localKey}",
            '=',
            "{$alias}.{$foreignKey}",
            $joinType
        );

        $this->manualJoins[] = [
            'table'       => $joinTable,
            'alias'       => $alias,
            'type'        => $joinType,
            'local_key'   => $localKey,
            'foreign_key' => $foreignKey,
        ];

        $this->joinAliases[$join['name'] ?? $joinTable] = $alias;
    }

    /**
     * Build the select clause.
     */
    protected function buildSelectClause(Builder $query): void
    {
        $rootTable   = $this->queryConfig['table']['name'];
        $hasGrouping = !empty($this->queryConfig['groupBy']);

        if (!$hasGrouping) {
            // existing behavior
            $selects = [];
            foreach ($this->queryConfig['columns'] ?? [] as $column) {
                $name             = $column['name'];
                $alias            = $column['alias'] ?? str_replace('.', '_', $name);
                [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $name);
                $selects[]        = "{$tblAlias}.{$col} as `{$alias}`";
            }

            // Add computed columns
            $this->buildComputedColumns($query, $selects);

            if ($selects) {
                $query->selectRaw(implode(', ', $selects));
            }

            return;
        }

        $selects = [];

        // Select grouped columns
        $groupAliases = []; // track to validate orderBy later
        foreach ($this->queryConfig['groupBy'] as $g) {
            $groupColName     = $g['groupBy']['name'];
            $alias            = $g['groupBy']['alias'] ?? str_replace('.', '_', $groupColName);
            [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $groupColName);
            $selects[]        = "{$tblAlias}.{$col} as `{$alias}`";
            $groupAliases[]   = $alias;
        }

        // Add aggregates per groupBy rule (support count/sum/avg/min/max)
        foreach ($this->queryConfig['groupBy'] as $g) {
            $fn = strtolower($g['aggregateFn']['value'] ?? '');
            if (!$fn) {
                continue;
            }

            $by = $g['aggregateBy']['full'] ?? $g['aggregateBy']['name'] ?? '*';

            if ($by === '*' || $by === 'count') {
                $expr = 'COUNT(*)';
            } else {
                // Check if this is a computed column
                $isComputed = false;
                $computedExpression = null;
                
                foreach ($this->queryConfig['computed_columns'] ?? [] as $computedColumn) {
                    if ($computedColumn['name'] === $by) {
                        $isComputed = true;
                        $computedExpression = $computedColumn['expression'] ?? '';
                        break;
                    }
                }
                
                if ($isComputed && $computedExpression) {
                    // For computed columns, use the resolved expression
                    $resolvedExpression = $this->resolveComputedColumnReferences($computedExpression, $rootTable);
                    $expr = strtoupper($fn) . "({$resolvedExpression})";
                } else {
                    // For regular columns, use table.column format
                    [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $by);
                    $expr = strtoupper($fn) . "({$tblAlias}.{$col})";
                }
            }

            $aggAlias  = $this->deriveAggregateAlias($g);
            $selects[] = "{$expr} as `{$aggAlias}`";

            // decide a type/label
            $typeMap = [
                'count'          => 'integer',
                'sum'            => 'decimal',
                'avg'            => 'decimal',
                'min'            => 'string',   // could be numeric/datetime;
                'max'            => 'string',
                'group_concat'   => 'string',
            ];
            $labelMap = [
                'count'          => 'Count',
                'sum'            => 'Sum',
                'avg'            => 'Average',
                'min'            => 'Min',
                'max'            => 'Max',
                'group_concat'   => 'Concatenate',
            ];

            $this->emittedAggregates[] = [
                'alias' => $aggAlias,
                'fn'    => $fn,
                'by'    => $by,
                'type'  => $typeMap[$fn] ?? 'decimal',
                'label' => $labelMap[$fn] ?? strtoupper($fn),
            ];
        }

        // (Optional) if user selected extra columns, drop them here OR auto-aggregate.
        // We'll drop them to stay deterministic under ONLY_FULL_GROUP_BY.

        // Add computed columns (works in both grouped and non-grouped mode)
        $this->buildComputedColumns($query, $selects);

        if ($selects) {
            $query->selectRaw(implode(', ', $selects));
        }
    }

    /**
     * Add foreign key columns to the query (for joins) without selecting them.
     */
    protected function addForeignKeyColumns(Builder $query, string $tableName): void
    {
        $table = $this->registry->getTable($tableName);

        foreach ($table->getRelationships() as $relationship) {
            $foreignKey = $relationship->getLocalKey();
            // These columns are needed for joins but not displayed
            // They're automatically included when we select from the main table
        }
    }

    /**
     * Build the where clause.
     */
    protected function buildWhereClause(Builder $query): void
    {
        if (empty($this->queryConfig['conditions'])) {
            return;
        }

        $this->processConditions($query, $this->queryConfig['conditions']);
    }

    /**
     * Process conditions recursively.
     */
    protected function processConditions(Builder $query, array $conditions, string $boolean = 'and'): void
    {
        foreach ($conditions as $condition) {
            if (isset($condition['conditions'])) {
                // Nested condition group
                $groupBoolean = $condition['boolean'] ?? 'and';
                $query->where(function ($subQuery) use ($condition, $groupBoolean) {
                    $this->processConditions($subQuery, $condition['conditions'], $groupBoolean);
                }, null, null, $boolean);
            } else {
                // Single condition
                $this->applySingleCondition($query, $condition, $boolean);
            }
        }
    }

    /**
     * Apply a single condition.
     */
    protected function applySingleCondition(Builder $query, array $condition, string $boolean = 'and'): void
    {
        $field     = $condition['field']['name'];
        $operator  = $condition['operator']['value'];
        $value     = $condition['value'];
        $tableName = $this->queryConfig['table']['name'];

        // Handle auto-join columns in conditions
        [$tblAlias, $col] = $this->resolveAliasAndColumn($tableName, $field);
        $field            = "{$tblAlias}.{$col}";

        // Apply the condition based on operator
        switch ($operator) {
            case '=':
            case '!=':
            case '>':
            case '>=':
            case '<':
            case '<=':
                $query->where($field, $operator, $value, $boolean);
                break;
            case 'like':
                $query->where($field, 'LIKE', "%{$value}%", $boolean);
                break;
            case 'not_like':
                $query->where($field, 'NOT LIKE', "%{$value}%", $boolean);
                break;
            case 'in':
                $values = is_array($value) ? $value : explode(',', $value);
                $query->whereIn($field, $values, $boolean);
                break;
            case 'not_in':
                $values = is_array($value) ? $value : explode(',', $value);
                $query->whereNotIn($field, $values, $boolean);
                break;
            case 'null':
                $query->whereNull($field, $boolean);
                break;
            case 'not_null':
                $query->whereNotNull($field, $boolean);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($field, $value, $boolean);
                }
                break;
        }
    }

    /**
     * Build the group by clause.
     */
    protected function buildGroupByClause(Builder $query): void
    {
        if (empty($this->queryConfig['groupBy'])) {
            return;
        }

        $rootTable = $this->queryConfig['table']['name'];
        $groupBy   = [];

        foreach ($this->queryConfig['groupBy'] as $g) {
            [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $g['groupBy']['name']);
            $groupBy[]        = "{$tblAlias}.{$col}";
        }

        if ($groupBy) {
            $query->groupBy($groupBy);
        }
    }

    /**
     * Build the order by clause.
     */
    protected function buildOrderByClause(Builder $query): void
    {
        if (empty($this->queryConfig['sortBy'])) {
            return;
        }

        $rootTable   = $this->queryConfig['table']['name'];
        $hasGrouping = !empty($this->queryConfig['groupBy']);

        // Build a whitelist for grouped mode
        $allowedOrderExprs = [];
        if ($hasGrouping) {
            // Grouped aliases (as emitted in buildSelectClause)
            foreach ($this->queryConfig['groupBy'] as $g) {
                $groupColName                    = $g['groupBy']['name'];
                $alias                           = $g['groupBy']['alias'] ?? str_replace('.', '_', $groupColName);
                $allowedOrderExprs["`{$alias}`"] = true;
            }
            // Aggregate aliases (as emitted in buildSelectClause)
            foreach ($this->queryConfig['groupBy'] as $g) {
                $fn = strtolower($g['aggregateFn']['value'] ?? '');
                if ($fn) {
                    $aggAliasBase                       = $g['aggregateBy']['name'] ?? $g['aggregateBy']['full'] ?? 'all';
                    $aggAlias                           = ($fn === 'count') ? "count_{$aggAliasBase}" : "{$fn}_" . str_replace('.', '_', $aggAliasBase);
                    $allowedOrderExprs["`{$aggAlias}`"] = true;
                }
            }
        }

        foreach ($this->queryConfig['sortBy'] as $s) {
            $dir     = $s['direction']['value'] ?? 'asc';
            $colName = $s['column']['name'];

            if ($hasGrouping) {
                // Try to order by a select alias first
                $alias     = $s['column']['alias'] ?? str_replace('.', '_', $colName);
                $aliasExpr = "`{$alias}`";
                if (isset($allowedOrderExprs[$aliasExpr])) {
                    $query->orderByRaw("{$aliasExpr} {$dir}");
                    continue;
                }
                // Not allowed → skip (or convert to MIN/MAX if you prefer)
                continue;
            }

            // Non-grouped mode → original behavior
            [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $colName);
            $query->orderBy("{$tblAlias}.{$col}", $dir);
        }
    }

    /**
     * Build the limit clause.
     */
    protected function buildLimitClause(Builder $query): void
    {
        if (isset($this->queryConfig['limit'])) {
            $limit  = (int) $this->queryConfig['limit'];
            $offset = (int) ($this->queryConfig['offset'] ?? 0);

            $query->limit($limit);
            if ($offset > 0) {
                $query->offset($offset);
            }
        }
    }

    /**
     * Generate a unique alias for joins.
     */
    protected function generateJoinAlias(string $tableName, string $relationshipName): string
    {
        return "{$tableName}_{$relationshipName}";
    }

    /**
     * Get selected columns information.
     */
    protected function getSelectedColumns(): array
    {
        $cols = [];

        // base: actual selected (non-aggregate) columns
        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            $cols[] = [
                'name'           => Str::snake(str_replace('.', '_', $column['name'])),
                'column_name'    => $column['name'],
                'label'          => $column['label'] ?? $column['name'],
                'type'           => $column['type'] ?? 'string',
                'auto_join_path' => $column['auto_join_path'] ?? null,
            ];
        }

        // Add computed columns
        foreach ($this->queryConfig['computed_columns'] ?? [] as $computedColumn) {
            $cols[] = [
                'name'        => $computedColumn['name'],
                'column_name' => $computedColumn['name'],
                'label'       => $computedColumn['label'] ?? $computedColumn['name'],
                'type'        => $computedColumn['type'] ?? 'string',
                'computed'    => true,
                'expression'  => $computedColumn['expression'] ?? '',
            ];
        }

        // add group keys explicitly (they might already be in columns; harmless if duplicated)
        if (!empty($this->queryConfig['groupBy'])) {
            foreach ($this->queryConfig['groupBy'] as $g) {
                $gname  = $g['groupBy']['name'];
                $galias = $g['groupBy']['alias'] ?? str_replace('.', '_', $gname);
                $cols[] = [
                    'name'           => Str::snake($galias),
                    'column_name'    => $gname,
                    'label'          => $g['groupBy']['label'] ?? $gname,
                    'type'           => $g['groupBy']['type'] ?? 'string',
                    'auto_join_path' => $g['groupBy']['auto_join_path'] ?? null,
                ];
            }
        }

        // add aggregates emitted in SELECT
        foreach ($this->emittedAggregates as $agg) {
            $cols[] = [
                'name'           => $agg['alias'],
                'column_name'    => $agg['alias'],
                'label'          => $agg['label'] . ($agg['by'] === '*' ? '' : " ({$agg['by']})"),
                'type'           => $agg['type'],
                'auto_join_path' => null,
            ];
        }

        // dedupe by 'name' to avoid duplicates if group key also appears in columns
        $uniq = [];
        $out  = [];
        foreach ($cols as $c) {
            if (!isset($uniq[$c['name']])) {
                $uniq[$c['name']] = true;
                $out[]            = $c;
            }
        }

        return $out;
    }

    /**
     * Derive an alias for aggregates.
     */
    protected function deriveAggregateAlias(array $g): string
    {
        $fn   = strtolower($g['aggregateFn']['value'] ?? '');
        $by   = $g['aggregateBy']['name'] ?? $g['aggregateBy']['full'] ?? '*';
        $base = ($by === '*' ? 'all' : str_replace('.', '_', $by));

        if ($fn === 'count') {
            return "count_{$base}";
        }

        return "{$fn}_{$base}";
    }

    /**
     * Get selected column names.
     */
    protected function getSelectedColumnNames(): array
    {
        $names = array_map(fn ($c) => $c['name'], $this->queryConfig['columns'] ?? []);

        // computed columns
        foreach ($this->queryConfig['computed_columns'] ?? [] as $computedColumn) {
            $names[] = $computedColumn['name'];
        }

        // grouped keys
        if (!empty($this->queryConfig['groupBy'])) {
            foreach ($this->queryConfig['groupBy'] as $g) {
                $gname   = $g['groupBy']['name'];
                $galias  = $g['groupBy']['alias'] ?? str_replace('.', '_', $gname);
                $names[] = $galias;
            }
        }

        // aggregate aliases
        foreach ($this->emittedAggregates as $agg) {
            $names[] = $agg['alias'];
        }

        // dedupe
        return array_values(array_unique($names));
    }

    /**
     * Build computed columns and add them to the select array.
     */
    protected function buildComputedColumns(Builder $query, array &$selects): void
    {
        if (empty($this->queryConfig['computed_columns'])) {
            return;
        }

        $tableName = $this->queryConfig['table']['name'];
        $validator = new ComputedColumnValidator($this->registry);

        foreach ($this->queryConfig['computed_columns'] as $computedColumn) {
            $name       = $computedColumn['name'] ?? '';
            $expression = $computedColumn['expression'] ?? '';

            if (empty($name) || empty($expression)) {
                throw new \InvalidArgumentException('Computed column must have both name and expression');
            }

            // Validate the expression, passing all computed columns so they can reference each other
            $validationResult = $validator->validate($expression, $tableName, $this->queryConfig['computed_columns']);
            if (!$validationResult['valid']) {
                $errors = implode('; ', $validationResult['errors']);
                throw new \InvalidArgumentException("Invalid computed column '{$name}': {$errors}");
            }

            // Resolve column references in the expression to use proper table aliases
            $resolvedExpression = $this->resolveComputedColumnReferences($expression, $tableName);

            // Add to selects
            $selects[] = "({$resolvedExpression}) as `{$name}`";
        }
    }

    /**
     * Resolve column references in computed column expressions to use proper table aliases.
     */
    protected function resolveComputedColumnReferences(string $expression, string $rootTable): string
    {
        // First, check if this expression references other computed columns and expand them
        $computedColumns = $this->queryConfig['computed_columns'] ?? [];
        $computedColumnMap = [];
        foreach ($computedColumns as $col) {
            $computedColumnMap[$col['name']] = $col['expression'];
        }

        // Recursively expand computed column references
        $maxDepth = 10; // Prevent infinite recursion
        $depth = 0;
        while ($depth < $maxDepth) {
            $changed = false;
            foreach ($computedColumnMap as $name => $expr) {
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $expression)) {
                    $expression = preg_replace('/\b' . preg_quote($name, '/') . '\b/', '(' . $expr . ')', $expression);
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
            $depth++;
        }

        // First, extract and protect string literals
        $stringLiterals = [];
        $protectedExpression = preg_replace_callback(
            "/'([^']*)'/",
            function ($matches) use (&$stringLiterals) {
                $placeholder = '___STRING_LITERAL_' . count($stringLiterals) . '___';
                $stringLiterals[$placeholder] = $matches[0]; // Keep the quotes
                return $placeholder;
            },
            $expression
        );

        // Also protect double-quoted strings
        $protectedExpression = preg_replace_callback(
            '/"([^"]*)"/',
            function ($matches) use (&$stringLiterals) {
                $placeholder = '___STRING_LITERAL_' . count($stringLiterals) . '___';
                $stringLiterals[$placeholder] = $matches[0]; // Keep the quotes
                return $placeholder;
            },
            $protectedExpression
        );

        // Now resolve column references in the protected expression
        $resolvedExpression = preg_replace_callback(
            '/\b([a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)*)\b/i',
            function ($matches) use ($rootTable) {
                $columnRef = $matches[1];

                // Skip SQL keywords and functions (comprehensive list)
                $keywords = [
                    // Date/Time Functions
                    'DATEDIFF', 'DATE_ADD', 'DATE_SUB', 'NOW', 'CURDATE', 'CURTIME',
                    'LAST_DAY', 'DAYOFWEEK', 'DAYOFMONTH', 'DAYOFYEAR', 'WEEK', 'WEEKDAY', 'QUARTER',
                    'TIMESTAMPDIFF', 'TIMESTAMPADD', 'FROM_UNIXTIME', 'UNIX_TIMESTAMP', 'STR_TO_DATE',
                    'MAKEDATE', 'ADDDATE', 'SUBDATE', 'DATE_FORMAT', 'TIME_FORMAT',
                    'DAY', 'MONTH', 'YEAR', 'HOUR', 'MINUTE', 'SECOND',
                    // String Functions
                    'CONCAT', 'CONCAT_WS', 'SUBSTRING', 'SUBSTR', 'LEFT', 'RIGHT',
                    'UPPER', 'LOWER', 'TRIM', 'LTRIM', 'RTRIM', 'LENGTH', 'CHAR_LENGTH',
                    'LPAD', 'RPAD', 'REVERSE', 'REPLACE', 'LOCATE', 'POSITION', 'INSTR', 'STRCMP',
                    // Numeric Functions
                    'ROUND', 'CEIL', 'CEILING', 'FLOOR', 'TRUNCATE', 'ABS', 'MOD',
                    'POW', 'POWER', 'SQRT', 'SIGN', 'RAND', 'PI',
                    'EXP', 'LN', 'LOG', 'LOG10', 'LOG2', 'DEGREES', 'RADIANS',
                    'SIN', 'COS', 'TAN', 'ASIN', 'ACOS', 'ATAN', 'ATAN2',
                    // Conditional/Logic
                    'COALESCE', 'IFNULL', 'NULLIF', 'IF',
                    'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
                    // Aggregate Functions
                    'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT',
                    // Type Conversion
                    'CAST', 'CONVERT',
                    // Keywords
                    'INTERVAL', 'AND', 'OR', 'NOT', 'IS', 'NULL', 'TRUE', 'FALSE',
                    'AS', 'FROM', 'WHERE', 'DIV',
                ];

                if (in_array(strtoupper($columnRef), $keywords) || is_numeric($columnRef)) {
                    return $columnRef;
                }

                // Skip string literal placeholders
                if (strpos($columnRef, '___STRING_LITERAL_') === 0) {
                    return $columnRef;
                }

                // Try to resolve the column reference
                try {
                    [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $columnRef);

                    return "{$tblAlias}.{$col}";
                } catch (\Exception $e) {
                    // If resolution fails, return as-is (might be a literal or string)
                    return $columnRef;
                }
            },
            $protectedExpression
        );

        // Restore string literals
        foreach ($stringLiterals as $placeholder => $original) {
            $resolvedExpression = str_replace($placeholder, $original, $resolvedExpression);
        }

        return $resolvedExpression;
    }

    /**
     * Process results (apply transformers, etc.).
     */
    protected function processResults(array $results): array
    {
        // Apply any column transformers here if needed
        return $results;
    }

    /**
     * Validate the query configuration.
     */
    protected function validateQueryConfig(): void
    {
        if (empty($this->queryConfig['table']['name'])) {
            throw new \InvalidArgumentException('Table name is required');
        }

        if (empty($this->queryConfig['columns']) && empty($this->queryConfig['computed_columns'])) {
            throw new \InvalidArgumentException('At least one column or computed column must be selected');
        }

        $tableName = $this->queryConfig['table']['name'];
        if (!$this->registry->isTableRegistered($tableName)) {
            throw new \InvalidArgumentException("Table '{$tableName}' is not registered");
        }

        // Validate columns
        foreach ($this->queryConfig['columns'] as $column) {
            if (!$this->registry->isColumnAllowed($tableName, $column['name'])) {
                throw new \InvalidArgumentException("Column '{$column['name']}' is not allowed for table '{$tableName}'");
            }
        }

        if (!empty($this->queryConfig['groupBy'])) {
            $groupCols = array_map(
                fn ($g) => $g['groupBy']['name'],
                $this->queryConfig['groupBy']
            );

            foreach ($this->queryConfig['columns'] as $col) {
                $isGrouped   = in_array($col['name'], $groupCols, true);
                $isComputed  = !empty($col['computed']) && $col['computed'] === true;
                if (!$isGrouped && !$isComputed) {
                    throw new \InvalidArgumentException("Column '{$col['name']}' must be grouped or aggregated when GROUP BY is used");
                }
            }
        }

        // Autostrip non grouped columns (optional) - we can add this as an option later
        // if (!empty($this->queryConfig['groupBy'])) {
        //     $groupCols = array_map(
        //         fn ($g) => $g['groupBy']['name'],
        //         $this->queryConfig['groupBy']
        //     );

        //     // Keep only grouped or computed (aggregated) columns
        //     $this->queryConfig['columns'] = array_values(array_filter(
        //         $this->queryConfig['columns'],
        //         function ($col) use ($groupCols) {
        //             $isGrouped  = in_array($col['name'], $groupCols, true);
        //             $isComputed = !empty($col['computed']); // e.g. COUNT(...), AVG(...), etc.
        //             return $isGrouped || $isComputed;
        //         }
        //     ));
        //     // (Optional) log/warn that some columns were dropped
        // }
    }
}
