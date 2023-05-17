<?php

namespace Fleetbase\Exports;

use Fleetbase\Models\Group;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class GroupExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{

    /**
     * @return array
     */
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

    /**
     * @return array
     */
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
        return Group::where('company_uuid', session('company'))->get();
    }
}

