<?php

namespace Fleetbase\Support\Reporting;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportQueryErrorHandler
{
    protected array $errorCodes = [
        'VALIDATION_FAILED'      => 1001,
        'TABLE_NOT_FOUND'        => 1002,
        'COLUMN_NOT_FOUND'       => 1003,
        'PERMISSION_DENIED'      => 1004,
        'QUERY_EXECUTION_FAILED' => 1005,
        'EXPORT_FAILED'          => 1006,
        'TIMEOUT'                => 1007,
        'MEMORY_LIMIT'           => 1008,
        'INVALID_CONFIGURATION'  => 1009,
        'SCHEMA_ERROR'           => 1010,
        'CONNECTION_ERROR'       => 1011,
        'RATE_LIMIT_EXCEEDED'    => 1012,
    ];

    protected array $userFriendlyMessages = [
        'VALIDATION_FAILED'      => 'The query configuration contains errors. Please check your selections and try again.',
        'TABLE_NOT_FOUND'        => 'The selected table is not available for reporting.',
        'COLUMN_NOT_FOUND'       => 'One or more selected columns are not available.',
        'PERMISSION_DENIED'      => 'You do not have permission to access this data.',
        'QUERY_EXECUTION_FAILED' => 'The query could not be executed. Please try simplifying your request.',
        'EXPORT_FAILED'          => 'The export could not be completed. Please try again.',
        'TIMEOUT'                => 'The query took too long to execute. Please try reducing the data scope.',
        'MEMORY_LIMIT'           => 'The query requires too much memory. Please try limiting the results.',
        'INVALID_CONFIGURATION'  => 'The query configuration is invalid.',
        'SCHEMA_ERROR'           => 'There was an error with the data schema.',
        'CONNECTION_ERROR'       => 'Could not connect to the database.',
        'RATE_LIMIT_EXCEEDED'    => 'Too many requests. Please wait before trying again.',
    ];

    /**
     * Handle and format an error for API response.
     */
    public function handleError(\Throwable $exception, array $context = []): array
    {
        $errorCode = $this->determineErrorCode($exception);
        $errorId   = $this->generateErrorId();

        // Log the error with full details
        $this->logError($exception, $errorCode, $errorId, $context);

        // Return user-friendly error response
        return [
            'success' => false,
            'error'   => [
                'code'        => $errorCode,
                'id'          => $errorId,
                'message'     => $this->getUserFriendlyMessage($errorCode),
                'details'     => $this->getErrorDetails($exception, $errorCode),
                'suggestions' => $this->getErrorSuggestions($errorCode),
                'timestamp'   => now()->toISOString(),
            ],
            'meta' => [
                'request_id' => request()->header('X-Request-ID') ?? $errorId,
                'user_id'    => auth()->id(),
                'company_id' => session('company'),
            ],
        ];
    }

    /**
     * Handle validation errors specifically.
     */
    public function handleValidationError(array $validationResult, array $context = []): array
    {
        $errorId = $this->generateErrorId();

        // Log validation failure
        Log::warning('Report validation failed', [
            'error_id' => $errorId,
            'errors'   => $validationResult['errors'],
            'warnings' => $validationResult['warnings'],
            'context'  => $context,
        ]);

        return [
            'success' => false,
            'error'   => [
                'code'                => 'VALIDATION_FAILED',
                'id'                  => $errorId,
                'message'             => 'Query validation failed',
                'validation_errors'   => $validationResult['errors'],
                'validation_warnings' => $validationResult['warnings'],
                'suggestions'         => $this->getValidationSuggestions($validationResult['errors']),
                'timestamp'           => now()->toISOString(),
            ],
        ];
    }

