<?php

namespace Fleetbase\Support\Reporting;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportQueryExporter
{
    protected array $data;
    protected array $columns;
    protected array $metadata;
    protected string $tableName;

    public function __construct(array $data, array $columns, array $metadata = [], string $tableName = 'report')
    {
        $this->data      = $data;
        $this->columns   = $columns;
        $this->metadata  = $metadata;
        $this->tableName = $tableName;
    }

    /**
     * Export data in the specified format.
     */
    public function export(string $format, array $options = []): array
    {
        switch (strtolower($format)) {
            case 'csv':
                return $this->exportToCsv($options);
            case 'excel':
            case 'xlsx':
                return $this->exportToExcel($options);
            case 'json':
                return $this->exportToJson($options);
            case 'pdf':
                return $this->exportToPdf($options);
            case 'xml':
                return $this->exportToXml($options);
            default:
                throw new \InvalidArgumentException("Unsupported export format: {$format}");
        }
    }

    /**
     * Export to CSV format.
     */
    protected function exportToCsv(array $options = []): array
    {
        $fileName = $this->generateFileName('csv');
        $filePath = $this->getExportPath($fileName);

        $this->ensureExportDirectory();

        $handle = fopen($filePath, 'w');

        // Add BOM for UTF-8 support in Excel
        if ($options['include_bom'] ?? true) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        // Write headers
        $headers = array_column($this->columns, 'label');
        fputcsv($handle, $headers, $options['delimiter'] ?? ',', $options['enclosure'] ?? '"');

        // Write data
        foreach ($this->data as $row) {
            $rowData = [];
            foreach ($this->columns as $column) {
                $key   = $column['key'];
                $value = $row->{$key} ?? $row[$key] ?? '';

                // Format value based on column type
                $value     = $this->formatCellValue($value, $column);
                $rowData[] = $value;
            }
            fputcsv($handle, $rowData, $options['delimiter'] ?? ',', $options['enclosure'] ?? '"');
        }

        fclose($handle);

        return $this->buildExportResponse('csv', $fileName, $filePath);
    }

