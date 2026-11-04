<?php

namespace App\Http\Resources;

use App\Support\AttendanceStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        // Koordinat hanya tampil untuk HR dan pemilik absensi itu sendiri
        $canSeeCoords = $viewer
            && ($viewer->isHr() || $viewer->employee?->id === $this->employee_id);

        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'clock_in' => $this->clock_in,
            'clock_out' => $this->clock_out,
            'status' => $this->status,
            'work_minutes' => AttendanceStats::workMinutes($this->resource),
            'in_location' => $this->when($canSeeCoords, fn () => [
                'latitude' => $this->in_lat,
                'longitude' => $this->in_lng,
            ]),
            'out_location' => $this->when($canSeeCoords, fn () => [
                'latitude' => $this->out_lat,
                'longitude' => $this->out_lng,
            ]),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
                'department' => $this->employee->department?->name,
            ]),
        ];
    }
}