    /**
     * Handle query timeout errors.
     */
    public function handleTimeoutError(float $executionTime, array $context = []): array
    {
        $errorId = $this->generateErrorId();

        Log::warning('Query timeout', [
            'error_id'       => $errorId,
            'execution_time' => $executionTime,
            'context'        => $context,
        ]);

        return [
            'success' => false,
            'error'   => [
                'code'           => 'TIMEOUT',
                'id'             => $errorId,
                'message'        => 'Query execution timed out',
                'execution_time' => $executionTime,
                'suggestions'    => [
                    'Try reducing the number of selected columns',
                    'Add more specific filters to limit the data',
                    'Remove complex joins if possible',
                    'Increase the limit to reduce the dataset size',
                ],
                'timestamp' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Handle export errors.
     */
    public function handleExportError(\Throwable $exception, string $format, array $context = []): array
    {
        $errorId = $this->generateErrorId();

        Log::error('Export failed', [
            'error_id'  => $errorId,
            'format'    => $format,
            'exception' => $exception->getMessage(),
            'context'   => $context,
        ]);

        return [
            'success' => false,
            'error'   => [
                'code'        => 'EXPORT_FAILED',
                'id'          => $errorId,
                'message'     => "Export to {$format} format failed",
                'format'      => $format,
                'suggestions' => [
                    'Try a different export format',
                    'Reduce the amount of data being exported',
                    'Check if the export directory has sufficient space',
                ],
                'timestamp' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Determine error code from exception.
     */
    protected function determineErrorCode(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'table') && str_contains($message, 'not found')) {
            return 'TABLE_NOT_FOUND';
        }

        if (str_contains($message, 'column') && str_contains($message, 'not found')) {
            return 'COLUMN_NOT_FOUND';
        }

        if (str_contains($message, 'permission') || str_contains($message, 'access denied')) {
            return 'PERMISSION_DENIED';
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'time limit')) {
            return 'TIMEOUT';
        }

        if (str_contains($message, 'memory') || str_contains($message, 'out of memory')) {
            return 'MEMORY_LIMIT';
        }

        if (str_contains($message, 'connection') || str_contains($message, 'database')) {
            return 'CONNECTION_ERROR';
        }

        if (str_contains($message, 'validation') || str_contains($message, 'invalid')) {
            return 'VALIDATION_FAILED';
        }

        // Default to query execution failed
        return 'QUERY_EXECUTION_FAILED';
    }

    /**
     * Get user-friendly error message.
     */
    protected function getUserFriendlyMessage(string $errorCode): string
    {
        return $this->userFriendlyMessages[$errorCode] ?? 'An unexpected error occurred. Please try again.';
    }

    /**
     * Get error details based on error code.
     */
    protected function getErrorDetails(\Throwable $exception, string $errorCode): array
    {
        $details = [
            'type' => get_class($exception),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
        ];

        // Add specific details based on error code
        switch ($errorCode) {
            case 'TIMEOUT':
                $details['timeout_limit'] = config('database.timeout', 30);
                break;
            case 'MEMORY_LIMIT':
                $details['memory_limit'] = ini_get('memory_limit');
                break;
            case 'CONNECTION_ERROR':
                $details['database'] = config('database.default');
                break;
        }

        return $details;
    }

    /**
     * Get error suggestions based on error code.
     */
    protected function getErrorSuggestions(string $errorCode): array
    {
        $suggestions = [
            'VALIDATION_FAILED' => [
                'Check that all selected tables and columns exist',
                'Verify that join relationships are correctly configured',
                'Ensure filter values are in the correct format',
            ],
            'TABLE_NOT_FOUND' => [
                'Verify the table name is correct',
                'Check if you have permission to access this table',
                'Contact your administrator if the table should be available',
            ],
            'COLUMN_NOT_FOUND' => [
                'Check the column names in your selection',
                'Verify that joined tables contain the selected columns',
                'Refresh the page to get the latest schema information',
            ],
            'PERMISSION_DENIED' => [
                'Contact your administrator for access to this data',
                'Try selecting different tables or columns',
                'Check if your user role has the necessary permissions',
            ],
            'QUERY_EXECUTION_FAILED' => [
                'Try simplifying your query by removing some joins',
                'Add more specific filters to reduce the data set',
                'Check if all selected columns are valid',
            ],
            'EXPORT_FAILED' => [
                'Try a different export format',
                'Reduce the amount of data being exported',
                'Try again in a few minutes',
            ],
            'TIMEOUT' => [
                'Add more specific filters to reduce the data set',
                'Try selecting fewer columns',
                'Remove complex joins if possible',
                'Increase the row limit to reduce processing time',
            ],
            'MEMORY_LIMIT' => [
                'Reduce the number of rows by adding filters',
                'Select fewer columns',
                'Try exporting in smaller batches',
            ],
        ];

        return $suggestions[$errorCode] ?? [
            'Try refreshing the page and attempting the operation again',
            'Contact support if the problem persists',
        ];
    }

    /**
     * Get validation-specific suggestions.
     */
    protected function getValidationSuggestions(array $errors): array
    {
        $suggestions = [];

        foreach ($errors as $error) {
            $errorLower = strtolower($error);

            if (str_contains($errorLower, 'table')) {
                $suggestions[] = 'Verify that the selected table is available for reporting';
            }

            if (str_contains($errorLower, 'column')) {
                $suggestions[] = 'Check that all selected columns exist in their respective tables';
            }

            if (str_contains($errorLower, 'join')) {
                $suggestions[] = 'Ensure join relationships are properly configured';
            }

            if (str_contains($errorLower, 'condition') || str_contains($errorLower, 'filter')) {
                $suggestions[] = 'Verify that filter conditions use valid operators and values';
            }

            if (str_contains($errorLower, 'limit')) {
                $suggestions[] = 'Adjust the row limit to be within acceptable bounds';
            }
        }

        // Remove duplicates and add general suggestions
        $suggestions = array_unique($suggestions);

        if (empty($suggestions)) {
            $suggestions[] = 'Review your query configuration and try again';
        }

        return $suggestions;
    }

    /**
     * Log error with full context.
     */
    protected function logError(\Throwable $exception, string $errorCode, string $errorId, array $context): void
    {
        Log::error('Report system error', [
            'error_id'   => $errorId,
            'error_code' => $errorCode,
            'exception'  => [
                'message' => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'trace'   => $exception->getTraceAsString(),
            ],
            'context'    => $context,
            'user_id'    => auth()->id(),
            'company_id' => session('company'),
            'request_id' => request()->header('X-Request-ID'),
            'url'        => request()->url(),
            'method'     => request()->method(),
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Generate unique error ID.
     */
    protected function generateErrorId(): string
    {
        return 'ERR_' . strtoupper(Str::random(8)) . '_' . time();
    }

    /**
     * Check if error is recoverable.
     */
    public function isRecoverable(string $errorCode): bool
    {
        $recoverableErrors = [
            'TIMEOUT',
            'MEMORY_LIMIT',
            'RATE_LIMIT_EXCEEDED',
            'CONNECTION_ERROR',
        ];

        return in_array($errorCode, $recoverableErrors);
    }

    /**
     * Get retry suggestions for recoverable errors.
     */
    public function getRetrySuggestions(string $errorCode): array
    {
        $retrySuggestions = [
            'TIMEOUT' => [
                'wait_time' => 30,
                'message'   => 'Try again in 30 seconds with a simpler query',
            ],
            'MEMORY_LIMIT' => [
                'wait_time' => 60,
                'message'   => 'Try again in 1 minute with fewer columns or more filters',
            ],
            'RATE_LIMIT_EXCEEDED' => [
                'wait_time' => 300,
                'message'   => 'Try again in 5 minutes',
            ],
            'CONNECTION_ERROR' => [
                'wait_time' => 10,
                'message'   => 'Try again in 10 seconds',
            ],
        ];

        return $retrySuggestions[$errorCode] ?? [
            'wait_time' => 60,
            'message'   => 'Try again in 1 minute',
        ];
    }

    /**
     * Format error for different output types.
     */
    public function formatErrorForOutput(array $error, string $outputType = 'json'): mixed
    {
        switch ($outputType) {
            case 'html':
                return $this->formatErrorAsHtml($error);
            case 'text':
                return $this->formatErrorAsText($error);
            case 'json':
            default:
                return $error;
        }
    }

    /**
     * Format error as HTML.
     */
    protected function formatErrorAsHtml(array $error): string
    {
        $errorInfo = $error['error'];

        $html = "<div class='error-container'>";
        $html .= "<h3>Error: {$errorInfo['message']}</h3>";
        $html .= "<p><strong>Error ID:</strong> {$errorInfo['id']}</p>";
        $html .= "<p><strong>Code:</strong> {$errorInfo['code']}</p>";

        if (!empty($errorInfo['suggestions'])) {
            $html .= '<h4>Suggestions:</h4><ul>';
            foreach ($errorInfo['suggestions'] as $suggestion) {
                $html .= "<li>{$suggestion}</li>";
            }
            $html .= '</ul>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Format error as plain text.
     */
    protected function formatErrorAsText(array $error): string
    {
        $errorInfo = $error['error'];

        $text = "ERROR: {$errorInfo['message']}\n";
        $text .= "Error ID: {$errorInfo['id']}\n";
        $text .= "Code: {$errorInfo['code']}\n";

        if (!empty($errorInfo['suggestions'])) {
            $text .= "\nSuggestions:\n";
            foreach ($errorInfo['suggestions'] as $suggestion) {
                $text .= "- {$suggestion}\n";
            }
        }

        return $text;
    }
}
