<?php

use Fleetbase\Support\Reporting\ReportQueryExporter;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

beforeEach(function () {
    bind_test_container();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function report_exporter_fixture(): ReportQueryExporter
{
    return new ReportQueryExporter(
        [
            [
                'public_id' => 'order_001',
                'total'     => 1234.5,
                'ratio'     => 0.2567,
                'active'    => true,
                'created'   => '2026-07-01 09:15:00',
            ],
            (object) [
                'public_id' => 'order_002',
                'total'     => 'not numeric',
                'ratio'     => 'n/a',
                'active'    => false,
                'created'   => 'invalid date',
            ],
        ],
        [
            ['key' => 'public_id', 'label' => 'Order ID', 'type' => 'string'],
            ['key' => 'total', 'label' => 'Total', 'type' => 'decimal'],
            ['key' => 'ratio', 'label' => 'Ratio', 'type' => 'percentage'],
            ['key' => 'active', 'label' => 'Active', 'type' => 'boolean'],
            ['key' => 'created', 'label' => 'Created', 'type' => 'datetime'],
        ],
        ['requested_by' => 'tester'],
        'orders'
    );
}

class ReportQueryExporterProbe extends ReportQueryExporter
{
    public function formatValue(mixed $value, array $column): mixed
    {
        return $this->formatCellValue($value, $column);
    }

    public function applyFormattingFor(array $column, mixed $value): array
    {
        $spreadsheet = new Spreadsheet();
        $cell        = $spreadsheet->getActiveSheet()->getCell('A1');

        $this->applyCellFormatting($cell, $column, $value);

        return [
            'format'    => $cell->getStyle()->getNumberFormat()->getFormatCode(),
            'alignment' => $cell->getStyle()->getAlignment()->getHorizontal(),
        ];
    }

    public function ensureDirectoryForTest(): void
    {
        $this->ensureExportDirectory();
    }
}

test('report query exporter writes csv with formatted values and response metadata', function () {
    $result = report_exporter_fixture()->export('csv', ['include_bom' => false]);

    expect($result['success'])->toBeTrue()
        ->and($result['format'])->toBe('csv')
        ->and($result['rows'])->toBe(2)
        ->and($result['filename'])->toStartWith('report-orders-')
        ->and($result['download_url'])->toContain('reports/download')
        ->and(file_exists($result['filepath']))->toBeTrue();

    $csv = file_get_contents($result['filepath']);

    expect($csv)->toContain('"Order ID",Total,Ratio,Active,Created')
        ->and($csv)->toContain('order_001')
        ->and($csv)->toContain('25.67%')
        ->and($csv)->toContain('Yes')
        ->and($csv)->toContain('No')
        ->and($csv)->toContain('invalid date');
});

test('report query exporter writes json xml and pdf placeholder exports', function () {
    $exporter = report_exporter_fixture();

    $json = $exporter->export('json');
    $xml  = $exporter->export('xml');
    $pdf  = $exporter->export('pdf', ['title' => 'Operations Report']);

    expect($json['format'])->toBe('json')
        ->and(json_decode(file_get_contents($json['filepath']), true)['metadata']['total_rows'])->toBe(2)
        ->and($xml['format'])->toBe('xml')
        ->and(file_get_contents($xml['filepath']))->toContain('<report>')
        ->and($pdf['format'])->toBe('pdf')
        ->and($pdf['note'])->toContain('PDF generation requires')
        ->and(file_get_contents(dirname($pdf['filepath']) . '/' . $pdf['html_file']))->toContain('Operations Report');
});

test('report query exporter writes excel workbooks with metadata when requested', function () {
    $result = report_exporter_fixture()->export('xlsx', [
        'sheet_name'        => 'Operations',
        'include_metadata'  => true,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['format'])->toBe('excel')
        ->and($result['filename'])->toEndWith('.xlsx')
        ->and($result['size'])->toBeGreaterThan(0)
        ->and(file_exists($result['filepath']))->toBeTrue();
});

test('report query exporter exposes supported formats and rejects unsupported formats', function () {
    $formats = ReportQueryExporter::getSupportedFormats();

    expect(array_keys($formats))->toBe(['csv', 'excel', 'json', 'pdf', 'xml'])
        ->and($formats['excel']['extension'])->toBe('xlsx');

    report_exporter_fixture()->export('yaml');
})->throws(InvalidArgumentException::class, 'Unsupported export format: yaml');

test('report query exporter formats cell values and excel styles by declared column type', function () {
    $exporter = new ReportQueryExporterProbe([], [], [], 'orders');

    expect($exporter->formatValue(null, ['type' => 'string']))->toBe('')
        ->and($exporter->formatValue('', ['type' => 'string']))->toBe('')
        ->and($exporter->formatValue('2026-07-17 12:34:56', ['type' => 'date']))->toBe('2026-07-17')
        ->and($exporter->formatValue('not-a-date', ['type' => 'date']))->toBe('not-a-date')
        ->and($exporter->formatValue('2026-07-17 12:34:56', ['type' => 'datetime']))->toBe('2026-07-17 12:34:56')
        ->and($exporter->formatValue('not-a-datetime', ['type' => 'datetime']))->toBe('not-a-datetime')
        ->and($exporter->formatValue('42', ['type' => 'number']))->toBe(42.0)
        ->and($exporter->formatValue('42.75', ['type' => 'decimal']))->toBe(42.75)
        ->and($exporter->formatValue('not numeric', ['type' => 'number']))->toBe('not numeric')
        ->and($exporter->formatValue(1234.56, ['type' => 'currency']))->toBe('$1,234.56')
        ->and($exporter->formatValue('', ['type' => 'currency']))->toBe('')
        ->and($exporter->formatValue('n/a', ['type' => 'percentage']))->toBe('n/a')
        ->and($exporter->formatValue(false, ['type' => 'boolean']))->toBe('No')
        ->and($exporter->formatValue('plain', []))->toBe('plain')
        ->and($exporter->applyFormattingFor(['type' => 'date'], '2026-07-17')['format'])->toBe('yyyy-mm-dd')
        ->and($exporter->applyFormattingFor(['type' => 'datetime'], '2026-07-17 12:34:56')['format'])->toBe('yyyy-mm-dd hh:mm:ss')
        ->and($exporter->applyFormattingFor(['type' => 'number'], 42))->toMatchArray(['format' => '#,##0', 'alignment' => 'right'])
        ->and($exporter->applyFormattingFor(['type' => 'decimal'], 42.75))->toMatchArray(['format' => '#,##0.00', 'alignment' => 'right'])
        ->and($exporter->applyFormattingFor(['type' => 'currency'], 12.34))->toMatchArray(['format' => '$#,##0.00', 'alignment' => 'right'])
        ->and($exporter->applyFormattingFor(['type' => 'percentage'], 0.25))->toMatchArray(['format' => '0.00%', 'alignment' => 'right']);
});

test('report query exporter creates the export directory when missing', function () {
    $exportDirectory = storage_path('app/exports');

    if (is_dir($exportDirectory)) {
        foreach (glob($exportDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($exportDirectory);
    }

    expect(is_dir($exportDirectory))->toBeFalse();

    (new ReportQueryExporterProbe([], [], [], 'orders'))->ensureDirectoryForTest();

    expect(is_dir($exportDirectory))->toBeTrue();
});
