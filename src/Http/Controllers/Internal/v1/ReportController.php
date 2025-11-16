<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Models\Report;
use Fleetbase\Support\Reporting\ComputedColumnValidator;
use Fleetbase\Support\Reporting\ReportQueryConverter;
use Fleetbase\Support\Reporting\ReportQueryErrorHandler;
use Fleetbase\Support\Reporting\ReportQueryValidator;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'report';

    protected ReportQueryValidator $queryValidator;
    protected ReportQueryErrorHandler $errorHandler;

    public function __construct()
    {
        parent::__construct();
        $this->queryValidator = new ReportQueryValidator(app(ReportSchemaRegistry::class));
        $this->errorHandler   = new ReportQueryErrorHandler();
    }

    /**
     * Get available tables for reporting.
     */
    public function getTables(Request $request): JsonResponse
    {
        $extension = $request->input('extension', 'core');
        $category  = $request->input('category');

        try {
            $tables = Report::getAvailableTables($extension, $category);

            return response()->json([
                'success' => true,
                'tables'  => $tables,
                'meta'    => [
                    'total_tables' => count($tables),
                    'timestamp'    => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, ['action' => 'get_tables']),
                500
            );
        }
    }

    /**
     * Get table schema including columns and relationships.
     */
    public function getTableSchema(Request $request, string $tableName): JsonResponse
    {
        try {
            $schema = Report::getTableSchema($tableName);

            return response()->json([
                'success' => true,
                'schema'  => $schema,
                'meta'    => [
                    'table_name'          => $tableName,
                    'columns_count'       => count($schema['columns'] ?? []),
                    'relationships_count' => count($schema['relationships'] ?? []),
                    'timestamp'           => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action'     => 'get_table_schema',
                    'table_name' => $tableName,
                ]),
                404
            );
        }
    }

    /**
     * Get columns for a specific table.
     */
    public function getTableColumns(Request $request, string $tableName): JsonResponse
    {
        try {
            $columns = Report::getTableColumns($tableName);

            return response()->json([
                'success' => true,
                'columns' => $columns,
                'meta'    => [
                    'table_name'    => $tableName,
                    'total_columns' => count($columns),
                    'timestamp'     => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action'     => 'get_table_columns',
                    'table_name' => $tableName,
                ]),
                404
            );
        }
    }

    /**
     * Get relationships for a specific table.
     */
    public function getTableRelationships(Request $request, string $tableName): JsonResponse
    {
        try {
            $relationships = Report::getTableRelationships($tableName);

            return response()->json([
                'success'       => true,
                'relationships' => $relationships,
                'meta'          => [
                    'table_name'          => $tableName,
                    'total_relationships' => count($relationships),
                    'timestamp'           => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action'     => 'get_table_relationships',
                    'table_name' => $tableName,
                ]),
                404
            );
        }
    }

    /**
     * Validate a query configuration.
     */
    public function validateQuery(Request $request): JsonResponse
    {
        try {
            $queryConfig = $request->input('query_config');

            if (!$queryConfig) {
                return response()->json([
                    'valid'  => false,
                    'errors' => ['Query configuration is required'],
                ], 400);
            }

            $validationResult = $this->queryValidator->validate($queryConfig);

            if ($validationResult['valid']) {
                return response()->json([
                    'valid'    => true,
                    'message'  => 'Query configuration is valid',
                    'warnings' => $validationResult['warnings'],
                    'summary'  => $validationResult['summary'],
                    'meta'     => [
                        'validation_time' => microtime(true),
                        'timestamp'       => now()->toISOString(),
                    ],
                ]);
            } else {
                return response()->json(
                    $this->errorHandler->handleValidationError($validationResult, [
                        'action'       => 'validate_query',
                        'query_config' => $queryConfig,
                    ]),
                    400
                );
            }
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action' => 'validate_query',
                ]),
                500
            );
        }
    }

    /**
     * Execute a report query.
     */
    public function execute(Request $request, string $id): JsonResponse
    {
        try {
            $report = Report::where('uuid', $id)->firstOrFail();

            // Validate query configuration before execution
            $validationResult = $this->queryValidator->validate($report->query_config);
            if (!$validationResult['valid']) {
                return response()->json(
                    $this->errorHandler->handleValidationError($validationResult, [
                        'action'    => 'execute_report',
                        'report_id' => $id,
                    ]),
                    400
                );
            }

            $result = $report->execute();

            if ($result['success']) {
                return response()->json($result);
            } else {
                return response()->json(
                    $this->errorHandler->handleError(
                        new \Exception($result['error']),
                        ['action' => 'execute_report', 'report_id' => $id]
                    ),
                    500
                );
            }
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action'    => 'execute_report',
                    'report_id' => $id,
                ]),
                500
            );
        }
    }

    /**
     * Execute a query directly without saving as report.
     */
    public function executeQuery(Request $request): JsonResponse
    {
        try {
            $queryConfig = $request->input('query_config');

            if (!$queryConfig) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'INVALID_CONFIGURATION',
                        'message' => 'Query configuration is required',
                    ],
                ], 400);
            }

            // Validate query configuration
            $validationResult = $this->queryValidator->validate($queryConfig);
            if (!$validationResult['valid']) {
                return response()->json(
                    $this->errorHandler->handleValidationError($validationResult, [
                        'action'       => 'execute_query',
                        'query_config' => $queryConfig,
                    ]),
                    400
                );
            }

            // Execute query with timeout protection
            $startTime    = microtime(true);
            $timeoutLimit = config('reports.query_timeout', 30);

            DB::beginTransaction();

            try {
                // Get the query converter
                $registry  = app(ReportSchemaRegistry::class);
                $converter = new ReportQueryConverter($registry, $queryConfig);

                // Execute the query
                $result = $converter->execute();

                DB::commit();

                $executionTime = microtime(true) - $startTime;

                // Check for timeout
                if ($executionTime > $timeoutLimit) {
                    return response()->json(
                        $this->errorHandler->handleTimeoutError($executionTime, [
                            'action'       => 'execute_query',
                            'query_config' => $queryConfig,
                        ]),
                        408
                    );
                }

                return response()->json($result);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action' => 'execute_query',
                ]),
                500
            );
        }
    }

    /**
     * Export a report in specified format.
     */
    public function export(Request $request, string $id): JsonResponse
    {
        try {
            $report  = Report::where('uuid', $id)->firstOrFail();
            $format  = $request->input('format', 'csv');
            $options = $request->input('options', []);

            // Validate format
            $registry       = app(ReportSchemaRegistry::class);
            $converter      = new ReportQueryConverter($registry, $report->query_config);
            $allowedFormats = array_keys($converter->getAvailableExportFormats());

            if (!in_array($format, $allowedFormats)) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'            => 'INVALID_CONFIGURATION',
                        'message'         => 'Invalid export format',
                        'allowed_formats' => $allowedFormats,
                    ],
                ], 400);
            }

            $result = $report->export($format, $options);

            if ($result['success']) {
                return response()->json($result);
            } else {
                return response()->json(
                    $this->errorHandler->handleExportError(
                        new \Exception($result['error']),
                        $format,
                        ['action' => 'export_report', 'report_id' => $id]
                    ),
                    500
                );
            }
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleExportError($e, $request->input('format', 'unknown'), [
                    'action'    => 'export_report',
                    'report_id' => $id,
                ]),
                500
            );
        }
    }

    /**
     * Export query results directly without saving as report.
     */
    public function exportQuery(Request $request): JsonResponse
    {
        try {
            $queryConfig = $request->input('query_config');
            $format      = $request->input('format', 'csv');
            $options     = $request->input('options', []);

            if (!$queryConfig) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'INVALID_CONFIGURATION',
                        'message' => 'Query configuration is required',
                    ],
                ], 400);
            }

            // Validate query configuration
            $validationResult = $this->queryValidator->validate($queryConfig);
            if (!$validationResult['valid']) {
                return response()->json(
                    $this->errorHandler->handleValidationError($validationResult, [
                        'action'       => 'export_query',
                        'query_config' => $queryConfig,
                    ]),
                    400
                );
            }

            // Get the query converter
            $registry  = app(ReportSchemaRegistry::class);
            $converter = new ReportQueryConverter($registry, $queryConfig);

            // Validate format
            $allowedFormats = array_keys($converter->getAvailableExportFormats());
            if (!in_array($format, $allowedFormats)) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'            => 'INVALID_CONFIGURATION',
                        'message'         => 'Invalid export format',
                        'allowed_formats' => $allowedFormats,
                    ],
                ], 400);
            }

            // Export the data
            $result = $converter->export($format, $options);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleExportError($e, $request->input('format', 'unknown'), [
                    'action' => 'export_query',
                ]),
                500
            );
        }
    }

    /**
     * Download exported file.
     */
    public function download(Request $request, string $filename)
    {
        try {
            $filepath = storage_path('app/exports/' . $filename);

            if (!file_exists($filepath)) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'FILE_NOT_FOUND',
                        'message' => 'Export file not found',
                    ],
                ], 404);
            }

            // Security check - ensure filename doesn't contain path traversal
            if (str_contains($filename, '..') || str_contains($filename, '/')) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'INVALID_FILENAME',
                        'message' => 'Invalid filename',
                    ],
                ], 400);
            }

            // Determine content type
            $extension    = pathinfo($filename, PATHINFO_EXTENSION);
            $contentTypes = [
                'csv'  => 'text/csv',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'json' => 'application/json',
                'pdf'  => 'application/pdf',
                'xml'  => 'application/xml',
            ];

            $contentType = $contentTypes[$extension] ?? 'application/octet-stream';

            return response()->download($filepath, $filename, [
                'Content-Type'  => $contentType,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma'        => 'no-cache',
                'Expires'       => '0',
            ]);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action'   => 'download_export',
                    'filename' => $filename,
                ]),
                500
            );
        }
    }

    /**
     * Get query analysis information.
     */
    public function analyzeQuery(Request $request): JsonResponse
    {
        try {
            $queryConfig = $request->input('query_config');

            if (!$queryConfig) {
                return response()->json([
                    'success' => false,
                    'error'   => [
                        'code'    => 'INVALID_CONFIGURATION',
                        'message' => 'Query configuration is required',
                    ],
                ], 400);
            }

            // Get the query converter for analysis
            $registry  = app(ReportSchemaRegistry::class);
            $converter = new ReportQueryConverter($registry, $queryConfig);

            $analysis         = $converter->getQueryAnalysis();
            $validationResult = $this->queryValidator->validate($queryConfig);

            return response()->json([
                'success'         => true,
                'analysis'        => $analysis,
                'validation'      => $validationResult,
                'recommendations' => $this->getQueryRecommendations($analysis, $validationResult),
                'meta'            => [
                    'analyzed_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action' => 'analyze_query',
                ]),
                500
            );
        }
    }

    /**
     * Get available export formats.
     */
    public function getExportFormats(Request $request): JsonResponse
    {
        try {
            $registry  = app(ReportSchemaRegistry::class);
            $converter = new ReportQueryConverter($registry, ['table' => ['name' => 'dummy'], 'columns' => []]);
            $formats   = $converter->getAvailableExportFormats();

            return response()->json([
                'success' => true,
                'formats' => $formats,
                'meta'    => [
                    'total_formats' => count($formats),
                    'timestamp'     => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action' => 'get_export_formats',
                ]),
                500
            );
        }
    }

    /**
     * Get query recommendations based on analysis.
     */
    protected function getQueryRecommendations(array $analysis, array $validationResult): array
    {
        $recommendations = [];

        // Performance recommendations
        if ($analysis['complexity'] === 'complex') {
            $recommendations[] = [
                'type'        => 'performance',
                'priority'    => 'high',
                'message'     => 'Consider simplifying the query to improve performance',
                'suggestions' => [
                    'Reduce the number of selected columns',
                    'Add more specific filters',
                    'Remove unnecessary joins',
                ],
            ];
        }

        if ($analysis['joins_count'] > 3) {
            $recommendations[] = [
                'type'        => 'performance',
                'priority'    => 'medium',
                'message'     => 'Multiple joins may impact performance',
                'suggestions' => [
                    'Consider if all joins are necessary',
                    'Ensure join conditions use indexed columns',
                ],
            ];
        }

        if ($analysis['selected_columns_count'] > 20) {
            $recommendations[] = [
                'type'        => 'performance',
                'priority'    => 'medium',
                'message'     => 'Selecting many columns may slow down the query',
                'suggestions' => [
                    'Select only the columns you need',
                    'Consider creating multiple smaller reports',
                ],
            ];
        }

        // Add validation-based recommendations
        if (!empty($validationResult['warnings'])) {
            $recommendations[] = [
                'type'        => 'validation',
                'priority'    => 'low',
                'message'     => 'Query has validation warnings',
                'suggestions' => $validationResult['warnings'],
            ];
        }

        return $recommendations;
    }

    /**
     * Validate a computed column expression.
     */
    public function validateComputedColumn(Request $request): JsonResponse
    {
        try {
            $expression = $request->input('expression');
            $tableName  = $request->input('table_name');

            if (!$expression) {
                return response()->json([
                    'valid'  => false,
                    'errors' => ['Expression is required'],
                ], 400);
            }

            if (!$tableName) {
                return response()->json([
                    'valid'  => false,
                    'errors' => ['Table name is required'],
                ], 400);
            }

            // Get the registry and create validator
            $registry  = app(ReportSchemaRegistry::class);
            $validator = new ComputedColumnValidator($registry);

            // Validate the expression
            $validationResult = $validator->validate($expression, $tableName);

            if ($validationResult['valid']) {
                return response()->json([
                    'valid'   => true,
                    'message' => 'Expression is valid',
                    'meta'    => [
                        'expression'  => $expression,
                        'table_name'  => $tableName,
                        'timestamp'   => now()->toISOString(),
                    ],
                ]);
            } else {
                return response()->json([
                    'valid'  => false,
                    'errors' => $validationResult['errors'],
                    'meta'   => [
                        'expression' => $expression,
                        'table_name' => $tableName,
                        'timestamp'  => now()->toISOString(),
                    ],
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json(
                $this->errorHandler->handleError($e, [
                    'action'     => 'validate_computed_column',
                    'expression' => $request->input('expression'),
                    'table_name' => $request->input('table_name'),
                ]),
                500
            );
        }
    }
}