    /**
     * Export to Excel format with advanced formatting.
     */
    protected function exportToExcel(array $options = []): array
    {
        $fileName = $this->generateFileName('xlsx');
        $filePath = $this->getExportPath($fileName);

        $this->ensureExportDirectory();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        // Set sheet name
        $sheetName = $options['sheet_name'] ?? Str::title($this->tableName);
        $sheet->setTitle(substr($sheetName, 0, 31)); // Excel limit

        // Add metadata sheet if requested
        if ($options['include_metadata'] ?? true) {
            $this->addMetadataSheet($spreadsheet);
        }

        // Style headers
        $headerStyle = [
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 12,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => '000000'],
                ],
            ],
        ];

        // Set headers
        $columnIndex = 1;
        foreach ($this->columns as $column) {
            $cellCoordinate = $sheet->getCellByColumnAndRow($columnIndex, 1);
            $cellCoordinate->setValue($column['label']);
            $columnIndex++;
        }

        // Apply header styling
        $headerRange = 'A1:' . $sheet->getCellByColumnAndRow(count($this->columns), 1)->getCoordinate();
        $sheet->getStyle($headerRange)->applyFromArray($headerStyle);

        // Set data
        $rowIndex = 2;
        foreach ($this->data as $row) {
            $columnIndex = 1;
            foreach ($this->columns as $column) {
                $key   = $column['key'];
                $value = $row->{$key} ?? $row[$key] ?? '';

                // Format and set value
                $formattedValue = $this->formatCellValue($value, $column);
                $cell           = $sheet->getCellByColumnAndRow($columnIndex, $rowIndex);
                $cell->setValue($formattedValue);

                // Apply column-specific formatting
                $this->applyCellFormatting($cell, $column, $formattedValue);

                $columnIndex++;
            }
            $rowIndex++;
        }

        // Auto-size columns
        foreach (range(1, count($this->columns)) as $columnIndex) {
            $columnLetter = $sheet->getCellByColumnAndRow($columnIndex, 1)->getColumn();
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        // Add data borders
        if ($rowIndex > 2) {
            $dataRange = 'A2:' . $sheet->getCellByColumnAndRow(count($this->columns), $rowIndex - 1)->getCoordinate();
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => 'CCCCCC'],
                    ],
                ],
            ]);
        }

        // Freeze header row
        $sheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $this->buildExportResponse('excel', $fileName, $filePath);
    }

    /**
     * Export to JSON format.
     */
    protected function exportToJson(array $options = []): array
    {
        $fileName = $this->generateFileName('json');
        $filePath = $this->getExportPath($fileName);

        $this->ensureExportDirectory();

        $exportData = [
            'metadata' => array_merge($this->metadata, [
                'exported_at'   => now()->toISOString(),
                'total_rows'    => count($this->data),
                'columns_count' => count($this->columns),
                'format'        => 'json',
            ]),
            'columns' => $this->columns,
            'data'    => $this->data,
        ];

        $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
        if ($options['compact'] ?? false) {
            $jsonOptions = JSON_UNESCAPED_UNICODE;
        }

        file_put_contents($filePath, json_encode($exportData, $jsonOptions));

        return $this->buildExportResponse('json', $fileName, $filePath);
    }

    /**
     * Export to PDF format.
     */
    protected function exportToPdf(array $options = []): array
    {
        $fileName = $this->generateFileName('pdf');
        $filePath = $this->getExportPath($fileName);

        $this->ensureExportDirectory();

        // Create HTML content for PDF conversion
        $html = $this->generatePdfHtml($options);

        // Use a PDF library like DomPDF or wkhtmltopdf
        // For now, we'll create a simple HTML file that can be converted
        $htmlFileName = str_replace('.pdf', '.html', $fileName);
        $htmlFilePath = $this->getExportPath($htmlFileName);
        file_put_contents($htmlFilePath, $html);

        // Note: In a real implementation, you would use a PDF library here
        // For example: $pdf = new Dompdf(); $pdf->loadHtml($html); $pdf->render(); file_put_contents($filePath, $pdf->output());

        return $this->buildExportResponse('pdf', $fileName, $filePath, [
            'html_file' => $htmlFileName,
            'note'      => 'PDF generation requires additional PDF library integration',
        ]);
    }

    /**
     * Export to XML format.
     */
    protected function exportToXml(array $options = []): array
    {
        $fileName = $this->generateFileName('xml');
        $filePath = $this->getExportPath($fileName);

        $this->ensureExportDirectory();

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><report></report>');

        // Add metadata
        $metadataNode = $xml->addChild('metadata');
        foreach (array_merge($this->metadata, ['exported_at' => now()->toISOString()]) as $key => $value) {
            $metadataNode->addChild($key, htmlspecialchars($value));
        }

        // Add columns
        $columnsNode = $xml->addChild('columns');
        foreach ($this->columns as $column) {
            $columnNode = $columnsNode->addChild('column');
            foreach ($column as $key => $value) {
                $columnNode->addChild($key, htmlspecialchars($value));
            }
        }

        // Add data
        $dataNode = $xml->addChild('data');
        foreach ($this->data as $row) {
            $rowNode = $dataNode->addChild('row');
            foreach ($this->columns as $column) {
                $key   = $column['key'];
                $value = $row->{$key} ?? $row[$key] ?? '';
                $rowNode->addChild($key, htmlspecialchars($this->formatCellValue($value, $column)));
            }
        }

        // Format XML
        $dom               = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        file_put_contents($filePath, $dom->saveXML());

        return $this->buildExportResponse('xml', $fileName, $filePath);
    }

    /**
     * Format cell value based on column type.
     */
    protected function formatCellValue($value, array $column)
    {
        if ($value === null || $value === '') {
            return '';
        }

        $type = $column['type'] ?? 'string';

        switch ($type) {
            case 'date':
                return $this->formatDate($value);
            case 'datetime':
                return $this->formatDateTime($value);
            case 'number':
            case 'decimal':
                return is_numeric($value) ? (float) $value : $value;
            case 'currency':
                return $this->formatCurrency($value);
            case 'percentage':
                return $this->formatPercentage($value);
            case 'boolean':
                return $value ? 'Yes' : 'No';
            default:
                return (string) $value;
        }
    }

    /**
     * Apply cell formatting for Excel.
     */
    protected function applyCellFormatting($cell, array $column, $value): void
    {
        $type = $column['type'] ?? 'string';

        switch ($type) {
            case 'date':
                $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                break;
            case 'datetime':
                $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm:ss');
                break;
            case 'number':
                $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0');
                $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                break;
            case 'decimal':
                $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
                $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                break;
            case 'currency':
                $cell->getStyle()->getNumberFormat()->setFormatCode('$#,##0.00');
                $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                break;
            case 'percentage':
                $cell->getStyle()->getNumberFormat()->setFormatCode('0.00%');
                $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                break;
        }
    }

    /**
     * Add metadata sheet to Excel workbook.
     */
    protected function addMetadataSheet(Spreadsheet $spreadsheet): void
    {
        $metadataSheet = $spreadsheet->createSheet();
        $metadataSheet->setTitle('Metadata');

        $row = 1;
        foreach (array_merge($this->metadata, [
            'exported_at'   => now()->toISOString(),
            'total_rows'    => count($this->data),
            'columns_count' => count($this->columns),
        ]) as $key => $value) {
            $metadataSheet->setCellValue("A{$row}", Str::title(str_replace('_', ' ', $key)));
            $metadataSheet->setCellValue("B{$row}", $value);
            $row++;
        }

        // Style metadata sheet
        $metadataSheet->getStyle('A:A')->getFont()->setBold(true);
        $metadataSheet->getColumnDimension('A')->setAutoSize(true);
        $metadataSheet->getColumnDimension('B')->setAutoSize(true);
    }

    /**
     * Generate HTML content for PDF export.
     */
    protected function generatePdfHtml(array $options = []): string
    {
        $title = $options['title'] ?? Str::title($this->tableName) . ' Report';

        $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>{$title}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; border-bottom: 2px solid #4472C4; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background-color: #4472C4; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .metadata { margin-bottom: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <h1>{$title}</h1>
    <div class='metadata'>
        Generated on: " . now()->format('Y-m-d H:i:s') . '<br>
        Total rows: ' . count($this->data) . '<br>
        Columns: ' . count($this->columns) . '
    </div>
    <table>
        <thead>
            <tr>';

        foreach ($this->columns as $column) {
            $html .= '<th>' . htmlspecialchars($column['label']) . '</th>';
        }

        $html .= '</tr>
        </thead>
        <tbody>';

        foreach ($this->data as $row) {
            $html .= '<tr>';
            foreach ($this->columns as $column) {
                $key            = $column['key'];
                $value          = $row->{$key} ?? $row[$key] ?? '';
                $formattedValue = $this->formatCellValue($value, $column);
                $html .= '<td>' . htmlspecialchars($formattedValue) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>
    </table>
</body>
</html>';

        return $html;
    }

    /**
     * Format date value.
     */
    protected function formatDate($value): string
    {
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return (string) $value;
        }
    }

    /**
     * Format datetime value.
     */
    protected function formatDateTime($value): string
    {
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return (string) $value;
        }
    }

    /**
     * Format currency value.
     */
    protected function formatCurrency($value, string $currency = 'USD'): string
    {
        return Utils::moneyFormat($value, $currency);
    }

    /**
     * Format percentage value.
     */
    protected function formatPercentage($value): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        return number_format((float) $value * 100, 2) . '%';
    }

    /**
     * Generate unique filename.
     */
    protected function generateFileName(string $extension): string
    {
        $timestamp = date('Y-m-d-His');
        $tableName = Str::slug($this->tableName);

        return "report-{$tableName}-{$timestamp}.{$extension}";
    }

    /**
     * Get export file path.
     */
    protected function getExportPath(string $fileName): string
    {
        return storage_path("app/exports/{$fileName}");
    }

    /**
     * Ensure export directory exists.
     */
    protected function ensureExportDirectory(): void
    {
        $exportDir = storage_path('app/exports');
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
    }

    /**
     * Build export response.
     */
    protected function buildExportResponse(string $format, string $fileName, string $filePath, array $additional = []): array
    {
        return array_merge([
            'success'      => true,
            'format'       => $format,
            'filename'     => $fileName,
            'filepath'     => $filePath,
            'size'         => file_exists($filePath) ? filesize($filePath) : 0,
            'rows'         => count($this->data),
            'url'          => url("v1/reports/export-download/{$fileName}"),
            'download_url' => route('reports.download', ['filename' => $fileName]),
        ], $additional);
    }

    /**
     * Get supported export formats.
     */
    public static function getSupportedFormats(): array
    {
        return [
            'csv' => [
                'label'       => 'CSV (Comma Separated Values)',
                'mime_type'   => 'text/csv',
                'extension'   => 'csv',
                'description' => 'Simple text format compatible with Excel and other spreadsheet applications',
            ],
            'excel' => [
                'label'       => 'Excel Spreadsheet',
                'mime_type'   => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension'   => 'xlsx',
                'description' => 'Microsoft Excel format with advanced formatting and multiple sheets',
            ],
            'json' => [
                'label'       => 'JSON (JavaScript Object Notation)',
                'mime_type'   => 'application/json',
                'extension'   => 'json',
                'description' => 'Structured data format ideal for API consumption and data processing',
            ],
            'pdf' => [
                'label'       => 'PDF Document',
                'mime_type'   => 'application/pdf',
                'extension'   => 'pdf',
                'description' => 'Portable document format for sharing and printing',
            ],
            'xml' => [
                'label'       => 'XML (Extensible Markup Language)',
                'mime_type'   => 'application/xml',
                'extension'   => 'xml',
                'description' => 'Structured markup format for data exchange',
            ],
        ];
    }
}
