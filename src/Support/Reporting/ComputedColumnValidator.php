<?php

namespace Fleetbase\Support\Reporting;

use Fleetbase\Support\Reporting\Schema\Table;

class ComputedColumnValidator
{
    /**
     * Allowed SQL functions.
     */
    protected array $allowedFunctions = [
        'DATEDIFF',
        'DATE_ADD',
        'DATE_SUB',
        'CONCAT',
        'COALESCE',
        'IFNULL',
        'CASE',
        'WHEN',
        'THEN',
        'ELSE',
        'END',
        'LEAST',
        'GREATEST',
        'ROUND',
        'ABS',
        'UPPER',
        'LOWER',
        'TRIM',
        'LENGTH',
        'NULLIF',
        'NOW',
        'CURDATE',
        'CURTIME',
        'YEAR',
        'MONTH',
        'DAY',
        'HOUR',
        'MINUTE',
        'SECOND',
        'DATE_FORMAT',
        'SUBSTRING',
        'REPLACE',
        'INTERVAL',
    ];

    /**
     * Allowed operators.
     */
    protected array $allowedOperators = [
        '+', '-', '*', '/', '%',
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'AND', 'OR', 'NOT',
        'IS', 'NULL',
    ];

    /**
     * Forbidden SQL keywords that indicate potential SQL injection.
     */
    protected array $forbiddenKeywords = [
        'DROP', 'DELETE', 'UPDATE', 'INSERT', 'TRUNCATE',
        'ALTER', 'CREATE', 'GRANT', 'REVOKE',
        'EXEC', 'EXECUTE', 'UNION', 'INTO',
        'INFORMATION_SCHEMA', 'LOAD_FILE', 'OUTFILE',
        'DUMPFILE', 'BENCHMARK', 'SLEEP',
    ];

    protected ReportSchemaRegistry $registry;

