<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    /**
     * Ajukan cuti. Hari yang dihitung hanya hari kerja (sesuai config hris.work_days).
     */
    public function submit(Employee $employee, array $data): LeaveRequest
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if ($start->lt(now()->startOfDay())) {
            $this->fail('start_date', 'Tanggal mulai cuti tidak boleh di masa lalu.');
        }

        if ($start->year !== $end->year) {
            $this->fail('end_date', 'Cuti lintas tahun harus diajukan terpisah per tahun.');
        }

        $totalDays = count($this->workingDates($start, $end));

        if ($totalDays === 0) {
            $this->fail('start_date', 'Rentang tanggal itu tidak mengandung hari kerja.');
        }

        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        $employee->loadMissing('manager');

        // Kalau atasan tidak punya akun login, tahap atasan dilewati
        $steps = $employee->manager?->user_id ? [1, 2] : [2];

        return DB::transaction(function () use ($employee, $leaveType, $data, $start, $end, $totalDays, $steps) {
            $overlap = LeaveRequest::where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->exists();

            if ($overlap) {
                $this->fail('start_date', 'Kamu sudah punya pengajuan cuti lain di rentang tanggal itu.');
            }

            // Baris saldo dikunci supaya dua pengajuan bersamaan tidak lolos barengan
            $balance = $this->lockedBalance($employee->id, $leaveType, $start->year);

            $pendingDays = (int) LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('status', 'pending')
                ->whereYear('start_date', $start->year)
                ->sum('total_days');

            $available = $balance->quota - $balance->used - $pendingDays;

            if ($available < $totalDays) {
                $this->fail('leave_type_id', sprintf(
                    'Saldo %s tidak cukup. Tersedia %d hari, kamu mengajukan %d hari.',
                    $leaveType->name,
                    max(0, $available),
                    $totalDays
                ));
            }

            $leave = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_days' => $totalDays,
                'reason' => $data['reason'] ?? null,
                'status' => 'pending',
                'current_step' => $steps[0],
            ]);

            foreach ($steps as $step) {
                LeaveApproval::create([
                    'leave_request_id' => $leave->id,
                    'step' => $step,
                    'status' => 'pending',
                ]);
            }

            return $leave;
        });
    }

    /**
     * Setujui tahap yang sedang berjalan. Kalau itu tahap terakhir,
     * saldo dipotong dan absensi 'leave' dibuat.
     */
    public function approve(int $id, User $approver, ?string $notes = null): LeaveRequest
    {
        return DB::transaction(function () use ($id, $approver, $notes) {
            $leave = $this->lockForAction($id, $approver);

            $this->currentApproval($leave)->update([
                'approver_id' => $approver->id,
                'status' => 'approved',
                'notes' => $notes,
                'acted_at' => now(),
            ]);

            $next = $leave->approvals()->where('status', 'pending')->orderBy('step')->first();

            if ($next) {
                $leave->update(['current_step' => $next->step]);
            } else {
                $this->finalize($leave);
            }

            return $leave->fresh();
        });
    }

    public function reject(int $id, User $approver, string $notes): LeaveRequest
    {
        return DB::transaction(function () use ($id, $approver, $notes) {
            $leave = $this->lockForAction($id, $approver);

            $this->currentApproval($leave)->update([
                'approver_id' => $approver->id,
                'status' => 'rejected',
                'notes' => $notes,
                'acted_at' => now(),
            ]);

            // Tahap sesudahnya tidak akan pernah diproses
            $leave->approvals()->where('status', 'pending')->delete();
            $leave->update(['status' => 'rejected']);

            return $leave->fresh();
        });
    }

    /**
     * Batalkan pengajuan. Yang sudah disetujui hanya bisa dibatalkan
     * kalau tanggal mulainya belum tiba.
     */
    public function cancel(int $id): LeaveRequest
    {
        return DB::transaction(function () use ($id) {
            $leave = LeaveRequest::lockForUpdate()->findOrFail($id);

            if ($leave->status === 'pending') {
                $leave->approvals()->where('status', 'pending')->delete();
            } elseif ($leave->status === 'approved') {
                if (! $leave->start_date->gt(now()->startOfDay())) {
                    $this->fail('status', 'Cuti yang sudah berjalan tidak bisa dibatalkan. Hubungi HR.');
                }

                $balance = LeaveBalance::where('employee_id', $leave->employee_id)
                    ->where('leave_type_id', $leave->leave_type_id)
                    ->where('year', $leave->start_date->year)
                    ->lockForUpdate()
                    ->first();

                $balance?->update(['used' => max(0, $balance->used - $leave->total_days)]);

                Attendance::where('employee_id', $leave->employee_id)
                    ->where('status', 'leave')
                    ->whereDate('date', '>=', $leave->start_date->toDateString())
                    ->whereDate('date', '<=', $leave->end_date->toDateString())
                    ->delete();
            } else {
                $this->fail('status', "Pengajuan berstatus {$leave->status} tidak bisa dibatalkan.");
            }

            $leave->update(['status' => 'cancelled']);

            return $leave->fresh();
        });
    }

    // ---------- Internal ----------

    /** Kunci pengajuan lalu pastikan approver memang berhak memprosesnya saat ini */
    private function lockForAction(int $id, User $user): LeaveRequest
    {
        $leave = LeaveRequest::with('employee.manager')->lockForUpdate()->findOrFail($id);

        if (! Gate::forUser($user)->allows('act', $leave)) {
            abort(409, 'Pengajuan ini sudah diproses atau kamu tidak berhak memprosesnya.');
        }

        return $leave;
    }

    private function currentApproval(LeaveRequest $leave): LeaveApproval
    {
        return $leave->approvals()
            ->where('step', $leave->current_step)
            ->where('status', 'pending')
            ->firstOrFail();
    }

    /** Persetujuan terakhir: potong saldo, ubah status, tandai absensi */
    private function finalize(LeaveRequest $leave): void
    {
        $balance = LeaveBalance::where('employee_id', $leave->employee_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('year', $leave->start_date->year)
            ->lockForUpdate()
            ->first();

        // Dicek ulang: saldo bisa saja sudah terpakai pengajuan lain sejak diajukan
        if (! $balance || ($balance->quota - $balance->used) < $leave->total_days) {
            $this->fail('balance', 'Saldo cuti karyawan tidak cukup lagi untuk menyetujui pengajuan ini.');
        }

        $balance->increment('used', $leave->total_days);
        $leave->update(['status' => 'approved']);

        foreach ($this->workingDates($leave->start_date, $leave->end_date) as $date) {
            $alreadyHasRecord = Attendance::where('employee_id', $leave->employee_id)
                ->whereDate('date', $date->toDateString())
                ->exists();

            // Catatan yang sudah ada (misal sudah clock in hari ini) tidak ditimpa
            if (! $alreadyHasRecord) {
                Attendance::create([
                    'employee_id' => $leave->employee_id,
                    'date' => $date->toDateString(),
                    'status' => 'leave',
                ]);
            }
        }
    }

    private function lockedBalance(int $employeeId, LeaveType $type, int $year): LeaveBalance
    {
        $keys = [
            'employee_id' => $employeeId,
            'leave_type_id' => $type->id,
            'year' => $year,
        ];

        // Saldo tahun baru dibuat otomatis dari kuota default jenis cutinya
        LeaveBalance::firstOrCreate($keys, ['quota' => $type->default_quota, 'used' => 0]);

        return LeaveBalance::where($keys)->lockForUpdate()->firstOrFail();
    }

    /** @return Carbon[] */
    private function workingDates(Carbon $from, Carbon $to): array
    {
        $dates = [];

        foreach (CarbonPeriod::create($from->toDateString(), $to->toDateString()) as $day) {
            if (in_array($day->isoWeekday(), config('hris.work_days'), true)) {
                $dates[] = Carbon::parse($day->toDateString());
            }
        }

        return $dates;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}