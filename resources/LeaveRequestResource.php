<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $cancellable = $viewer
            && $viewer->can('cancel', $this->resource)
            && ($this->status === 'pending'
                || ($this->status === 'approved' && $this->start_date->gt(now()->startOfDay())));

        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
                'department' => $this->employee->department?->name,
            ]),
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
            ]),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'total_days' => $this->total_days,
            'reason' => $this->reason,
            'status' => $this->status,
            'current_step' => $this->current_step,
            'waiting_for' => $this->status === 'pending'
                ? ((int) $this->current_step === 1 ? 'atasan' : 'hr')
                : null,
            'can_act' => $viewer ? $viewer->can('act', $this->resource) : false,
            'can_cancel' => (bool) $cancellable,
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval) => [
                'step' => $approval->step,
                'role' => (int) $approval->step === 1 ? 'atasan' : 'hr',
                'status' => $approval->status,
                'approver' => $approval->approver ? [
                    'id' => $approval->approver->id,
                    'name' => $approval->approver->name,
                ] : null,
                'notes' => $approval->notes,
                'acted_at' => $approval->acted_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}