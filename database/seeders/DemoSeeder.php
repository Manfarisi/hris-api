<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Data demo: 20 karyawan di 5 departemen, absensi 2 bulan terakhir sampai kemarin,
 * riwayat dan pengajuan cuti dalam berbagai status, serta payroll
 * (dua bulan lalu sudah final, bulan lalu masih draft).
 *
 * Pakai: php artisan migrate:fresh --seed --seeder=DemoSeeder
 * Semua akun demo berpassword "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Employee::where('nip', 'EMP010')->exists()) {
            $this->command?->warn('Data demo sudah ada. Pakai migrate:fresh --seed --seeder=DemoSeeder untuk mengulang dari nol.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        // Angka acak dikunci supaya hasilnya selalu sama
        mt_srand(20261104);

        $this->createEmployees();
        $this->createBalances();
        $this->createAttendance();
        $this->createHistoricLeaves();
        $this->createLeaveFlows();
        $this->createPayrolls();

        $this->command?->info(sprintf(
            'Demo siap: %d karyawan, %d absensi, %d pengajuan cuti, %d payroll.',
            Employee::count(),
            Attendance::count(),
            LeaveRequest::count(),
            Payroll::count()
        ));
    }

    // ---------- Karyawan ----------

    private function createEmployees(): void
    {
        foreach ([
            'Finance Manager', 'Marketing Manager', 'Operations Manager',
            'UI/UX Designer', 'QA Engineer', 'Accountant',
        ] as $name) {
            Position::firstOrCreate(['name' => $name]);
        }

        // Karyawan baru yang join di tengah bulan lalu (buat demo gaji proporsional)
        $newJoiner = now()->subMonthNoOverflow()->startOfMonth()->addDays(10);

        while ($newJoiner->isWeekend()) {
            $newJoiner->addDay();
        }

        // [nip, nama, email, role, departemen, jabatan, nip atasan, gaji pokok, tanggal join]
        $people = [
            ['EMP010', 'Dian Kusuma', 'dian@hris.test', User::ROLE_MANAGER, 'Finance', 'Finance Manager', null, 16000000, '2023-03-01'],
            ['EMP011', 'Hendra Wijaya', 'hendra@hris.test', User::ROLE_MANAGER, 'Marketing', 'Marketing Manager', null, 15000000, '2023-06-12'],
            ['EMP012', 'Maya Anggraini', 'maya@hris.test', User::ROLE_MANAGER, 'Operations', 'Operations Manager', null, 15500000, '2022-11-07'],

            ['EMP013', 'Fajar Nugroho', 'fajar@hris.test', User::ROLE_EMPLOYEE, 'IT', 'Software Engineer', 'EMP003', 11000000, '2024-05-20'],
            ['EMP014', 'Putri Ayu', 'putri@hris.test', User::ROLE_EMPLOYEE, 'IT', 'UI/UX Designer', 'EMP003', 9000000, '2024-08-05'],
            ['EMP015', 'Yoga Pratama', 'yoga@hris.test', User::ROLE_EMPLOYEE, 'IT', 'QA Engineer', 'EMP003', 8500000, '2025-02-10'],
            ['EMP024', 'Kevin Mahendra', 'kevin@hris.test', User::ROLE_EMPLOYEE, 'IT', 'Software Engineer', 'EMP003', 9500000, $newJoiner->toDateString()],

            ['EMP016', 'Lestari Dewi', 'lestari@hris.test', User::ROLE_EMPLOYEE, 'Finance', 'Accountant', 'EMP010', 8500000, '2024-01-22'],
            ['EMP017', 'Rizky Aditya', 'rizky@hris.test', User::ROLE_EMPLOYEE, 'Finance', 'Finance Staff', 'EMP010', 7500000, '2025-04-14'],

            ['EMP018', 'Nadia Putri', 'nadia@hris.test', User::ROLE_EMPLOYEE, 'Marketing', 'Marketing Staff', 'EMP011', 7500000, '2024-09-02'],
            ['EMP019', 'Bayu Setiawan', 'bayu@hris.test', User::ROLE_EMPLOYEE, 'Marketing', 'Marketing Staff', 'EMP011', 7200000, '2025-06-16'],

            ['EMP020', 'Siti Rahma', 'siti@hris.test', User::ROLE_EMPLOYEE, 'Operations', 'Operations Staff', 'EMP012', 6800000, '2024-03-18'],
            ['EMP021', 'Agus Salim', 'agus@hris.test', User::ROLE_EMPLOYEE, 'Operations', 'Operations Staff', 'EMP012', 6500000, '2023-10-09'],
            ['EMP022', 'Wulan Sari', 'wulan@hris.test', User::ROLE_EMPLOYEE, 'Operations', 'Operations Staff', 'EMP012', 6500000, '2025-08-04'],

            ['EMP023', 'Tania Safitri', 'tania@hris.test', User::ROLE_EMPLOYEE, 'Human Resources', 'HR Staff', 'EMP002', 7000000, '2025-01-13'],
        ];

        foreach ($people as [$nip, $name, $email, $role, $department, $position, $managerNip, $salary, $join]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password', 'role' => $role]
            );

            Employee::updateOrCreate(['nip' => $nip], [
                'user_id' => $user->id,
                'full_name' => $name,
                'department_id' => Department::where('name', $department)->value('id'),
                'position_id' => Position::where('name', $position)->value('id'),
                'manager_id' => $managerNip ? Employee::where('nip', $managerNip)->value('id') : null,
                'join_date' => $join,
                'base_salary' => $salary,
                'phone' => '0812'.mt_rand(10000000, 99999999),
                'status' => 'active',
            ]);
        }
    }

    private function createBalances(): void
    {
        $years = collect([$this->windowStart()->year, now()->year])->unique();
        $types = LeaveType::all();

        foreach (Employee::all() as $employee) {
            foreach ($years as $year) {
                foreach ($types as $type) {
                    LeaveBalance::firstOrCreate(
                        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                        ['quota' => $type->default_quota, 'used' => 0]
                    );
                }
            }
        }
    }

    // ---------- Absensi ----------

    /** Hari kerja dua bulan terakhir sampai kemarin. Hari ini sengaja kosong supaya bisa dicoba clock in. */
    private function createAttendance(): void
    {
        $start = $this->windowStart()->startOfDay();
        $end = now()->subDay()->startOfDay();
        $office = OfficeLocation::firstOrFail();
        $stamp = now();
        $rows = [];

        foreach (Employee::where('status', 'active')->orderBy('id')->get() as $employee) {
            $from = $employee->join_date->gt($start)
                ? $employee->join_date->copy()->startOfDay()
                : $start->copy();

            for ($day = $from->copy(); $day->lte($end); $day->addDay()) {
                if (! $this->isWorkingDay($day)) {
                    continue;
                }

                $roll = mt_rand(1, 100);

                if ($roll <= 4) {
                    continue; // absen tanpa keterangan
                }

                $late = $roll <= 14;

                $clockIn = $late
                    ? sprintf('08:%02d:%02d', mt_rand(5, 50), mt_rand(0, 59))
                    : sprintf('07:%02d:%02d', mt_rand(35, 59), mt_rand(0, 59));

                $clockOut = mt_rand(1, 100) <= 8
                    ? sprintf('%02d:%02d:00', mt_rand(18, 20), mt_rand(5, 50)) // lembur
                    : sprintf('17:%02d:%02d', mt_rand(0, 25), mt_rand(0, 59));

                $rows[] = [
                    'employee_id' => $employee->id,
                    'date' => $day->toDateString(),
                    'clock_in' => $clockIn,
                    'clock_out' => $clockOut,
                    'in_lat' => round($office->latitude + mt_rand(-300, 300) / 1000000, 7),
                    'in_lng' => round($office->longitude + mt_rand(-300, 300) / 1000000, 7),
                    'out_lat' => round($office->latitude + mt_rand(-300, 300) / 1000000, 7),
                    'out_lng' => round($office->longitude + mt_rand(-300, 300) / 1000000, 7),
                    'status' => $late ? 'late' : 'present',
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Attendance::insert($chunk);
        }
    }

    // ---------- Cuti ----------

    /** Cuti yang sudah lewat dan disetujui penuh. Menimpa absensi di tanggal itu jadi status 'leave'. */
    private function createHistoricLeaves(): void
    {
        $m2 = $this->windowStart();
        $m1 = $m2->copy()->addMonthNoOverflow();

        $this->createApprovedLeave('EMP013', 'Cuti Tahunan', $m2->copy()->addDays(13), 3, 'Liburan keluarga');
        $this->createApprovedLeave('EMP004', 'Cuti Tahunan', $m1->copy()->addDays(10), 2, 'Acara keluarga');
        $this->createApprovedLeave('EMP016', 'Cuti Sakit', $m1->copy()->addDays(6), 2, 'Sakit demam');
        $this->createApprovedLeave('EMP018', 'Cuti Tahunan', $m1->copy()->addDays(19), 2, 'Urusan pribadi');
    }

    private function createApprovedLeave(string $nip, string $typeName, Carbon $from, int $days, string $reason): void
    {
        $employee = Employee::with('manager')->where('nip', $nip)->firstOrFail();
        $type = LeaveType::where('name', $typeName)->firstOrFail();
        $hrUserId = User::where('email', 'hr@hris.test')->value('id');

        $dates = [];
        $cursor = $from->copy();

        while (count($dates) < $days) {
            if ($this->isWorkingDay($cursor)) {
                $dates[] = $cursor->copy();
            }

            $cursor->addDay();
        }

        $start = $dates[0];
        $end = end($dates);

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_days' => $days,
            'reason' => $reason,
            'status' => 'approved',
            'current_step' => 2,
        ]);

        LeaveApproval::create([
            'leave_request_id' => $leave->id,
            'approver_id' => $employee->manager?->user_id,
            'step' => 1,
            'status' => 'approved',
            'notes' => 'Disetujui.',
            'acted_at' => $start->copy()->subDays(4)->setTime(10, 0),
        ]);

        LeaveApproval::create([
            'leave_request_id' => $leave->id,
            'approver_id' => $hrUserId,
            'step' => 2,
            'status' => 'approved',
            'acted_at' => $start->copy()->subDays(3)->setTime(14, 0),
        ]);

        LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $start->year)
            ->increment('used', $days);

        foreach ($dates as $date) {
            Attendance::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $date->toDateString()],
                [
                    'clock_in' => null,
                    'clock_out' => null,
                    'in_lat' => null,
                    'in_lng' => null,
                    'out_lat' => null,
                    'out_lng' => null,
                    'status' => 'leave',
                ]
            );
        }
    }

    /** Pengajuan yang sedang berjalan, lewat LeaveService yang sama dengan API */
    private function createLeaveFlows(): void
    {
        $service = app(LeaveService::class);
        $monday = now()->startOfDay()->next(Carbon::MONDAY);

        // 1. Menunggu persetujuan atasan
        $this->submitLeave($service, 'EMP005', 'Cuti Tahunan', $monday->copy()->addWeeks(2), 2, 'Mudik ke kampung halaman');

        // 2. Sudah disetujui atasan, menunggu HR
        $leave = $this->submitLeave($service, 'EMP014', 'Cuti Tahunan', $monday->copy(), 3, 'Mengurus dokumen keluarga');
        $service->approve($leave->id, $this->userOf('manager@hris.test'), 'Silakan, pastikan handover beres.');

        // 3. Disetujui penuh
        $leave = $this->submitLeave($service, 'EMP018', 'Cuti Menikah', $monday->copy()->addWeeks(3), 3, 'Pernikahan');
        $service->approve($leave->id, $this->userOf('hendra@hris.test'));
        $service->approve($leave->id, $this->userOf('hr@hris.test'));

        // 4. Ditolak atasan
        $leave = $this->submitLeave($service, 'EMP020', 'Cuti Tahunan', $monday->copy()->addWeek(), 2, 'Liburan');
        $service->reject($leave->id, $this->userOf('maya@hris.test'), 'Bertabrakan dengan audit operasional.');
    }

    private function submitLeave(LeaveService $service, string $nip, string $type, Carbon $start, int $days, string $reason): LeaveRequest
    {
        return $service->submit(
            Employee::where('nip', $nip)->firstOrFail(),
            [
                'leave_type_id' => LeaveType::where('name', $type)->value('id'),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
                'reason' => $reason,
            ]
        );
    }

    // ---------- Payroll ----------

    private function createPayrolls(): void
    {
        $m2 = $this->windowStart();
        $m1 = $m2->copy()->addMonthNoOverflow();
        $payrolls = app(PayrollService::class);

        // Dua bulan lalu: dihitung lalu dikunci final
        $payrolls->generate($m2->month, $m2->year);
        $payrolls->finalize($m2->month, $m2->year);

        // Bulan lalu: dibiarkan draft supaya alur finalisasi bisa dicoba
        $payrolls->generate($m1->month, $m1->year);
    }

    // ---------- Helper ----------

    private function windowStart(): Carbon
    {
        return now()->subMonthsNoOverflow(2)->startOfMonth();
    }

    private function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->isoWeekday(), config('hris.work_days'), true);
    }

    private function userOf(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
}