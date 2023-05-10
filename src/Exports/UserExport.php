<?php

namespace Fleetbase\Exports;

use Fleetbase\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class UserExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{

    /**
     * @return array
     */
    public function map($user): array
    {
        return [
            $user->name,
            $user->company_name,
            $user->last_login,
            $user->email_verified_at ? Date::dateTimeToExcel($user->email_verified_at) : 'Never',
            Date::dateTimeToExcel($user->created_at),
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
        return User::where('company_uuid', session('company'))->get();
    }
}
