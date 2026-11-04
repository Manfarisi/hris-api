<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    /** Saldo cuti milik sendiri untuk satu tahun (default: tahun berjalan) */
    public function me(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee || $employee->status !== 'active') {
            abort(403, 'Akun kamu belum terhubung ke data karyawan yang aktif.');
        }

        $params = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $year = (int) ($params['year'] ?? now()->year);

        // Saldo tahun berjalan/mendatang dibuat otomatis kalau belum ada (misal ganti tahun)
        if ($year >= now()->year) {
            foreach (LeaveType::all() as $type) {
                LeaveBalance::firstOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                    ['quota' => $type->default_quota, 'used' => 0]
                );
            }
        }

        $pending = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->whereYear('start_date', $year)
            ->selectRaw('leave_type_id, SUM(total_days) as days')
            ->groupBy('leave_type_id')
            ->pluck('days', 'leave_type_id');

        $balances = LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->orderBy('leave_type_id')
            ->get()
            ->map(function (LeaveBalance $balance) use ($pending) {
                $pendingDays = (int) ($pending[$balance->leave_type_id] ?? 0);

                return [
                    'leave_type' => [
                        'id' => $balance->leaveType->id,
                        'name' => $balance->leaveType->name,
                    ],
                    'year' => $balance->year,
                    'quota' => $balance->quota,
                    'used' => $balance->used,
                    'pending' => $pendingDays,
                    'remaining' => $balance->quota - $balance->used,
                    'available' => $balance->quota - $balance->used - $pendingDays,
                ];
            });

        return response()->json(['year' => $year, 'data' => $balances]);
    }
}