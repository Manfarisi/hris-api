<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        // Gaji hanya boleh dilihat HR dan karyawan itu sendiri
        $canSeeSalary = $viewer->isHr() || $viewer->employee?->id === $this->id;

        return [
            'id' => $this->id,
            'nip' => $this->nip,
            'full_name' => $this->full_name,
            'join_date' => $this->join_date?->toDateString(),
            'phone' => $this->phone,
            'address' => $this->address,
            'status' => $this->status,
            'base_salary' => $this->when($canSeeSalary, fn () => (float) $this->base_salary),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'position' => new PositionResource($this->whenLoaded('position')),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'full_name' => $this->manager->full_name,
            ] : null),
            'account' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ] : null),
        ];
    }
}