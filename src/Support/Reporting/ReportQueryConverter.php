<?php

namespace Fleetbase\Support\Reporting;

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

    /**
     * Process auto-joins based on selected columns.
     */
    protected function processAutoJoins(Builder $query, string $tableName): void
    {
        $table         = $this->registry->getTable($tableName);
        $autoJoinPaths = [];

        // Collect auto-join paths from selected columns
        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            if (isset($column['auto_join_path'])) {
                $autoJoinPaths[] = $column['auto_join_path'];
            } elseif (Str::contains($column['name'], '.')) {
                // Extract auto-join path from column name
                $parts = explode('.', $column['name']);
                if (count($parts) >= 2) {
                    $relationshipName = $parts[0];
                    $relationship     = $table->getRelationship($relationshipName);
                    if ($relationship && $relationship->isAutoJoin()) {
                        $autoJoinPaths[] = $relationshipName;
                    }
                }
            }
        }

        // Also check conditions for auto-join paths
        $this->collectAutoJoinPathsFromConditions($this->queryConfig['conditions'] ?? [], $autoJoinPaths);

        // Remove duplicates
        $autoJoinPaths = array_unique($autoJoinPaths);

        // Apply auto-joins
        foreach ($autoJoinPaths as $path) {
            $this->applyAutoJoin($query, $tableName, $path);
        }
    }

    /**
     * Collect auto-join paths from conditions recursively.
     */
    protected function collectAutoJoinPathsFromConditions(array $conditions, array &$autoJoinPaths): void
    {
        foreach ($conditions as $condition) {
            if (isset($condition['conditions'])) {
                // Nested conditions
                $this->collectAutoJoinPathsFromConditions($condition['conditions'], $autoJoinPaths);
            } elseif (isset($condition['field']['auto_join_path'])) {
                $autoJoinPaths[] = $condition['field']['auto_join_path'];
            } elseif (isset($condition['field']['name']) && Str::contains($condition['field']['name'], '.')) {
                $parts = explode('.', $condition['field']['name']);
                if (count($parts) >= 2) {
                    $autoJoinPaths[] = $parts[0];
                }
            }
        }
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
        $selectColumns = [];
        $tableName     = $this->queryConfig['table']['name'];

        foreach ($this->queryConfig['columns'] ?? [] as $column) {
            $columnName  = $column['name'];
            $columnAlias = $column['alias'] ?? $columnName;

            if (Str::contains($columnName, '.')) {
                // Handle auto-join or manual join columns
                $parts             = explode('.', $columnName, 2);
                $relationshipName  = $parts[0];
                $relatedColumnName = $parts[1];

                if (isset($this->joinAliases[$relationshipName])) {
                    $alias           = $this->joinAliases[$relationshipName];
                    $selectColumns[] = "{$alias}.{$relatedColumnName} as `{$columnAlias}`";
                } else {
                    // Fallback to direct column reference
                    $selectColumns[] = "{$columnName} as `{$columnAlias}`";
                }
            } else {
                // Direct table column
                $selectColumns[] = "{$tableName}.{$columnName} as `{$columnAlias}`";
            }
        }

        // Always include foreign key columns for joins (but don't select them)
        $this->addForeignKeyColumns($query, $tableName);

        if (!empty($selectColumns)) {
            $query->select(DB::raw(implode(', ', $selectColumns)));
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
        if (Str::contains($field, '.')) {
            $parts             = explode('.', $field, 2);
            $relationshipName  = $parts[0];
            $relatedColumnName = $parts[1];

            if (isset($this->joinAliases[$relationshipName])) {
                $alias = $this->joinAliases[$relationshipName];
                $field = "{$alias}.{$relatedColumnName}";
            }
        } else {
            $field = "{$tableName}.{$field}";
        }

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

        $groupByColumns = [];
        $tableName      = $this->queryConfig['table']['name'];

        foreach ($this->queryConfig['groupBy'] as $groupBy) {
            $columnName = $groupBy['name'];

            if (Str::contains($columnName, '.')) {
                $parts             = explode('.', $columnName, 2);
                $relationshipName  = $parts[0];
                $relatedColumnName = $parts[1];

                if (isset($this->joinAliases[$relationshipName])) {
                    $alias            = $this->joinAliases[$relationshipName];
                    $groupByColumns[] = "{$alias}.{$relatedColumnName}";
                } else {
                    $groupByColumns[] = $columnName;
                }
            } else {
                $groupByColumns[] = "{$tableName}.{$columnName}";
            }
        }

        if (!empty($groupByColumns)) {
            $query->groupBy($groupByColumns);
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

        $tableName = $this->queryConfig['table']['name'];

        foreach ($this->queryConfig['sortBy'] as $sortBy) {
            $columnName = $sortBy['column']['name'];
            $direction  = $sortBy['direction']['value'] ?? 'asc';

            if (Str::contains($columnName, '.')) {
                $parts             = explode('.', $columnName, 2);
                $relationshipName  = $parts[0];
                $relatedColumnName = $parts[1];

                if (isset($this->joinAliases[$relationshipName])) {
                    $alias = $this->joinAliases[$relationshipName];
                    $query->orderBy("{$alias}.{$relatedColumnName}", $direction);
                } else {
                    $query->orderBy($columnName, $direction);
                }
            } else {
                $query->orderBy("{$tableName}.{$columnName}", $direction);
            }
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
                'name'           => $column['name'],
                'label'          => $column['label'] ?? $column['name'],
                'type'           => $column['type'] ?? 'string',
                'auto_join_path' => $column['auto_join_path'] ?? null,
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
