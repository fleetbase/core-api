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
    protected array $autoJoins   = [];
    protected array $manualJoins = [];
    protected array $joinAliases = [];
    protected int $aliasCounter  = 0;

    public function __construct(ReportSchemaRegistry $registry, array $queryConfig)
    {
        $this->registry    = $registry;
        $this->queryConfig = $queryConfig;
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
            // dd($results);

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
            if (!empty($g['name']) && str_contains($g['name'], '.')) {
                $parts = explode('.', $g['name']);
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
        $selects   = [];
        $rootTable = $this->queryConfig['table']['name'];

        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            $name  = $column['name'];
            // Optional: normalize aliases to avoid dots in JSON keys
            $alias = $column['alias'] ?? str_replace('.', '_', $name);

            [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $name);
            $selects[]        = "{$tblAlias}.{$col} as `{$alias}`";
        }

        // NOTE: nothing to add in addForeignKeyColumns() right now (safe to keep as-is)
        // Always include foreign key columns for joins (but don't select them)
        // $this->addForeignKeyColumns($query, $rootTable);

        if (!empty($selects)) {
            // Use selectRaw to pass the comma-joined expression list
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
            [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $g['name']);
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

        $rootTable = $this->queryConfig['table']['name'];

        foreach ($this->queryConfig['sortBy'] as $s) {
            $dir              = $s['direction']['value'] ?? 'asc';
            [$tblAlias, $col] = $this->resolveAliasAndColumn($rootTable, $s['column']['name']);
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
        $columns = [];

        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            $columns[] = [
                'name'                  => Str::snake(str_replace('.', '_', $column['name'])),
                'column_name'           => $column['name'],
                'label'                 => $column['label'] ?? $column['name'],
                'type'                  => $column['type'] ?? 'string',
                'auto_join_path'        => $column['auto_join_path'] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * Get selected column names.
     */
    protected function getSelectedColumnNames(): array
    {
        return array_map(function ($column) {
            return $column['name'];
        }, $this->queryConfig['columns'] ?? []);
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

        if (empty($this->queryConfig['columns'])) {
            throw new \InvalidArgumentException('At least one column must be selected');
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
    }
}
