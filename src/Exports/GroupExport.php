<?php

namespace Fleetbase\Exports;

use Fleetbase\Models\Group;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class GroupExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function map($group): array
    {
        return [
            $group->name,
            $group->company_name,
            $group->last_login,
            $group->email_verified_at ? Date::dateTimeToExcel($group->email_verified_at) : 'Never',
            Date::dateTimeToExcel($group->created_at),
        ];
    }

    public function headings(): array
    {
        return [
            'Name',
            'Company',
            'Last Login',
            'Email Verified At',
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
        return Group::where('company_uuid', session('company'))->get();
    }
}
