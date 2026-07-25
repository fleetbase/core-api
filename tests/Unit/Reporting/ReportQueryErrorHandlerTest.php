<?php

use Fleetbase\Support\Reporting\ReportQueryErrorHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $container = bind_test_container([
        'database.default' => 'mysql',
        'database.timeout' => 45,
    ]);

    $request = Request::create('/int/v1/reports/execute-query', 'POST', [], [], [], [
        'HTTP_X_REQUEST_ID' => 'req_123',
        'REMOTE_ADDR'       => '127.0.0.1',
        'HTTP_USER_AGENT'   => 'Pest',
    ]);

    $container->instance('request', $request);
    session(['company' => 'company_123', 'user' => 'user_123']);
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('report query error handler formats table and connection errors for api responses', function () {
    $handler = new ReportQueryErrorHandler();

    $tableError = $handler->handleError(new RuntimeException('table orders not found'), ['table' => 'orders']);
    $dbError    = $handler->handleError(new RuntimeException('database connection refused'));

    expect($tableError['success'])->toBeFalse()
        ->and($tableError['error']['code'])->toBe('TABLE_NOT_FOUND')
        ->and($tableError['error']['message'])->toBe('The selected table is not available for reporting.')
        ->and($tableError['meta']['request_id'])->toBe('req_123')
        ->and($tableError['meta']['user_id'])->toBe('user_123')
        ->and($tableError['meta']['company_id'])->toBe('company_123')
        ->and($dbError['error']['code'])->toBe('CONNECTION_ERROR')
        ->and($dbError['error']['details']['database'])->toBe('mysql');
});

test('report query error handler handles validation timeout and export failures', function () {
    $handler = new ReportQueryErrorHandler();

    $validation = $handler->handleValidationError([
        'errors'   => ['Table orders is missing', 'Column total is invalid', 'Limit cannot exceed 50,000 rows'],
        'warnings' => ['Large limit may impact performance'],
    ]);
    $timeout = $handler->handleTimeoutError(31.5);
    $export  = $handler->handleExportError(new RuntimeException('Disk full'), 'csv');

    expect($validation['error']['code'])->toBe('VALIDATION_FAILED')
        ->and($validation['error']['validation_errors'])->toHaveCount(3)
        ->and($validation['error']['suggestions'])->toContain('Verify that the selected table is available for reporting')
        ->and($validation['error']['suggestions'])->toContain('Check that all selected columns exist in their respective tables')
        ->and($timeout['error']['code'])->toBe('TIMEOUT')
        ->and($timeout['error']['execution_time'])->toBe(31.5)
        ->and($export['error']['code'])->toBe('EXPORT_FAILED')
        ->and($export['error']['format'])->toBe('csv');
});

test('report query error handler classifies specific failures and validation suggestions', function () {
    $handler = new ReportQueryErrorHandler();

    $column     = $handler->handleError(new RuntimeException('column total not found'));
    $permission = $handler->handleError(new RuntimeException('access denied for reporting table'));
    $timeout    = $handler->handleError(new RuntimeException('query timeout exceeded'));
    $memory     = $handler->handleError(new RuntimeException('allowed memory exhausted'));
    $invalid    = $handler->handleError(new RuntimeException('invalid query configuration'));
    $generic    = $handler->handleError(new RuntimeException('unexpected sql grammar failure'));

    $validation = $handler->handleValidationError([
        'errors'   => [
            'Join from orders to users is not configured',
            'Filter condition status ~~ active is invalid',
            'Unknown report problem',
        ],
        'warnings' => [],
    ]);
    $genericValidation = $handler->handleValidationError([
        'errors'   => ['Unexpected report problem'],
        'warnings' => [],
    ]);

    expect($column['error']['code'])->toBe('COLUMN_NOT_FOUND')
        ->and($column['error']['message'])->toBe('One or more selected columns are not available.')
        ->and($permission['error']['code'])->toBe('PERMISSION_DENIED')
        ->and($permission['error']['suggestions'])->toContain('Contact your administrator for access to this data')
        ->and($timeout['error']['code'])->toBe('TIMEOUT')
        ->and($timeout['error']['details']['timeout_limit'])->toBe(45)
        ->and($memory['error']['code'])->toBe('MEMORY_LIMIT')
        ->and($memory['error']['details']['memory_limit'])->toBe(ini_get('memory_limit'))
        ->and($invalid['error']['code'])->toBe('VALIDATION_FAILED')
        ->and($generic['error']['code'])->toBe('QUERY_EXECUTION_FAILED')
        ->and($validation['error']['suggestions'])->toContain('Ensure join relationships are properly configured')
        ->and($validation['error']['suggestions'])->toContain('Verify that filter conditions use valid operators and values')
        ->and($genericValidation['error']['suggestions'])->toBe(['Review your query configuration and try again']);
});

test('report query error handler exposes retry and output formatting contracts', function () {
    $handler = new ReportQueryErrorHandler();
    $error   = $handler->handleTimeoutError(42);

    expect($handler->isRecoverable('TIMEOUT'))->toBeTrue()
        ->and($handler->isRecoverable('VALIDATION_FAILED'))->toBeFalse()
        ->and($handler->getRetrySuggestions('CONNECTION_ERROR')['wait_time'])->toBe(10)
        ->and($handler->getRetrySuggestions('UNKNOWN')['message'])->toBe('Try again in 1 minute')
        ->and($handler->formatErrorForOutput($error, 'json'))->toBe($error)
        ->and($handler->formatErrorForOutput($error, 'html'))->toContain("<div class='error-container'>")
        ->and($handler->formatErrorForOutput($error, 'text'))->toContain('ERROR: Query execution timed out');
});
