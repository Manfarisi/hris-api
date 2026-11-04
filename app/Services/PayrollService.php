<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollComponent;
use App\Support\AttendanceStats;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    /**
     * Hitung (atau hitung ulang) draft payroll satu periode.
     * Payroll yang sudah final tidak pernah disentuh.
     *
     * @return array{created:int, updated:int, skipped_final:int, skipped_not_eligible:int}
     */
    public function generate(int $month, int $year, ?int $employeeId = null): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $today = now()->startOfDay();

        if ($start->gt($today)) {
            $this->fail('month', 'Periode payroll tidak boleh di masa depan.');
        }

        if ($end->gte($today) && ! config('hris.payroll.allow_open_month')) {
            $this->fail('month', 'Payroll hanya bisa dibuat setelah bulan tersebut berakhir.');
        }

        // Untuk bulan yang masih berjalan, absensi hanya dihitung sampai hari ini
        $cutoff = $end->gt($today) ? $today : $end;

        $components = PayrollComponent::orderBy('id')->get();

        $employees = Employee::where('status', 'active')
            ->when($employeeId, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')
            ->get();

        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped_final' => 0,
            'skipped_not_eligible' => 0,
        ];

        foreach ($employees as $employee) {
            $result = DB::transaction(
                fn () => $this->generateFor($employee, $start, $end, $cutoff, $components, $month, $year)
            );

            $summary[$result]++;
        }

        return $summary;
    }

    /** Ubah semua draft di periode itu menjadi final. Mengembalikan jumlah yang difinalkan. */
    public function finalize(int $month, int $year, ?int $employeeId = null): int
    {
        return Payroll::where('period_month', $month)
            ->where('period_year', $year)
            ->where('status', 'draft')
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->update(['status' => 'final']);
    }

    // ---------- Internal ----------

    private function generateFor(
        Employee $employee,
        Carbon $start,
        Carbon $end,
        Carbon $cutoff,
        Collection $components,
        int $month,
        int $year
    ): string {
        $fullDays = AttendanceStats::workingDays($start, $end);

        // Karyawan yang join di tengah bulan dihitung mulai tanggal join
        $from = $employee->join_date && $employee->join_date->gt($start)
            ? $employee->join_date->copy()->startOfDay()
            : $start->copy();

        $eligibleDays = AttendanceStats::workingDays($from, $end);
        $elapsedDays = AttendanceStats::workingDays($from, $cutoff);

        if ($fullDays === 0 || $eligibleDays === 0) {
            return 'skipped_not_eligible';
        }

        $payroll = Payroll::where('employee_id', $employee->id)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->lockForUpdate()
            ->first();

        if ($payroll && $payroll->status === 'final') {
            return 'skipped_final';
        }

        $records = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();

        $stats = AttendanceStats::summarize($records);

        $fullBase = (float) $employee->base_salary;

        // Gaji pokok diproporsikan untuk karyawan yang join di tengah bulan
        $base = (int) round($fullBase * $eligibleDays / $fullDays);

        $absentDays = max(0, $elapsedDays - $stats['attended_days'] - $stats['leave_days']);

        $items = [];

        foreach ($components as $component) {
            $amount = $component->calc_type === 'percent'
                ? (int) round($base * (float) $component->value / 100)
                : (int) round((float) $component->value);

            if ($amount > 0) {
                $items[] = ['name' => $component->name, 'type' => $component->type, 'amount' => $amount];
            }
        }

        [$overtimeHours, $overtimePay] = $this->overtime($records, $fullBase);

        if ($overtimePay > 0) {
            $items[] = [
                'name' => "Lembur ({$overtimeHours} jam)",
                'type' => 'earning',
                'amount' => $overtimePay,
            ];
        }

        $absenceDeduction = (int) round($fullBase / $fullDays * $absentDays);

        if ($absenceDeduction > 0) {
            $items[] = [
                'name' => "Potongan Absen ({$absentDays} hari)",
                'type' => 'deduction',
                'amount' => $absenceDeduction,
            ];
        }

        $collection = collect($items);
        $earnings = (int) $collection->where('type', 'earning')->sum('amount');
        $deductions = (int) $collection->where('type', 'deduction')->sum('amount');

        $data = [
            'base_salary' => $base,
            'total_earnings' => $earnings,
            'total_deductions' => $deductions,
            'net_salary' => $base + $earnings - $deductions,
            'status' => 'draft',
        ];

        if ($payroll) {
            $payroll->update($data);
            $payroll->items()->delete(); // snapshot lama diganti hasil hitung terbaru
            $result = 'updated';
        } else {
            $payroll = Payroll::create($data + [
                'employee_id' => $employee->id,
                'period_month' => $month,
                'period_year' => $year,
            ]);
            $result = 'created';
        }

        $payroll->items()->createMany($items);

        return $result;
    }

    /**
     * Lembur dihitung dari jam pulang setelah jam kerja berakhir.
     * Hanya jam penuh yang dihitung, dengan batas per hari.
     *
     * @return array{0:int, 1:int} [total jam, total rupiah]
     */
    private function overtime(Collection $records, float $fullBase): array
    {
        $config = config('hris.payroll');
        $workEnd = $this->seconds(config('hris.work_end'));

        $hours = 0;
        $units = 0.0; // jumlah "kali upah per jam"

        foreach ($records as $record) {
            if (! $record->clock_out) {
                continue;
            }

            $minutes = intdiv($this->seconds($record->clock_out) - $workEnd, 60);

            if ($minutes < 60) {
                continue;
            }

            $dayHours = min(intdiv($minutes, 60), (int) $config['overtime_max_hours_per_day']);

            $hours += $dayHours;
            $units += $config['overtime_first_hour_multiplier']
                + ($dayHours - 1) * $config['overtime_next_hour_multiplier'];
        }

        $amount = (int) round($fullBase / $config['overtime_divisor'] * $units);

        return [$hours, $amount];
    }

    /** "HH:MM" atau "HH:MM:SS" menjadi jumlah detik sejak tengah malam */
    private function seconds(string $time): int
    {
        [$h, $m, $s] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $h * 3600 + $m * 60 + $s;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}