<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => [
                'month' => (int) $this->period_month,
                'year' => (int) $this->period_year,
            ],
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
                'department' => $this->employee->department?->name,
                'position' => $this->employee->position?->name,
            ]),
            'base_salary' => (float) $this->base_salary,
            'total_earnings' => (float) $this->total_earnings,
            'total_deductions' => (float) $this->total_deductions,
            'net_salary' => (float) $this->net_salary,
            'status' => $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items
                ->sortBy(fn ($item) => sprintf('%d-%08d', $item->type === 'earning' ? 0 : 1, $item->id))
                ->values()
                ->map(fn ($item) => [
                    'name' => $item->name,
                    'type' => $item->type,
                    'amount' => (float) $item->amount,
                ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}