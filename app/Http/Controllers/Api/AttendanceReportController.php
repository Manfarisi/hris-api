<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Support\AttendanceStats;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AttendanceReportController extends Controller
{
    /**
     * Daftar absensi. Default: hari ini.
     * HR melihat semua, manager hanya bawahan langsungnya.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $this->ensureCanView($user);

        $params = $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', Rule::in(['present', 'late', 'absent', 'leave'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // ?date= untuk satu hari, ?from=&to= untuk rentang
        $from = $params['from'] ?? $params['date'] ?? now()->toDateString();
        $to = $params['to'] ?? $params['date'] ?? $from;

        $attendances = Attendance::query()
            ->with('employee.department')
            ->whereBetween('date', [$from, $to])
            ->whereHas('employee', function ($q) use ($user, $params) {
                if (! $user->isHr()) {
                    $q->where('manager_id', $user->employee->id);
                }

                if (! empty($params['department_id'])) {
                    $q->where('department_id', $params['department_id']);
                }
            })
            ->when($params['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($params['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('date')
            ->orderBy('clock_in')
            ->paginate($params['per_page'] ?? 15);

        return AttendanceResource::collection($attendances);
    }

    /** Rekap bulanan per karyawan */
    public function recap(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureCanView($user);

        $params = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $month = (int) ($params['month'] ?? now()->month);
        $year = (int) ($params['year'] ?? now()->year);

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        // Hari kerja dihitung sampai hari ini (kalau bulan masih berjalan)
        $upTo = now()->lt($end) ? now()->startOfDay() : $end->copy()->startOfDay();

        $employees = Employee::with(['department', 'position'])
            ->where('status', 'active')
            ->when(! $user->isHr(), fn ($q) => $q->where('manager_id', $user->employee->id))
            ->when($params['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->orderBy('full_name')
            ->get();

        $records = Attendance::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($records, $start, $upTo) {
            $stats = AttendanceStats::summarize($records->get($employee->id, collect()));

            // Karyawan yang baru join di tengah bulan dihitung mulai tanggal join
            $from = $employee->join_date && $employee->join_date->gt($start)
                ? $employee->join_date->copy()->startOfDay()
                : $start;

            $workingDays = AttendanceStats::workingDays($from, $upTo);

            return [
                'employee' => [
                    'id' => $employee->id,
                    'nip' => $employee->nip,
                    'full_name' => $employee->full_name,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->name,
                ],
                ...$stats,
                'working_days' => $workingDays,
                'absent_days_estimate' => max(0, $workingDays - $stats['attended_days'] - $stats['leave_days']),
            ];
        });

        return response()->json([
            'period' => ['month' => $month, 'year' => $year],
            'data' => $rows->values(),
        ]);
    }

    /** Manager harus punya data karyawan supaya bawahannya bisa dicari */
    private function ensureCanView(User $user): void
    {
        if (! $user->isHr() && ! $user->employee) {
            abort(403, 'Akun kamu belum terhubung ke data karyawan.');
        }
    }
}