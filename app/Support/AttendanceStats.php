<?php

namespace App\Support;

use App\Models\Attendance;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class AttendanceStats
{
    /** Lama kerja dalam menit, null kalau belum clock out */
    public static function workMinutes(Attendance $attendance): ?int
    {
        if (! $attendance->clock_in || ! $attendance->clock_out) {
            return null;
        }

        $in = Carbon::createFromTimeString($attendance->clock_in);
        $out = Carbon::createFromTimeString($attendance->clock_out);

        return max(0, (int) $in->diffInMinutes($out));
    }

    /** Ringkasan dari kumpulan data absensi */
    public static function summarize(Collection $records): array
    {
        return [
            'attended_days' => $records->whereIn('status', ['present', 'late'])->count(),
            'on_time_days' => $records->where('status', 'present')->count(),
            'late_days' => $records->where('status', 'late')->count(),
            'leave_days' => $records->where('status', 'leave')->count(),
            'total_work_minutes' => (int) $records->sum(fn ($r) => self::workMinutes($r) ?? 0),
        ];
    }

    /** Jumlah hari kerja (sesuai config) dari tanggal $from sampai $to, inklusif */
    public static function workingDays(Carbon $from, Carbon $to): int
    {
        if ($from->gt($to)) {
            return 0;
        }

        $days = 0;

        foreach (CarbonPeriod::create($from->toDateString(), $to->toDateString()) as $day) {
            if (in_array($day->isoWeekday(), config('hris.work_days'), true)) {
                $days++;
            }
        }

        return $days;
    }
}