<?php

namespace Fleetbase\Exports;

use Fleetbase\Models\ApiCredential;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ApiCredentialExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{

    /**
     * @return array
     */
    public function map($apiCredential): array
    {
        return [
            $apiCredential->name,
            $apiCredential->key,
            $apiCredential->secret,
            $apiCredential->test_mode ? 'Test' : 'Live',
            $apiCredential->expires_at ? Date::dateTimeToExcel($apiCredential->expires_at) : 'Never',
            $apiCredential->last_used_at ? Date::dateTimeToExcel($apiCredential->last_used_at) : 'Never',
            Date::dateTimeToExcel($apiCredential->created_at),
        ];
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Name',
            'Public Key',
            'Secret Key',
            'Environment',
            'Expiry',
            'Last Used',
            'Created',
        ];
    }

    /**
     * @return array
     */
    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'F' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'G' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return ApiCredential::where('company_uuid', session('company'))->get();
    }
}
