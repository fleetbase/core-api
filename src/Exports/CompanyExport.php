<?php

namespace Fleetbase\Exports;

use Fleetbase\Models\Company;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CompanyExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function map($company): array
    {
        return [
            $company->name,
            data_get($company, 'owner.name'),
            data_get($company, 'owner.email'),
            data_get($company, 'owner.phone'),
            data_get($company, 'owner.user'),
            $company->created_at ? Date::dateTimeToExcel($company->created_at) : 'N/A',
        ];
    }

    public function headings(): array
    {
        return [
            'Name',
            'Owner',
            'Email',
            'Phone',
            'User',
            'Created',
        ];
    }

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
        if ($this->selections) {
            return Company::where('owner_uuid', session('user'))->whereIn('uuid', $this->selections)->get();
        }

        return Company::where('owner_uuid', session('user'))->get();
    }
}
