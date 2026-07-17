<?php

use Fleetbase\Support\Reporting\ReportQueryExporter;
use Illuminate\Support\Carbon;

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
