<?php

namespace Fleetbase\Support\Reporting;

use Illuminate\Support\Facades\Validator;

class ReportQueryValidator
{
    protected ReportSchemaRegistry $registry;
    protected array $errors   = [];
    protected array $warnings = [];

    public function __construct(ReportSchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Validate a complete query configuration.
     */
    public function validate(array $queryConfig): array
    {
        $this->errors   = [];
        $this->warnings = [];

        // Basic structure validation
        $this->validateBasicStructure($queryConfig);

        if (empty($this->errors)) {
            // Detailed validation only if basic structure is valid
            $this->validateTable($queryConfig);
            $this->validateColumns($queryConfig);
            $this->validateJoins($queryConfig);
            $this->validateConditions($queryConfig);
            $this->validateGroupBy($queryConfig);
            $this->validateSortBy($queryConfig);
            $this->validateLimit($queryConfig);

            // Performance and security checks
            $this->performSecurityChecks($queryConfig);
            $this->performPerformanceChecks($queryConfig);
        }

        return [
            'valid'    => empty($this->errors),
            'errors'   => $this->errors,
            'warnings' => $this->warnings,
            'summary'  => $this->generateValidationSummary($queryConfig),
        ];
    }

    /**
     * Validate basic query structure.
     */
    protected function validateBasicStructure(array $queryConfig): void
    {
        $validator = Validator::make($queryConfig, [
            'table'      => 'required|array',
            'table.name' => 'required|string',
            'columns'    => 'required|array|min:1',
            'joins'      => 'sometimes|array',
            'conditions' => 'sometimes|array',
            'groupBy'    => 'sometimes|array',
            'sortBy'     => 'sometimes|array',
            'limit'      => 'sometimes|integer|min:1|max:50000',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->errors[] = $error;
            }
        }
    }

    /**
     * Validate table configuration.
     */
    protected function validateTable(array $queryConfig): void
    {
        if (!isset($queryConfig['table']['name'])) {
            return;
        }

        $tableName = $queryConfig['table']['name'];

        // Check if table exists in registry
        if (!$this->registry->hasTable($tableName)) {
            $this->errors[] = "Table '{$tableName}' is not available for reporting";

            return;
        }

        // // Check table permissions
        // $tableSchema = $this->registry->getTableSchema($tableName);
        // if (isset($tableSchema['permissions']) && !$this->checkTablePermissions($tableSchema['permissions'])) {
        //     $this->errors[] = "Insufficient permissions to access table '{$tableName}'";
        // }

        // Check max rows limit
        if (isset($queryConfig['limit'])) {
            $maxRows = $tableSchema['max_rows'] ?? 50000;
            if ($queryConfig['limit'] > $maxRows) {
                $this->warnings[] = "Requested limit ({$queryConfig['limit']}) exceeds maximum allowed ({$maxRows}) for table '{$tableName}'";
            }
        }
    }

    /**
     * Validate columns configuration.
     */
    protected function validateColumns(array $queryConfig): void
    {
        if (!isset($queryConfig['columns']) || !isset($queryConfig['table']['name'])) {
            return;
        }

        $tableName            = $queryConfig['table']['name'];
        $availableColumns     = $this->registry->getTableColumns($tableName);
        $availableColumnNames = array_column($availableColumns, 'name');

        foreach ($queryConfig['columns'] as $index => $column) {
            $this->validateColumn($column, $index, $availableColumnNames, $tableName);
        }

        // Check for too many columns
        if (count($queryConfig['columns']) > 50) {
            $this->warnings[] = 'Selecting many columns (' . count($queryConfig['columns']) . ') may impact performance';
        }
    }

    /**
     * Validate individual column.
     */
    protected function validateColumn(array $column, int $index, array $availableColumns, string $tableName): void
    {
        $validator = Validator::make($column, [
            'name'  => 'required|string',
            'alias' => 'sometimes|string|nullable|max:64',
            'type'  => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->errors[] = "Column {$index}: {$error}";
            }

            return;
        }

        // Check if column exists
        if (!in_array($column['name'], $availableColumns)) {
            $this->errors[] = "Column '{$column['name']}' does not exist in table '{$tableName}'";
        }

        // Validate alias format
        if (isset($column['alias'])) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column['alias'])) {
                $this->errors[] = "Invalid alias format '{$column['alias']}' for column '{$column['name']}'";
            }
        }
    }

    /**
     * Validate joins configuration.
     */
    protected function validateJoins(array $queryConfig): void
    {
        if (!isset($queryConfig['joins']) || !isset($queryConfig['table']['name'])) {
            return;
        }

        $mainTable              = $queryConfig['table']['name'];
        $availableRelationships = $this->registry->getTableRelationships($mainTable);

        foreach ($queryConfig['joins'] as $index => $join) {
            $this->validateJoin($join, $index, $availableRelationships, $mainTable);
        }

        // Check for too many joins
        if (count($queryConfig['joins']) > 5) {
            $this->warnings[] = 'Multiple joins (' . count($queryConfig['joins']) . ') may significantly impact performance';
        }
    }

    /**
     * Validate individual join.
     */
    protected function validateJoin(array $join, int $index, array $availableRelationships, string $mainTable): void
    {
        $validator = Validator::make($join, [
            'table'           => 'required|string',
            'type'            => 'required|string|in:left,right,inner',
            'local_key'       => 'required|string',
            'foreign_key'     => 'required|string',
            'selectedColumns' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->errors[] = "Join {$index}: {$error}";
            }

            return;
        }

        $joinTable = $join['table'];
        $joinKey   = $join['key'] ?? $joinTable;

        // Check if join relationship exists
        if (!isset($availableRelationships[$joinKey])) {
            $this->errors[] = "Join relationship '{$joinKey}' is not available for table '{$mainTable}'";

            return;
        }

        // Check if join table exists
        if (!$this->registry->hasTable($joinTable)) {
            $this->errors[] = "Join table '{$joinTable}' is not available for reporting";

            return;
        }

        // Validate selected columns for join
        if (isset($join['selectedColumns'])) {
            $joinTableColumns = $this->registry->getTableColumns($joinTable);
            $joinColumnNames  = array_column($joinTableColumns, 'name');

            foreach ($join['selectedColumns'] as $columnIndex => $column) {
                $this->validateColumn($column, $columnIndex, $joinColumnNames, $joinTable);
            }
        }
    }

    /**
     * Validate conditions configuration.
     */
    protected function validateConditions(array $queryConfig): void
    {
        if (!isset($queryConfig['conditions'])) {
            return;
        }

        $this->validateConditionGroup($queryConfig['conditions'], $queryConfig);

        // Check for too many conditions
        $conditionCount = $this->countConditions($queryConfig['conditions']);
        if ($conditionCount > 20) {
            $this->warnings[] = "Many conditions ({$conditionCount}) may impact query performance";
        }
    }

    /**
     * Validate condition group recursively.
     */
    protected function validateConditionGroup(array $conditions, array $queryConfig, string $path = 'conditions'): void
    {
        foreach ($conditions as $index => $condition) {
            $currentPath = "{$path}[{$index}]";

            if (isset($condition['conditions'])) {
                // Nested condition group
                $this->validateConditionGroup($condition['conditions'], $queryConfig, $currentPath);
            } else {
                // Individual condition
                $this->validateCondition($condition, $queryConfig, $currentPath);
            }
        }
    }

    /**
     * Validate individual condition.
     */
    protected function validateCondition(array $condition, array $queryConfig, string $path): void
    {
        $validator = Validator::make($condition, [
            'field'           => 'required|array',
            'field.name'      => 'required|string',
            'operator'        => 'required|array',
            'operator.value'  => 'required|string',
            'value'           => 'sometimes',
            'logicalOperator' => 'sometimes|string|in:and,or',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->errors[] = "{$path}: {$error}";
            }

            return;
        }

        // Validate field exists
        $fieldName = $condition['field']['name'];
        $tableName = $condition['field']['table'] ?? $queryConfig['table']['name'];

        if (!$this->isFieldAvailable($fieldName, $tableName, $queryConfig)) {
            $this->errors[] = "{$path}: Field '{$fieldName}' is not available in the query";
        }

        // Validate operator
        $operator = $condition['operator']['value'];
        if (!$this->isValidOperator($operator)) {
            $this->errors[] = "{$path}: Invalid operator '{$operator}'";
        }

        // Validate value based on operator
        $this->validateConditionValue($condition, $path);
    }

    /**
     * Validate condition value based on operator.
     */
    protected function validateConditionValue(array $condition, string $path): void
    {
        $operator = $condition['operator']['value'];
        $value    = $condition['value'];

        switch ($operator) {
            case 'in':
            case 'not_in':
                if (!is_array($value) && !is_string($value)) {
                    $this->errors[] = "{$path}: Value for '{$operator}' operator must be an array or comma-separated string";
                }
                break;
            case 'between':
            case 'not_between':
                if (!is_array($value) || count($value) !== 2) {
                    $this->errors[] = "{$path}: Value for '{$operator}' operator must be an array with exactly 2 elements";
                }
                break;
            case 'is_null':
            case 'is_not_null':
                // These operators don't need values
                break;
            default:
                if ($value === null || $value === '') {
                    $this->warnings[] = "{$path}: Empty value for operator '{$operator}' may not produce expected results";
                }
                break;
        }
    }

    /**
     * Validate GROUP BY configuration.
     */
    protected function validateGroupBy(array $queryConfig): void
    {
        if (!isset($queryConfig['groupBy'])) {
            return;
        }

        foreach ($queryConfig['groupBy'] as $index => $groupItem) {
            $validator = Validator::make($groupItem, [
                'groupBy'           => 'required|array',
                'groupBy.name'      => 'required|string',
                'aggregateFn'       => 'sometimes|array',
                'aggregateFn.value' => 'required_with:aggregateFn|string|in:count,sum,avg,min,max,group_concat',
                'aggregateBy'       => 'sometimes|array',
                'aggregateBy.name'  => 'required_with:aggregateBy|string',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->errors[] = "Group By {$index}: {$error}";
                }
            }

            // Validate group by field exists
            $groupByField = $groupItem['groupBy']['name'];
            if (!$this->isFieldAvailable($groupByField, $queryConfig['table']['name'], $queryConfig)) {
                $this->errors[] = "Group By {$index}: Field '{$groupByField}' is not available";
            }

            // Validate aggregate field if present
            if (isset($groupItem['aggregateBy']['name'])) {
                $aggregateField = $groupItem['aggregateBy']['name'];
                $aggregateFn    = $groupItem['aggregateFn']['value'] ?? null;

                // Allow '*' for COUNT operations only
                if ($aggregateField === '*') {
                    if ($aggregateFn !== 'count') {
                        $this->errors[] = "Group By {$index}: Wildcard '*' can only be used with COUNT function";
                    }
                } elseif (!$this->isFieldAvailable($aggregateField, $queryConfig['table']['name'], $queryConfig)) {
                    $this->errors[] = "Group By {$index}: Aggregate field '{$aggregateField}' is not available";
                }
            }
        }
    }

    /**
     * Validate ORDER BY configuration.
     */
    protected function validateSortBy(array $queryConfig): void
    {
        if (!isset($queryConfig['sortBy'])) {
            return;
        }

        foreach ($queryConfig['sortBy'] as $index => $sortItem) {
            $validator = Validator::make($sortItem, [
                'column'          => 'required|array',
                'column.name'     => 'required|string',
                'direction'       => 'required|array',
                'direction.value' => 'required|string|in:asc,desc',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->errors[] = "Sort By {$index}: {$error}";
                }
            }

            // Validate sort field exists
            $sortField = $sortItem['column']['name'];
            $tableName = $sortItem['column']['table'] ?? $queryConfig['table']['name'];

            if (!$this->isFieldAvailable($sortField, $tableName, $queryConfig)) {
                $this->errors[] = "Sort By {$index}: Field '{$sortField}' is not available";
            }
        }
    }

    /**
     * Validate LIMIT configuration.
     */
    protected function validateLimit(array $queryConfig): void
    {
        if (!isset($queryConfig['limit'])) {
            return;
        }

        $limit = $queryConfig['limit'];

        if (!is_numeric($limit) || $limit < 1) {
            $this->errors[] = 'Limit must be a positive integer';

            return;
        }

        if ($limit > 50000) {
            $this->errors[] = 'Limit cannot exceed 50,000 rows';
        } elseif ($limit > 10000) {
            $this->warnings[] = "Large limit ({$limit}) may impact performance";
        }
    }

    /**
     * Perform security checks.
     */
    protected function performSecurityChecks(array $queryConfig): void
    {
        // Check for SQL injection patterns in string values
        $this->checkForSqlInjection($queryConfig);

        // Check for sensitive data access
        $this->checkSensitiveDataAccess($queryConfig);

        // Check for excessive resource usage
        $this->checkResourceUsage($queryConfig);
    }

    /**
     * Perform performance checks.
     */
    protected function performPerformanceChecks(array $queryConfig): void
    {
        $complexity = $this->calculateComplexity($queryConfig);

        if ($complexity === 'high') {
            $this->warnings[] = 'Query complexity is high and may result in slow execution';
        }

        // Check for missing indexes on join/filter columns
        $this->checkIndexUsage($queryConfig);

        // Check for cartesian products
        $this->checkCartesianProducts($queryConfig);
    }

    /**
     * Check if field is available in the query context.
     */
    protected function isFieldAvailable(string $fieldName, string $tableName, array $queryConfig): bool
    {
        // Check main table columns
        if ($tableName === $queryConfig['table']['name']) {
            $mainColumns     = $this->registry->getTableColumns($tableName);
            $mainColumnNames = array_column($mainColumns, 'name');
            if (in_array($fieldName, $mainColumnNames)) {
                return true;
            }
        }

        // Check joined table columns
        if (isset($queryConfig['joins'])) {
            foreach ($queryConfig['joins'] as $join) {
                if ($join['table'] === $tableName) {
                    $joinColumns     = $this->registry->getTableColumns($tableName);
                    $joinColumnNames = array_column($joinColumns, 'name');
                    if (in_array($fieldName, $joinColumnNames)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if operator is valid.
     */
    protected function isValidOperator(string $operator): bool
    {
        $validOperators = [
            'eq', '=', 'neq', '!=', 'gt', '>', 'gte', '>=', 'lt', '<', 'lte', '<=',
            'like', 'not_like', 'in', 'not_in', 'between', 'not_between',
            'is_null', 'is_not_null', 'contains', 'starts_with', 'ends_with',
        ];

        return in_array($operator, $validOperators);
    }

    /**
     * Count total conditions recursively.
     */
    protected function countConditions(array $conditions): int
    {
        $count = 0;
        foreach ($conditions as $condition) {
            if (isset($condition['conditions'])) {
                $count += $this->countConditions($condition['conditions']);
            } else {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Calculate query complexity.
     */
    protected function calculateComplexity(array $queryConfig): string
    {
        $score = 0;

        $score += count($queryConfig['columns'] ?? []);
        $score += count($queryConfig['joins'] ?? []) * 3;
        $score += $this->countConditions($queryConfig['conditions'] ?? []) * 2;
        $score += count($queryConfig['groupBy'] ?? []) * 4;
        $score += count($queryConfig['sortBy'] ?? []);

        if ($score < 10) {
            return 'low';
        } elseif ($score < 25) {
            return 'medium';
        } else {
            return 'high';
        }
    }

    /**
     * Check for SQL injection patterns.
     */
    protected function checkForSqlInjection(array $queryConfig): void
    {
        $suspiciousPatterns = [
            '/union\s+select/i',
            '/drop\s+table/i',
            '/delete\s+from/i',
            '/insert\s+into/i',
            '/update\s+set/i',
            '/exec\s*\(/i',
            '/script\s*>/i',
        ];

        $this->checkPatternsRecursively($queryConfig, $suspiciousPatterns, 'Potential SQL injection detected');
    }

    /**
     * Check for sensitive data access.
     */
    protected function checkSensitiveDataAccess(array $queryConfig): void
    {
        $sensitiveColumns = ['password', 'token', 'secret', 'key', 'ssn', 'credit_card'];

        foreach ($queryConfig['columns'] ?? [] as $column) {
            foreach ($sensitiveColumns as $sensitive) {
                if (stripos($column['name'], $sensitive) !== false) {
                    $this->warnings[] = "Accessing potentially sensitive column: {$column['name']}";
                }
            }
        }
    }

    /**
     * Check resource usage.
     */
    protected function checkResourceUsage(array $queryConfig): void
    {
        $resourceScore = 0;

        $resourceScore += count($queryConfig['columns'] ?? []);
        $resourceScore += count($queryConfig['joins'] ?? []) * 5;
        $resourceScore += ($queryConfig['limit'] ?? 1000) / 100;

        if ($resourceScore > 100) {
            $this->warnings[] = 'Query may consume significant system resources';
        }
    }

    /**
     * Check index usage.
     */
    protected function checkIndexUsage(array $queryConfig): void
    {
        // This would require database schema information
        // For now, just check common patterns

        if (isset($queryConfig['conditions'])) {
            $hasIndexedConditions = false;
            foreach ($queryConfig['conditions'] as $condition) {
                if (isset($condition['field']['name'])) {
                    $fieldName = $condition['field']['name'];
                    if (in_array($fieldName, ['id', 'uuid', 'created_at', 'updated_at'])) {
                        $hasIndexedConditions = true;
                        break;
                    }
                }
            }

            if (!$hasIndexedConditions && count($queryConfig['conditions']) > 3) {
                $this->warnings[] = 'Consider adding conditions on indexed columns for better performance';
            }
        }
    }

    /**
     * Check for cartesian products.
     */
    protected function checkCartesianProducts(array $queryConfig): void
    {
        if (isset($queryConfig['joins']) && count($queryConfig['joins']) > 2) {
            $this->warnings[] = 'Multiple joins may result in cartesian products - ensure proper join conditions';
        }
    }

    /**
     * Check table permissions.
     */
    protected function checkTablePermissions(array $permissions): bool
    {
        // Implement permission checking logic based on your system
        // For now, return true as a placeholder
        return true;
    }

    /**
     * Check patterns recursively in data structure.
     */
    protected function checkPatternsRecursively(array $data, array $patterns, string $message): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->checkPatternsRecursively($value, $patterns, $message);
            } elseif (is_string($value)) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $this->errors[] = "{$message}: {$key}";
                        break;
                    }
                }
            }
        }
    }

    /**
     * Generate validation summary.
     */
    protected function generateValidationSummary(array $queryConfig): array
    {
        return [
            'complexity'            => $this->calculateComplexity($queryConfig),
            'total_columns'         => count($queryConfig['columns'] ?? []),
            'total_joins'           => count($queryConfig['joins'] ?? []),
            'total_conditions'      => $this->countConditions($queryConfig['conditions'] ?? []),
            'has_grouping'          => !empty($queryConfig['groupBy']),
            'has_sorting'           => !empty($queryConfig['sortBy']),
            'has_limit'             => isset($queryConfig['limit']),
            'estimated_performance' => $this->estimatePerformance($queryConfig),
        ];
    }

    /**
     * Estimate query performance.
     */
    protected function estimatePerformance(array $queryConfig): string
    {
        $complexity     = $this->calculateComplexity($queryConfig);
        $joinCount      = count($queryConfig['joins'] ?? []);
        $conditionCount = $this->countConditions($queryConfig['conditions'] ?? []);

        if ($complexity === 'low' && $joinCount <= 1 && $conditionCount <= 3) {
            return 'fast';
        } elseif ($complexity === 'medium' && $joinCount <= 3 && $conditionCount <= 10) {
            return 'moderate';
        } else {
            return 'slow';
        }
    }
}
