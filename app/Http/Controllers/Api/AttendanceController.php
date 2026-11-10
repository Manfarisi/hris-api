<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClockRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Support\AttendanceStats;
use App\Support\Geo;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /** Status absensi hari ini (dipakai tombol clock in/out di frontend) */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $now = now();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $now->toDateString())
            ->first();

        return response()->json([
            'data' => $attendance ? new AttendanceResource($attendance) : null,
            'server_time' => $now->toIso8601String(),
            'is_working_day' => $this->isWorkingDay($now),
            'location_required' => $this->requiresLocation(),
            'work_hours' => [
                'start' => config('hris.work_start'),
                'end' => config('hris.work_end'),
            ],
        ]);
    }

    public function clockIn(ClockRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $now = now();

        if (! $this->isWorkingDay($now)) {
            $this->fail('date', 'Hari ini bukan hari kerja.');
        }

        [$lat, $lng, $location] = $this->resolveLocation($request);

        $lateAfter = $now->copy()
            ->setTimeFromTimeString(config('hris.work_start'))
            ->addMinutes(config('hris.late_tolerance_minutes'));

        $status = $now->greaterThan($lateAfter) ? 'late' : 'present';

        try {
            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'date' => $now->toDateString(),
                'clock_in' => $now->format('H:i:s'),
                'in_lat' => $lat,
                'in_lng' => $lng,
                'status' => $status,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Kena unique (employee_id, date): sudah ada catatan hari ini
            $this->fail('attendance', 'Kamu sudah punya catatan absensi hari ini.');
        }

        return response()->json([
            'message' => $status === 'late'
                ? 'Clock in berhasil, tapi kamu tercatat terlambat.'
                : 'Clock in berhasil.',
            'data' => new AttendanceResource($attendance),
            'location' => $location,
        ], 201);
    }

    public function clockOut(ClockRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $now = now();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $now->toDateString())
            ->first();

        if (! $attendance || ! $attendance->clock_in) {
            $this->fail('attendance', 'Kamu belum clock in hari ini.');
        }

        if ($attendance->clock_out) {
            $this->fail('attendance', 'Kamu sudah clock out hari ini.');
        }

        [$lat, $lng, $location] = $this->resolveLocation($request);

        // whereNull('clock_out') bikin update ini atomik: klik ganda tidak menimpa data
        $updated = Attendance::whereKey($attendance->id)
            ->whereNull('clock_out')
            ->update([
                'clock_out' => $now->format('H:i:s'),
                'out_lat' => $lat,
                'out_lng' => $lng,
            ]);

        if (! $updated) {
            $this->fail('attendance', 'Kamu sudah clock out hari ini.');
        }

        return response()->json([
            'message' => 'Clock out berhasil.',
            'data' => new AttendanceResource($attendance->fresh()),
            'location' => $location,
        ]);
    }

    /** Riwayat absensi milik sendiri per bulan + ringkasan */
    public function me(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $params = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $month = (int) ($params['month'] ?? now()->month);
        $year = (int) ($params['year'] ?? now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $records = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('date')
            ->get();

        return response()->json([
            'period' => ['month' => $month, 'year' => $year],
            'summary' => AttendanceStats::summarize($records),
            'data' => AttendanceResource::collection($records),
        ]);
    }

    private function currentEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;

        if (! $employee || $employee->status !== 'active') {
            abort(403, 'Akun kamu belum terhubung ke data karyawan yang aktif.');
        }

        return $employee;
    }

    private function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->isoWeekday(), config('hris.work_days'), true);
    }

    private function requiresLocation(): bool
    {
        return (bool) config('hris.attendance.require_location');
    }

    /**
     * Menentukan koordinat yang disimpan dan info kantor untuk balasan.
     * Lokasi tidak wajib: tidak ada yang divalidasi dan tidak ada koordinat yang disimpan,
     * walaupun klien tetap mengirimnya.
     *
     * @return array{0: float|null, 1: float|null, 2: array<string, mixed>|null}
     */
    private function resolveLocation(ClockRequest $request): array
    {
        if (! $this->requiresLocation()) {
            return [null, null, null];
        }

        $lat = $request->float('latitude');
        $lng = $request->float('longitude');

        return [$lat, $lng, $this->checkLocation($lat, $lng)];
    }

    /**
     * Cek apakah koordinat ada di dalam radius salah satu kantor.
     * Kalau lolos, balikin info kantor terdekat.
     */
    private function checkLocation(float $lat, float $lng): array
    {
        $offices = OfficeLocation::all();

        if ($offices->isEmpty()) {
            $this->fail('location', 'Lokasi kantor belum diatur. Hubungi HR.');
        }

        $nearest = null;
        $nearestDistance = INF;

        foreach ($offices as $office) {
            $distance = Geo::distanceMeters($lat, $lng, $office->latitude, $office->longitude);

            if ($distance < $nearestDistance) {
                $nearest = $office;
                $nearestDistance = $distance;
            }
        }

        if ($nearestDistance > $nearest->radius_meters) {
            $this->fail('location', sprintf(
                'Kamu berada %d m dari %s, di luar radius absensi (%d m).',
                (int) round($nearestDistance),
                $nearest->name,
                $nearest->radius_meters
            ));
        }

        return [
            'office' => $nearest->name,
            'distance_meters' => (int) round($nearestDistance),
            'radius_meters' => $nearest->radius_meters,
        ];
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}