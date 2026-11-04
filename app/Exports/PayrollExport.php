<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class PayrollExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithTitle
{
    public function __construct(private Collection $payrolls, private string $title)
    {
    }

    public function collection(): Collection
    {
        return $this->payrolls;
    }

    public function headings(): array
    {
        return [
            'NIP',
            'Nama',
            'Departemen',
            'Jabatan',
            'Gaji Pokok',
            'Total Pendapatan',
            'Total Potongan',
            'Gaji Bersih',
            'Status',
        ];
    }

    public function map($payroll): array
    {
        return [
            $payroll->employee->nip,
            $payroll->employee->full_name,
            $payroll->employee->department?->name,
            $payroll->employee->position?->name,
            (float) $payroll->base_salary,
            (float) $payroll->total_earnings,
            (float) $payroll->total_deductions,
            (float) $payroll->net_salary,
            $payroll->status === 'final' ? 'Final' : 'Draft',
        ];
    }

    public function title(): string
    {
        return $this->title;
    }
}