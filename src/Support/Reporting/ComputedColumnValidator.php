<?php

namespace Fleetbase\Support\Reporting;

use Fleetbase\Support\Reporting\Schema\Table;

class ComputedColumnValidator
{
    /**
     * Keywords that are never column references.
     */
    public const SQL_KEYWORDS = [
        'INTERVAL', 'AND', 'OR', 'XOR', 'NOT', 'IS', 'NULL', 'TRUE', 'FALSE',
        'AS', 'FROM', 'WHERE', 'DIV', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
        'DISTINCT', 'IN', 'LIKE', 'BETWEEN', 'ESCAPE', 'REGEXP',
        'ORDER', 'BY', 'ASC', 'DESC', 'SEPARATOR',
    ];

    /**
     * Cast types and interval units. These are keywords after `AS` / `INTERVAL <n>` (e.g.
     * `CAST(x AS SIGNED)`, `INTERVAL 7 DAY`) but may also be real column names (e.g. `time`).
     */
    public const CONTEXTUAL_KEYWORDS = [
        // CAST / CONVERT target types
        'DECIMAL', 'SIGNED', 'UNSIGNED', 'INTEGER', 'INT', 'CHAR', 'NCHAR', 'BINARY',
        'DATE', 'DATETIME', 'TIME', 'DOUBLE', 'FLOAT', 'REAL', 'JSON',
        // INTERVAL units
        'MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR',
    ];

    /**
     * Allowed SQL functions.
     */
    protected array $allowedFunctions = [
        // Date/Time Functions
        'DATEDIFF',
        'DATE_ADD',
        'DATE_SUB',
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
        'LAST_DAY',           // Get last day of month
        'DATE',               // Date part of a datetime, e.g. DATE(created_at)
        'TIME',               // Time part of a datetime
        'DAYOFWEEK',          // Get day of week (1=Sunday, 7=Saturday)
        'DAYOFMONTH',         // Get day of month (1-31)
        'DAYOFYEAR',          // Get day of year (1-366)
        'WEEK',               // Get week number
        'WEEKDAY',            // Get weekday index (0=Monday, 6=Sunday)
        'QUARTER',            // Get quarter (1-4)
        'TIMESTAMPDIFF',      // Difference between timestamps
        'TIMESTAMPADD',       // Add interval to timestamp
        'FROM_UNIXTIME',      // Convert Unix timestamp to datetime
        'UNIX_TIMESTAMP',     // Convert datetime to Unix timestamp
        'STR_TO_DATE',        // Parse string to date
        'MAKEDATE',           // Create date from year and day of year
        'ADDDATE',            // Add days to date
        'SUBDATE',            // Subtract days from date

        // String Functions
        'CONCAT',
        'CONCAT_WS',          // Concat with separator
        'SUBSTRING',
        'SUBSTR',             // Alias for SUBSTRING
        'REPLACE',
        'UPPER',
        'LOWER',
        'TRIM',
        'LTRIM',              // Left trim
        'RTRIM',              // Right trim
        'LENGTH',
        'CHAR_LENGTH',        // Character length
        'LEFT',               // Get leftmost characters
        'RIGHT',              // Get rightmost characters
        'LPAD',               // Left pad string
        'RPAD',               // Right pad string
        'REVERSE',            // Reverse string
        'LOCATE',             // Find substring position
        'POSITION',           // Find substring position
        'INSTR',              // Find substring position
        'STRCMP',             // Compare strings

        // Numeric Functions
        'ROUND',
        'ABS',
        'CEIL',               // Round up
        'CEILING',            // Round up (alias)
        'FLOOR',              // Round down
        'TRUNCATE',           // Truncate to decimal places
        'MOD',                // Modulo
        'POW',                // Power
        'POWER',              // Power (alias)
        'SQRT',               // Square root
        'SIGN',               // Sign of number (-1, 0, 1)
        'RAND',               // Random number
        'PI',                 // Pi constant
        'EXP',                // Exponential
        'LN',                 // Natural logarithm
        'LOG',                // Logarithm
        'LOG10',              // Base-10 logarithm
        'LOG2',               // Base-2 logarithm
        'DEGREES',            // Radians to degrees
        'RADIANS',            // Degrees to radians
        'SIN',                // Sine
        'COS',                // Cosine
        'TAN',                // Tangent
        'ASIN',               // Arc sine
        'ACOS',               // Arc cosine
        'ATAN',               // Arc tangent
        'ATAN2',              // Arc tangent of two variables

        // Conditional/Logic Functions
        'CASE',
        'WHEN',
        'THEN',
        'ELSE',
        'END',
        'IF',                 // IF(condition, true_value, false_value)
        'IFNULL',
        'NULLIF',
        'COALESCE',

        // Comparison Functions
        'LEAST',
        'GREATEST',

        // Aggregate Functions (for reference, though typically used in GROUP BY)
        'COUNT',
        'SUM',
        'AVG',
        'MIN',
        'MAX',
        'GROUP_CONCAT',

        // Type Conversion
        'CAST',
        'CONVERT',
        'DECIMAL',            // CAST(x AS DECIMAL(10,2))
        'CHAR',               // CAST(x AS CHAR(20))
        'BINARY',
        'DOUBLE',
        'FLOAT',

        // JSON Functions, e.g. JSON_UNQUOTE(JSON_EXTRACT(meta, '$.total'))
        'JSON_EXTRACT',
        'JSON_UNQUOTE',
        'JSON_VALUE',
        'JSON_LENGTH',
        'JSON_CONTAINS',
        'JSON_CONTAINS_PATH',
        'JSON_KEYS',
        'JSON_TYPE',
        'JSON_VALID',
        'JSON_SEARCH',

        // Other Utility Functions
        'INTERVAL',           // For date arithmetic
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
        'DUMPFILE', 'BENCHMARK', 'SLEEP', 'SELECT',
    ];

    protected ReportSchemaRegistry $registry;

    public function __construct(ReportSchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Validate a computed column expression.
     *
     * @param string $expression      the SQL expression
     * @param string $tableName       the base table name for column validation
     * @param array  $computedColumns optional array of other computed columns that can be referenced
     *
     * @return array validation result with 'valid' boolean and optional 'errors' array
     */
    public function validate(string $expression, string $tableName, array $computedColumns = []): array
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
        $columnCheck = $this->validateColumnReferences($expression, $tableName, $computedColumns);
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
        preg_match_all('/\b([A-Z_][A-Z0-9_]*)\s*\(/i', $this->removeStringLiterals($expression), $matches);

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
    protected function validateColumnReferences(string $expression, string $tableName, array $computedColumns = []): array
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

            // Build a list of computed column names for quick lookup
            $computedColumnNames = [];
            foreach ($computedColumns as $col) {
                $computedColumnNames[] = $col['name'] ?? '';
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

                    // Check if it's a reference to another computed column
                    if (in_array($columnRef, $computedColumnNames)) {
                        continue; // Valid reference to another computed column
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
        $keywords = array_merge($this->allowedFunctions, $this->allowedOperators, static::SQL_KEYWORDS, static::CONTEXTUAL_KEYWORDS);

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
        $columns = $table->getAllColumns();

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
