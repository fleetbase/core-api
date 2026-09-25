<?php

namespace Fleetbase\Support\Reporting;

use Fleetbase\Support\Reporting\Schema\Column;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportQueryConverter
{
    /**
     * Aggregate functions a grouped report can apply.
     */
    public const AGGREGATE_FUNCTIONS = ['count', 'count_distinct', 'sum', 'avg', 'min', 'max', 'group_concat'];

    /**
     * How deep computed and expression columns may reference one another.
     */
    protected const MAX_REFERENCE_DEPTH = 10;

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
     *
     * Columns the schema declares (expression or summary columns) are left out: their SQL
     * always comes from the registry, never from the client.
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
            $isComputed  = $aggregateBy['computed'] ?? false;
            $computation = $aggregateBy['computation'] ?? $aggregateBy['expression'] ?? null;
            $name        = $aggregateBy['name'] ?? null;

            if ($isComputed && $computation && $name && !isset($existingComputedColumns[$name]) && !$this->findSchemaColumn($name)) {
                // Add to computed_columns array
                $this->queryConfig['computed_columns'][] = [
                    'name'       => $name,
                    'expression' => $computation,
                    'type'       => $aggregateBy['type'] ?? 'string',
                    'label'      => $aggregateBy['label'] ?? $name,
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
     * Export the query results in the requested format.
     */
    public function export(string $format, array $options = []): array
    {
        $result = $this->execute();

        if (!($result['success'] ?? false)) {
            return $result;
        }

        $columns = array_map(
            fn (array $column) => array_merge(['key' => $column['name']], $column),
            $result['columns'] ?? []
        );

        return (new ReportQueryExporter(
            $result['data'] ?? [],
            $columns,
            $result['meta'] ?? [],
            $this->queryConfig['table']['name'] ?? 'report'
        ))->export($format, $options);
    }

    /**
     * Get supported export formats for report results.
     */
    public function getAvailableExportFormats(): array
    {
        return ReportQueryExporter::getSupportedFormats();
    }

    /**
     * Return structural query analysis without executing the query.
     */
    public function getQueryAnalysis(): array
    {
        $joinsCount           = count($this->queryConfig['joins'] ?? []);
        $selectedColumnsCount = count($this->queryConfig['columns'] ?? []) + count($this->queryConfig['computed_columns'] ?? []);
        $conditionsCount      = $this->countConfiguredConditions($this->queryConfig['conditions'] ?? []);
        $groupByCount         = count($this->queryConfig['groupBy'] ?? []);
        $sortByCount          = count($this->queryConfig['sortBy'] ?? []);
        $complexityScore      = $joinsCount + $groupByCount + intdiv($selectedColumnsCount, 10) + intdiv($conditionsCount, 5);

        return [
            'table_name'             => $this->queryConfig['table']['name'] ?? null,
            'complexity'             => $complexityScore >= 3 ? 'complex' : ($complexityScore >= 1 ? 'moderate' : 'simple'),
            'joins_count'            => $joinsCount,
            'selected_columns_count' => $selectedColumnsCount,
            'conditions_count'       => $conditionsCount,
            'group_by_count'         => $groupByCount,
            'sort_by_count'          => $sortByCount,
            'has_limit'              => isset($this->queryConfig['limit']),
            'limit'                  => $this->queryConfig['limit'] ?? null,
        ];
    }

    /**
     * Count nested query condition leaves.
     */
    protected function countConfiguredConditions(array $conditions): int
    {
        $count = 0;

        foreach ($conditions as $condition) {
            if (isset($condition['conditions']) && is_array($condition['conditions'])) {
                $count += $this->countConfiguredConditions($condition['conditions']);

                continue;
            }

            $count++;
        }

        return $count;
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

        // Leave out soft-deleted rows of the root table
        if ($table->usesSoftDeletes()) {
            $query->whereNull("{$tableName}.deleted_at");
        }

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
        $autoJoinPaths = [];

        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            if (!empty($column['auto_join_path'])) {
                // already provided by your registry as full path like "payload.pickup"
                $autoJoinPaths[] = $column['auto_join_path'];
            }

            $this->collectJoinPathsForReference($column['name'], $autoJoinPaths);
        }

        $this->collectAutoJoinPathsFromConditions($this->queryConfig['conditions'] ?? [], $autoJoinPaths);
        $this->collectAutoJoinPathsFromGroupBy($this->queryConfig['groupBy'] ?? [], $autoJoinPaths);
        $this->collectAutoJoinPathsFromSortBy($this->queryConfig['sortBy'] ?? [], $autoJoinPaths);

        // Every computed column is selected when the report is not grouped. A grouped report
        // only uses the computed columns its group keys, aggregates, sorts and conditions
        // reference, and those were collected above; joining the rest would multiply rows.
        if (empty($this->queryConfig['groupBy'])) {
            $this->collectAutoJoinPathsFromComputedColumns($this->queryConfig['computed_columns'] ?? [], $autoJoinPaths, $tableName);
        }

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
            } elseif (!empty($condition['field']['name'])) {
                $this->collectJoinPathsForReference($condition['field']['name'], $autoJoinPaths);
            }
        }
    }

    protected function collectAutoJoinPathsFromGroupBy(array $groupBy, array &$autoJoinPaths): void
    {
        foreach ($groupBy as $g) {
            if (!empty($g['groupBy']['name'])) {
                $this->collectJoinPathsForReference($g['groupBy']['name'], $autoJoinPaths);
            }
            if (!empty($g['aggregateBy']['name']) && $g['aggregateBy']['name'] !== '*') {
                $this->collectJoinPathsForReference($g['aggregateBy']['name'], $autoJoinPaths);
            }
        }
    }

    protected function collectAutoJoinPathsFromSortBy(array $sortBy, array &$autoJoinPaths): void
    {
        foreach ($sortBy as $s) {
            if (!empty($s['column']['name'])) {
                $this->collectJoinPathsForReference($s['column']['name'], $autoJoinPaths);
            }
        }
    }

    protected function collectAutoJoinPathsFromComputedColumns(array $computedColumns, array &$autoJoinPaths, string $rootTable): void
    {
        foreach ($computedColumns as $computedColumn) {
            $expression = $computedColumn['expression'] ?? '';
            if (empty($expression)) {
                continue;
            }

            $this->collectJoinPathsFromExpression($expression, null, $autoJoinPaths);
        }
    }

    /**
     * Collect the relationship paths a column reference needs joined.
     *
     * A computed column (from the query) or an expression/summary column (from the schema)
     * contributes the joins its own expression needs, so `order_total` defined as
     * `transaction.amount / 100` joins `transaction` even though its name has no dot.
     */
    protected function collectJoinPathsForReference(string $reference, array &$autoJoinPaths, int $depth = 0): void
    {
        if ($depth > static::MAX_REFERENCE_DEPTH) {
            return;
        }

        $computed = $this->findQueryComputedColumn($reference);
        if ($computed) {
            $this->collectJoinPathsFromExpression($computed['expression'] ?? '', null, $autoJoinPaths, $depth + 1);

            return;
        }

        $schemaColumn = $this->findSchemaColumn($reference);
        if ($schemaColumn) {
            [$column, $prefix] = $schemaColumn;

            if ($prefix !== null) {
                $autoJoinPaths[] = $prefix;
            }

            if ($column->isComputed() && $column->getComputation()) {
                $this->collectJoinPathsFromExpression($column->getComputation(), $prefix, $autoJoinPaths, $depth + 1);
            }

            return;
        }

        if (str_contains($reference, '.')) {
            $autoJoinPaths[] = implode('.', array_slice(explode('.', $reference), 0, -1));
        }
    }

    /**
     * Collect the relationship paths every column reference in an expression needs joined.
     */
    protected function collectJoinPathsFromExpression(string $expression, ?string $prefix, array &$autoJoinPaths, int $depth = 0): void
    {
        $this->rewriteColumnReferences($expression, function (string $reference, bool $keywordPosition) use ($prefix, &$autoJoinPaths, $depth) {
            $path = $prefix !== null ? "{$prefix}.{$reference}" : $reference;

            if (!$this->isSqlKeyword($reference, $keywordPosition, $path)) {
                $this->collectJoinPathsForReference($path, $autoJoinPaths, $depth);
            }

            return null;
        });
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
            $localColumn   = "{$currentTableOrAlias}.{$relationship->getLocalKey()}";
            $foreignColumn = "{$alias}.{$relationship->getForeignKey()}";

            if ($relationship->usesSoftDeletes()) {
                // Constrain inside the ON clause so a LEFT join still keeps the parent row.
                $query->join(
                    "{$relationship->getTable()} as {$alias}",
                    function ($join) use ($localColumn, $foreignColumn, $alias) {
                        $join->on($localColumn, '=', $foreignColumn)->whereNull("{$alias}.deleted_at");
                    },
                    null,
                    null,
                    $joinType
                );
            } else {
                $query->join("{$relationship->getTable()} as {$alias}", $localColumn, '=', $foreignColumn, $joinType);
            }

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

        $col = array_pop($parts); // The final column name

        // Build the relationship path step by step to find the correct alias
        // For nested relationships like "relationship.nested.column", we need to:
        // 1. Check for the full path "relationship.nested"
        // 2. If not found, resolve step by step from "relationship" to "nested"

        $relPath = implode('.', $parts);

        // Check if we have a direct alias for the full relationship path
        if (isset($this->joinAliases[$relPath])) {
            return [$this->joinAliases[$relPath], $col];
        }

        // If not, try to resolve it step by step
        // Walk through each segment and use the longest matching path

        $currentTable = $rootTable;
        $pathSegments = [];

        // Loop through all segments and keep updating currentTable with the last successfully resolved alias
        foreach ($parts as $segment) {
            $pathSegments[] = $segment;
            $currentPath    = implode('.', $pathSegments);

            if (isset($this->joinAliases[$currentPath])) {
                $currentTable = $this->joinAliases[$currentPath];
            }
            // Don't break - continue to check if there's a longer path that matches
        }

        // If we resolved at least part of the path, use that table alias
        if ($currentTable !== $rootTable) {
            return [$currentTable, $col];
        }

        // Fallback: return as-is if we couldn't resolve
        return [$rootTable, $columnPath];
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

        // If the joined table is itself tenant-scoped (declares a company_uuid column in
        // the registry), constrain the join to the active company. This is applied inside
        // the JOIN ON clause (not a WHERE) so LEFT joins keep their unmatched-row semantics
        // while still never exposing another tenant's rows through the join.
        $joinTableSchema  = $this->registry->getTable($joinTable);
        $scopeJoinCompany = $joinTableSchema && $joinTableSchema->hasColumn('company_uuid')
            ? $this->resolveCompanyUuid()
            : null;

        if ($scopeJoinCompany !== null) {
            $localColumn = $join['localTable'] . ".{$localKey}";
            $foreignRef  = "{$alias}.{$foreignKey}";
            $query->join(
                "{$joinTable} as {$alias}",
                function ($joinClause) use ($localColumn, $foreignRef, $alias, $scopeJoinCompany) {
                    $joinClause->on($localColumn, '=', $foreignRef)
                        ->where("{$alias}.company_uuid", '=', $scopeJoinCompany);
                },
                null,
                null,
                $joinType
            );
        } else {
            // Apply the join
            $query->join(
                "{$joinTable} as {$alias}",
                $join['localTable'] . ".{$localKey}",
                '=',
                "{$alias}.{$foreignKey}",
                $joinType
            );
        }

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
        $hasGrouping = !empty($this->queryConfig['groupBy']);

        if (!$hasGrouping) {
            $selects = [];
            foreach ($this->queryConfig['columns'] ?? [] as $column) {
                $name      = $column['name'];
                $alias     = $column['alias'] ?? str_replace('.', '_', $name);
                $selects[] = $this->columnSql($name) . " as `{$alias}`";
            }

            // Add computed columns
            $this->buildComputedColumns($query, $selects);

            if ($selects) {
                $query->selectRaw(implode(', ', $selects));
            }

            return;
        }

        $selects   = [];
        $groupKeys = [];

        // Select grouped columns
        foreach ($this->queryConfig['groupBy'] as $g) {
            $groupColName = $g['groupBy']['name'];
            $alias        = $g['groupBy']['alias'] ?? str_replace('.', '_', $groupColName);

            if (isset($groupKeys[$alias])) {
                continue;
            }

            $selects[]         = $this->columnSql($groupColName) . " as `{$alias}`";
            $groupKeys[$alias] = true;
        }

        // Summary columns (e.g. "Total Orders") aggregate on their own and sit beside the group keys.
        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            $alias = $column['alias'] ?? str_replace('.', '_', $column['name']);

            if (!isset($groupKeys[$alias]) && $this->isAggregateColumn($column['name'])) {
                $selects[]         = $this->columnSql($column['name']) . " as `{$alias}`";
                $groupKeys[$alias] = true;
            }
        }

        // Add aggregates per groupBy rule (support count/count_distinct/sum/avg/min/max/group_concat)
        foreach ($this->queryConfig['groupBy'] as $g) {
            $fn = strtolower($g['aggregateFn']['value'] ?? '');
            if (!$fn) {
                continue;
            }

            $by       = $g['aggregateBy']['full'] ?? $g['aggregateBy']['name'] ?? '*';
            $aggAlias = $this->deriveAggregateAlias($g);

            if (isset($groupKeys[$aggAlias])) {
                continue;
            }

            $selects[]            = $this->aggregateExpression($fn, $by) . " as `{$aggAlias}`";
            $groupKeys[$aggAlias] = true;

            // decide a type/label
            $typeMap = [
                'count'          => 'integer',
                'count_distinct' => 'integer',
                'sum'            => 'decimal',
                'avg'            => 'decimal',
                'min'            => 'string',   // could be numeric/datetime;
                'max'            => 'string',
                'group_concat'   => 'string',
            ];
            $labelMap = [
                'count'          => 'Count',
                'count_distinct' => 'Distinct Count',
                'sum'            => 'Sum',
                'avg'            => 'Average',
                'min'            => 'Min',
                'max'            => 'Max',
                'group_concat'   => 'Concatenate',
            ];

            $this->emittedAggregates[] = [
                'alias'    => $aggAlias,
                'fn'       => $fn,
                'by'       => $by,
                'by_label' => $by === '*' ? null : ($g['aggregateBy']['label'] ?? null),
                'type'     => $typeMap[$fn] ?? 'decimal',
                'label'    => $labelMap[$fn] ?? strtoupper($fn),
            ];
        }

        // Other selected columns are neither grouped nor aggregated; validateQueryConfig()
        // rejects them so the query stays deterministic under ONLY_FULL_GROUP_BY.

        if ($selects) {
            $query->selectRaw(implode(', ', $selects));
        }
    }

    /**
     * Build the SQL for one aggregate of a grouped report.
     */
    protected function aggregateExpression(string $fn, string $by): string
    {
        if ($by === '*' || $by === 'count') {
            return 'COUNT(*)';
        }

        if ($this->isAggregateColumn($by)) {
            throw new \InvalidArgumentException("Column '{$by}' is already a summary value and cannot be aggregated again");
        }

        $sql = $this->columnSql($by);

        if ($fn === 'count_distinct') {
            return "COUNT(DISTINCT {$sql})";
        }

        return strtoupper($fn) . "({$sql})";
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
        $fieldName = $condition['field']['name'];
        $operator  = $condition['operator']['value'];
        $value     = $condition['value'] ?? null;

        if ($this->isAggregateColumn($fieldName)) {
            throw new \InvalidArgumentException("Column '{$fieldName}' is a summary value and cannot be used as a filter");
        }

        // Plain columns stay as identifiers (so the grammar quotes them); expression and
        // computed columns are filtered on their SQL expression.
        $sql   = $this->columnSql($fieldName);
        $field = $this->isExpressionReference($fieldName) ? DB::raw($sql) : $sql;

        // Apply the condition based on operator
        switch ($operator) {
            case 'eq':
            case '=':
                $query->where($field, '=', $value, $boolean);
                break;
            case 'neq':
            case '!=':
                $query->where($field, '!=', $value, $boolean);
                break;
            case 'gt':
            case '>':
                $query->where($field, '>', $value, $boolean);
                break;
            case 'gte':
            case '>=':
                $query->where($field, '>=', $value, $boolean);
                break;
            case 'lt':
            case '<':
                $query->where($field, '<', $value, $boolean);
                break;
            case 'lte':
            case '<=':
                $query->where($field, '<=', $value, $boolean);
                break;
            case 'like':
            case 'contains':
                $query->where($field, 'LIKE', "%{$value}%", $boolean);
                break;
            case 'not_like':
                $query->where($field, 'NOT LIKE', "%{$value}%", $boolean);
                break;
            case 'starts_with':
                $query->where($field, 'LIKE', "{$value}%", $boolean);
                break;
            case 'ends_with':
                $query->where($field, 'LIKE', "%{$value}", $boolean);
                break;
            case 'in':
                $values = is_array($value) ? $value : array_map('trim', explode(',', $value));
                $query->whereIn($field, $values, $boolean);
                break;
            case 'not_in':
                $values = is_array($value) ? $value : array_map('trim', explode(',', $value));
                $query->whereNotIn($field, $values, $boolean);
                break;
            case 'is_null':
            case 'null':
                $query->whereNull($field, $boolean);
                break;
            case 'is_not_null':
            case 'not_null':
                $query->whereNotNull($field, $boolean);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($field, $value, $boolean);
                }
                break;
            case 'not_between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereNotBetween($field, $value, $boolean);
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

        $groupBy = [];

        foreach ($this->queryConfig['groupBy'] as $g) {
            $groupBy[] = $this->columnSql($g['groupBy']['name']);
        }

        $query->groupByRaw(implode(', ', array_values(array_unique($groupBy))));
    }

    /**
     * Build the order by clause.
     */
    protected function buildOrderByClause(Builder $query): void
    {
        if (empty($this->queryConfig['sortBy'])) {
            return;
        }

        $hasGrouping       = !empty($this->queryConfig['groupBy']);
        $allowedOrderExprs = $hasGrouping ? $this->groupedSelectAliases() : [];

        foreach ($this->queryConfig['sortBy'] as $s) {
            $dir     = strtolower((string) ($s['direction']['value'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $colName = $s['column']['name'];

            if ($hasGrouping) {
                // Only a group key or an aggregate can be ordered by in a grouped report.
                $alias = $s['column']['alias'] ?? str_replace('.', '_', $colName);
                if (isset($allowedOrderExprs[$alias])) {
                    $query->orderByRaw("`{$alias}` {$dir}");
                }

                continue;
            }

            if ($this->isExpressionReference($colName)) {
                $query->orderByRaw($this->columnSql($colName) . " {$dir}");
                continue;
            }

            [$tblAlias, $col] = $this->resolveAliasAndColumn($this->queryConfig['table']['name'], $colName);
            $query->orderBy("{$tblAlias}.{$col}", $dir);
        }
    }

    /**
     * The select aliases of a grouped report: group keys, summary columns and aggregates.
     */
    protected function groupedSelectAliases(): array
    {
        $aliases = [];

        foreach ($this->queryConfig['groupBy'] ?? [] as $g) {
            $groupColName                                                            = $g['groupBy']['name'];
            $aliases[$g['groupBy']['alias'] ?? str_replace('.', '_', $groupColName)] = true;

            if (!empty($g['aggregateFn']['value'])) {
                $aliases[$this->deriveAggregateAlias($g)] = true;
            }
        }

        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            if ($this->isAggregateColumn($column['name'])) {
                $aliases[$column['alias'] ?? str_replace('.', '_', $column['name'])] = true;
            }
        }

        return $aliases;
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
                'label'          => $agg['label'] . ($agg['by'] === '*' ? '' : ' (' . ($agg['by_label'] ?? $agg['by']) . ')'),
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

        foreach ($this->queryConfig['computed_columns'] as $computedColumn) {
            $name = $computedColumn['name'] ?? '';

            $this->validateComputedColumn($computedColumn);

            // Note: Auto-joins for computed column relationships are now created earlier in processAutoJoins()
            // This ensures joins exist before aggregate expressions are resolved

            // Resolve column references in the expression to use proper table aliases
            $resolvedExpression = $this->resolveComputedColumnReferences($computedColumn['expression'], $this->queryConfig['table']['name']);

            // Add to selects
            $selects[] = "({$resolvedExpression}) as `{$name}`";
        }
    }

    /**
     * Validate a computed column's name and expression.
     */
    protected function validateComputedColumn(array $computedColumn): void
    {
        $name       = $computedColumn['name'] ?? '';
        $expression = $computedColumn['expression'] ?? '';

        if (empty($name) || empty($expression)) {
            throw new \InvalidArgumentException('Computed column must have both name and expression');
        }

        // The name is interpolated raw as the select alias.
        if (!$this->isSafeSqlIdentifier((string) $name)) {
            throw new \InvalidArgumentException("Invalid computed column name '{$name}'");
        }

        // Validate the expression, passing all computed columns so they can reference each other
        $validator        = new ComputedColumnValidator($this->registry);
        $validationResult = $validator->validate($expression, $this->queryConfig['table']['name'], $this->queryConfig['computed_columns'] ?? []);
        if (!$validationResult['valid']) {
            $errors = implode('; ', $validationResult['errors']);
            throw new \InvalidArgumentException("Invalid computed column '{$name}': {$errors}");
        }
    }

    /**
     * Resolve column references in computed column expressions to use proper table aliases.
     */
    protected function resolveComputedColumnReferences(string $expression, string $rootTable): string
    {
        return $this->resolveExpression($expression, null);
    }

    /**
     * Resolve every column reference in an expression to SQL.
     *
     * With a `$prefix` (the relationship path that declares an expression column), bare
     * column names resolve against that relationship's join alias instead of the root table.
     */
    protected function resolveExpression(string $expression, ?string $prefix, int $depth = 0): string
    {
        return $this->rewriteColumnReferences($expression, function (string $reference, bool $keywordPosition) use ($prefix, $depth) {
            $path = $prefix !== null ? "{$prefix}.{$reference}" : $reference;

            if ($this->isSqlKeyword($reference, $keywordPosition, $path)) {
                return null;
            }

            return $this->columnSql($path, $depth + 1);
        });
    }

    /**
     * Resolve a column reference to the SQL that reads it.
     *
     * - a computed column from the query resolves to its (parenthesised) expression;
     * - an expression or summary column declared in the schema resolves to its computation,
     *   with bare names read from the table or relationship that declares it;
     * - anything else is a physical column on the root table or a joined relationship.
     */
    protected function columnSql(string $reference, int $depth = 0): string
    {
        if ($depth > static::MAX_REFERENCE_DEPTH) {
            throw new \InvalidArgumentException("Column reference '{$reference}' is circular or nested too deeply");
        }

        $computed = $this->findQueryComputedColumn($reference);
        if ($computed) {
            return '(' . $this->resolveExpression($computed['expression'] ?? '', null, $depth) . ')';
        }

        $schemaColumn = $this->findSchemaColumn($reference);
        if ($schemaColumn && $schemaColumn[0]->isComputed() && $schemaColumn[0]->getComputation()) {
            return '(' . $this->resolveExpression($schemaColumn[0]->getComputation(), $schemaColumn[1], $depth) . ')';
        }

        [$tblAlias, $col] = $this->resolveAliasAndColumn($this->queryConfig['table']['name'], $reference);

        return "{$tblAlias}.{$col}";
    }

    /**
     * Whether a reference resolves to an SQL expression rather than a physical column.
     */
    protected function isExpressionReference(string $reference): bool
    {
        if ($this->findQueryComputedColumn($reference)) {
            return true;
        }

        $schemaColumn = $this->findSchemaColumn($reference);

        return $schemaColumn !== null && $schemaColumn[0]->isComputed() && $schemaColumn[0]->getComputation() !== null;
    }

    /**
     * Whether a reference is a summary value that aggregates rows (e.g. `COUNT(id)`).
     */
    protected function isAggregateColumn(string $reference): bool
    {
        $computed = $this->findQueryComputedColumn($reference);
        if ($computed) {
            return Column::isAggregateExpression($computed['expression'] ?? '');
        }

        $schemaColumn = $this->findSchemaColumn($reference);

        return $schemaColumn !== null && $schemaColumn[0]->isAggregate();
    }

    /**
     * Find a computed column defined by the query.
     */
    protected function findQueryComputedColumn(string $name): ?array
    {
        foreach ($this->queryConfig['computed_columns'] ?? [] as $computedColumn) {
            if (($computedColumn['name'] ?? null) === $name) {
                return $computedColumn;
            }
        }

        return null;
    }

    /**
     * Find a column the schema declares, on the root table or along an auto-join path.
     *
     * @return array{0: Column, 1: ?string}|null the column and the relationship path that declares it
     */
    protected function findSchemaColumn(string $path): ?array
    {
        $table = $this->registry->getTable($this->queryConfig['table']['name'] ?? '');
        if (!$table) {
            return null;
        }

        $segments = explode('.', $path);
        $name     = array_pop($segments);

        if (!$segments) {
            $column = $table->getColumn($name);

            return $column ? [$column, null] : null;
        }

        $context = $table;
        foreach ($segments as $segment) {
            $context = $this->getRelationshipFromContext($context, $segment);
            if (!$context) {
                return null;
            }
        }

        $column = $context->getColumn($name);

        return $column ? [$column, implode('.', $segments)] : null;
    }

    /**
     * Whether an identifier in an expression is an SQL keyword rather than a column.
     *
     * Cast types and interval units (DATE, TIME, YEAR, DAY, ...) are only keywords where
     * they cannot be a column: right after `AS` or `INTERVAL <n>`, or when no column of
     * that name exists.
     */
    protected function isSqlKeyword(string $identifier, bool $keywordPosition, string $path): bool
    {
        $upper = strtoupper($identifier);

        if (in_array($upper, ComputedColumnValidator::SQL_KEYWORDS, true)) {
            return true;
        }

        if (!in_array($upper, ComputedColumnValidator::CONTEXTUAL_KEYWORDS, true)) {
            return false;
        }

        if ($keywordPosition) {
            return true;
        }

        return !$this->findQueryComputedColumn($path) && !$this->findSchemaColumn($path);
    }

    /**
     * Rewrite each column reference in an SQL expression.
     *
     * String literals, function names and numbers are left alone. The callback receives the
     * reference and whether it sits in a keyword position (after `AS` or `INTERVAL <n>`),
     * and returns its replacement, or null to keep it.
     */
    protected function rewriteColumnReferences(string $expression, callable $rewrite): string
    {
        $placeholders = [];
        $protect      = function (string $text) use (&$placeholders): string {
            $placeholder                = '___PROTECTED_' . count($placeholders) . '___';
            $placeholders[$placeholder] = $text;

            return $placeholder;
        };

        // String literals first, so nothing inside them is mistaken for a column or function
        $protected = (string) preg_replace_callback('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', fn ($m) => $protect($m[0]), $expression);

        // Then function names (an identifier followed by an opening parenthesis)
        $protected = (string) preg_replace_callback('/\b([A-Z_][A-Z0-9_]*)(\s*\()/i', fn ($m) => $protect($m[1]) . $m[2], $protected);

        $rewritten = (string) preg_replace_callback(
            '/\b([a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)*)\b/i',
            function ($m) use ($rewrite, $protected) {
                [$reference, $offset] = $m[1];

                if (str_starts_with($reference, '___PROTECTED_')) {
                    return $reference;
                }

                $before          = substr($protected, 0, $offset);
                $keywordPosition = (bool) preg_match('/\bAS\s+$/i', $before) || (bool) preg_match('/\bINTERVAL\s+\S+\s+$/i', $before);

                return $rewrite($reference, $keywordPosition) ?? $reference;
            },
            $protected,
            -1,
            $count,
            PREG_OFFSET_CAPTURE
        );

        return strtr($rewritten, $placeholders);
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
        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            if (!$this->isConfiguredColumnAllowed($tableName, $column['name'])) {
                throw new \InvalidArgumentException("Column '{$column['name']}' is not allowed for table '{$tableName}'");
            }

            // User-provided aliases are interpolated raw into `... as `{$alias}`` in
            // buildSelectClause(), so an unchecked alias (e.g. containing a backtick)
            // is a SQL-injection vector.
            if (isset($column['alias']) && !$this->isSafeSqlIdentifier((string) $column['alias'])) {
                throw new \InvalidArgumentException("Invalid column alias '{$column['alias']}'");
            }
        }

        // Computed columns are validated up front: a grouped report resolves them inside
        // aggregates and group keys without ever selecting them on their own.
        foreach ($this->queryConfig['computed_columns'] ?? [] as $computedColumn) {
            $this->validateComputedColumn($computedColumn);
        }

        foreach ($this->queryConfig['groupBy'] ?? [] as $g) {
            $groupAlias = $g['groupBy']['alias'] ?? null;
            if ($groupAlias !== null && !$this->isSafeSqlIdentifier((string) $groupAlias)) {
                throw new \InvalidArgumentException("Invalid group-by alias '{$groupAlias}'");
            }

            $this->assertReferenceAllowed($g['groupBy']['name'] ?? '', 'Group by column');

            if ($this->isAggregateColumn($g['groupBy']['name'])) {
                throw new \InvalidArgumentException("Column '{$g['groupBy']['name']}' is a summary value and cannot be grouped by");
            }

            $fn = strtolower($g['aggregateFn']['value'] ?? '');
            if ($fn !== '' && !in_array($fn, static::AGGREGATE_FUNCTIONS, true)) {
                throw new \InvalidArgumentException("Aggregate function '{$fn}' is not supported");
            }

            $by = $g['aggregateBy']['full'] ?? $g['aggregateBy']['name'] ?? '*';
            if ($fn !== '' && $by !== '*' && $by !== 'count') {
                $this->assertReferenceAllowed($by, 'Aggregate column');
            }
        }

        foreach ($this->queryConfig['sortBy'] ?? [] as $s) {
            $sortColumn = $s['column']['name'] ?? '';
            if (empty($this->queryConfig['groupBy'])) {
                $this->assertReferenceAllowed($sortColumn, 'Sort column');
            } elseif (!$this->isSafeSqlIdentifier((string) ($s['column']['alias'] ?? str_replace('.', '_', $sortColumn)))) {
                throw new \InvalidArgumentException("Invalid sort column '{$sortColumn}'");
            }
        }

        $this->validateConditionReferences($this->queryConfig['conditions'] ?? []);

        // Validate manual joins: the join target must be a registered table, and every
        // identifier interpolated raw into the JOIN clause (table/alias/keys/localTable)
        // must be a safe SQL identifier. Without this, applyManualJoin() would splice
        // arbitrary attacker-controlled tables and identifiers directly into SQL.
        foreach ($this->queryConfig['joins'] ?? [] as $join) {
            $joinTable = $join['table'] ?? null;

            if (!$joinTable || !$this->registry->isTableRegistered($joinTable)) {
                throw new \InvalidArgumentException("Join table '" . ($joinTable ?? '') . "' is not registered");
            }

            foreach (['table', 'alias', 'name', 'localTable', 'localKey', 'foreignKey'] as $identifierKey) {
                if (isset($join[$identifierKey]) && $join[$identifierKey] !== ''
                    && !$this->isSafeSqlIdentifier((string) $join[$identifierKey])) {
                    throw new \InvalidArgumentException("Invalid identifier '{$join[$identifierKey]}' in join configuration");
                }
            }
        }

        if (!empty($this->queryConfig['groupBy'])) {
            $groupCols = array_map(
                fn ($g) => $g['groupBy']['name'],
                $this->queryConfig['groupBy']
            );

            // A column picked only to be aggregated (e.g. the "distance" in SUM(distance)) is fine.
            $aggregatedCols = [];
            foreach ($this->queryConfig['groupBy'] as $g) {
                if (!empty($g['aggregateFn']['value'])) {
                    $aggregatedCols[] = $g['aggregateBy']['name'] ?? null;
                    $aggregatedCols[] = $g['aggregateBy']['full'] ?? null;
                }
            }

            foreach ($this->queryConfig['columns'] ?? [] as $col) {
                $isGrouped    = in_array($col['name'], $groupCols, true);
                $isAggregated = in_array($col['name'], $aggregatedCols, true) || $this->isAggregateColumn($col['name']);
                if (!$isGrouped && !$isAggregated) {
                    throw new \InvalidArgumentException("Column '{$col['name']}' must be grouped or aggregated when GROUP BY is used");
                }
            }
        } else {
            // Without grouping, summary columns (e.g. "Total Orders") collapse the result to one
            // row, so they cannot sit beside per-row columns.
            $columns    = $this->queryConfig['columns'] ?? [];
            $summary    = array_filter($columns, fn ($col) => $this->isAggregateColumn($col['name']));
            $perRow     = array_filter($columns, fn ($col) => !$this->isAggregateColumn($col['name']));
            if ($summary && $perRow) {
                $summaryNames = implode(', ', array_map(fn ($col) => $col['name'], $summary));
                throw new \InvalidArgumentException("Summary columns ({$summaryNames}) can only be combined with other columns when the report is grouped");
            }
        }
    }

    /**
     * Assert that a referenced column is a computed column of the query or an allowed schema column.
     */
    protected function assertReferenceAllowed(string $reference, string $context): void
    {
        if ($this->findQueryComputedColumn($reference) || $this->isConfiguredColumnAllowed($this->queryConfig['table']['name'], $reference)) {
            return;
        }

        throw new \InvalidArgumentException("{$context} '{$reference}' is not allowed for table '{$this->queryConfig['table']['name']}'");
    }

    /**
     * Assert that every condition field is an allowed reference.
     */
    protected function validateConditionReferences(array $conditions): void
    {
        foreach ($conditions as $condition) {
            if (isset($condition['conditions'])) {
                $this->validateConditionReferences($condition['conditions']);

                continue;
            }

            $this->assertReferenceAllowed($condition['field']['name'] ?? '', 'Condition column');
        }
    }

    /**
     * Whether a string is a safe, bare SQL identifier (letters, digits and underscores,
     * starting with a letter or underscore). Used to guard values that are interpolated
     * raw into SQL — manual-join tables/aliases/keys and select aliases — rather than
     * passed as bound parameters.
     */
    protected function isSafeSqlIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier);
    }

    protected function isConfiguredColumnAllowed(string $tableName, string $columnName): bool
    {
        if ($this->registry->isColumnAllowed($tableName, $columnName)) {
            return true;
        }

        if (!str_contains($columnName, '.')) {
            return false;
        }

        [$joinAlias, $joinedColumn] = explode('.', $columnName, 2);

        foreach ($this->queryConfig['joins'] ?? [] as $join) {
            $joinTable = $join['table'] ?? null;
            $aliases   = array_filter([
                $join['name'] ?? null,
                $join['alias'] ?? null,
                $joinTable,
            ]);

            if ($joinTable && in_array($joinAlias, $aliases, true)) {
                return $this->registry->isColumnAllowed($joinTable, $joinedColumn);
            }
        }

        return false;
    }

    /**
     * Extract relationship paths from a computed column expression.
     *
     * This method parses the expression to find column references with nested relationships
     * and returns the relationship paths (e.g., "relationship.nested").
     *
     * @param string $expression The computed column expression
     * @param string $rootTable  The root table name (not used currently but kept for consistency)
     *
     * @return array Array of relationship paths
     */
    protected function extractRelationshipPathsFromExpression(string $expression, string $rootTable): array
    {
        $paths = [];
        $this->collectJoinPathsFromExpression($expression, null, $paths);

        return array_values(array_unique($paths));
    }

    /**
     * Extract relationship paths from a computed column expression and create necessary joins.
     *
     * This method parses the expression to find column references with nested relationships
     * and ensures that all necessary auto-joins are created for the relationship paths.
     *
     * @deprecated This method is now redundant as join creation happens in processAutoJoins
     */
    protected function createJoinsForComputedColumn(Builder $query, string $expression, string $rootTable): void
    {
        // This method is now a no-op since joins are created earlier in processAutoJoins
        // Keeping it for backward compatibility but it does nothing
        return;
    }
}