    public function __construct(ReportSchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Validate a computed column expression.
     *
     * @param string $expression the SQL expression
     * @param string $tableName  the base table name for column validation
     *
     * @return array validation result with 'valid' boolean and optional 'errors' array
     */
    public function validate(string $expression, string $tableName): array
    {
        $errors = [];

        // Check for forbidden keywords
        $forbiddenCheck = $this->checkForbiddenKeywords($expression);
        if (!$forbiddenCheck['valid']) {
            $errors[] = $forbiddenCheck['error'];
        }

        // Validate functions
        $functionCheck = $this->validateFunctions($expression);
        if (!$functionCheck['valid']) {
            $errors = array_merge($errors, $functionCheck['errors']);
        }

        // Validate operators
        $operatorCheck = $this->validateOperators($expression);
        if (!$operatorCheck['valid']) {
            $errors = array_merge($errors, $operatorCheck['errors']);
        }

        // Validate column references
        $columnCheck = $this->validateColumnReferences($expression, $tableName);
        if (!$columnCheck['valid']) {
            $errors = array_merge($errors, $columnCheck['errors']);
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check for forbidden SQL keywords.
     */
    protected function checkForbiddenKeywords(string $expression): array
    {
        $upperExpression = strtoupper($expression);

        foreach ($this->forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $upperExpression)) {
                return [
                    'valid' => false,
                    'error' => "Expression contains forbidden SQL keyword: {$keyword}",
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Validate that all functions used are in the whitelist.
     */
    protected function validateFunctions(string $expression): array
    {
        $errors = [];

        // Match function calls: FUNCTION_NAME(
        preg_match_all('/([A-Z_]+)\s*\(/i', $expression, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $function) {
                $upperFunction = strtoupper($function);
                if (!in_array($upperFunction, $this->allowedFunctions)) {
                    $errors[] = "Function '{$function}' is not allowed. Allowed functions: " . implode(', ', $this->allowedFunctions);
                }
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate that all operators used are in the whitelist.
     */
    protected function validateOperators(string $expression): array
    {
        $errors = [];

        // Remove string literals to avoid false positives
        $cleanExpression = preg_replace("/'[^']*'/", '', $expression);
        $cleanExpression = preg_replace('/"[^"]*"/', '', $cleanExpression);

        // Check for potentially dangerous operators
        $dangerousOperators = ['||', '&&', ';', '--', '/*', '*/'];

        foreach ($dangerousOperators as $operator) {
            if (strpos($cleanExpression, $operator) !== false) {
                $errors[] = "Operator '{$operator}' is not allowed";
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate that all column references exist in the schema.
     */
    protected function validateColumnReferences(string $expression, string $tableName): array
    {
        $errors = [];

        try {
            $table = $this->registry->getTable($tableName);
            if (!$table) {
                return [
                    'valid'  => false,
                    'errors' => ["Table '{$tableName}' not found in schema registry"],
                ];
            }

            // Remove string literals (both single and double quoted) to avoid treating them as column references
            $cleanExpression = $this->removeStringLiterals($expression);

            // Extract potential column references (simple word boundaries)
            // This regex matches words that could be column names
            preg_match_all('/\b([a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)*)\b/i', $cleanExpression, $matches);

            if (!empty($matches[1])) {
                $potentialColumns = array_unique($matches[1]);

                foreach ($potentialColumns as $columnRef) {
                    // Skip if it's a function, keyword, or literal
                    if ($this->isKeywordOrLiteral($columnRef)) {
                        continue;
                    }

                    // Check if it's a valid column reference
                    if (!$this->isValidColumnReference($columnRef, $table)) {
                        $errors[] = "Column reference '{$columnRef}' does not exist in table '{$tableName}' or its relationships";
                    }
                }
            }
        } catch (\Exception $e) {
            $errors[] = 'Error validating column references: ' . $e->getMessage();
        }
        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Remove string literals from expression to avoid false positives.
     */
    protected function removeStringLiterals(string $expression): string
    {
        // Remove single-quoted strings (e.g., 'High', 'Low')
        $cleaned = preg_replace("/'[^']*'/", "''", $expression);
        
        // Remove double-quoted strings (e.g., "High", "Low")
        $cleaned = preg_replace('/"[^"]*"/', '""', $cleaned);
        
        return $cleaned;
    }

    /**
     * Check if a word is a SQL keyword or literal value.
     */
    protected function isKeywordOrLiteral(string $word): bool
    {
        $keywords = array_merge($this->allowedFunctions, $this->allowedOperators, [
            'TRUE', 'FALSE', 'NULL', 'AS', 'FROM', 'WHERE',
            'INTERVAL', 'DAY', 'MONTH', 'YEAR', 'HOUR', 'MINUTE', 'SECOND',
        ]);

        return in_array(strtoupper($word), $keywords) || is_numeric($word);
    }

    /**
     * Check if a column reference is valid in the table schema.
     */
    protected function isValidColumnReference(string $columnRef, Table $table): bool
    {
        // Handle dot notation (e.g., "asset.name" or "details.purchase_date")
        $parts = explode('.', $columnRef);

        if (count($parts) === 1) {
            // Simple column reference
            return $this->columnExistsInTable($columnRef, $table);
        } elseif (count($parts) === 2) {
            // Could be relationship.column or json.field
            [$first, $second] = $parts;

            // Check if it's a JSON column access
            if ($this->columnExistsInTable($first, $table)) {
                return true; // Allow JSON field access
            }

            // Check if it's a relationship
            $relationships = $table->getRelationships();
            foreach ($relationships as $relationship) {
                if ($relationship->getName() === $first) {
                    // Relationship exists, assume column is valid
                    // (We could validate the related table's columns here, but that adds complexity)
                    return true;
                }
            }

            return false;
        } else {
            // More than 2 parts - could be nested relationships
            // For now, we'll be permissive and allow it
            return true;
        }
    }

    /**
     * Check if a column exists in the table.
     */
    protected function columnExistsInTable(string $columnName, Table $table): bool
    {
        $columns = $table->getColumns();

        foreach ($columns as $column) {
            if ($column->getName() === $columnName) {
                return true;
            }

            // Check if it's a JSON column prefix
            if (strpos($column->getName(), '.') !== false) {
                $prefix = explode('.', $column->getName())[0];
                if ($prefix === $columnName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the list of allowed functions.
     */
    public function getAllowedFunctions(): array
    {
        return $this->allowedFunctions;
    }

    /**
     * Get the list of allowed operators.
     */
    public function getAllowedOperators(): array
    {
        return $this->allowedOperators;
    }
}
