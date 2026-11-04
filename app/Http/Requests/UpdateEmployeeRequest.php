<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:150'],
            'nip' => [
                'sometimes', 'required', 'string', 'max:30',
                Rule::unique('employees', 'nip')->ignore($employee->id),
            ],
            'department_id' => ['sometimes', 'required', 'exists:departments,id'],
            'position_id' => ['sometimes', 'required', 'exists:positions,id'],
            'manager_id' => ['nullable', 'exists:employees,id', Rule::notIn([$employee->id])],
            'join_date' => ['sometimes', 'required', 'date'],
            'base_salary' => ['sometimes', 'required', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'role' => [
                'sometimes',
                Rule::in([User::ROLE_HR, User::ROLE_MANAGER, User::ROLE_EMPLOYEE]),
            ],
        ];
    }
}