<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Pengaman akun sendiri: tidak boleh menonaktifkan atau menurunkan role akun yang
     * sedang dipakai login. Aturan yang sama sudah ada di DELETE /employees/{id}.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employee = $this->route('employee');
            $me = $this->user();

            $isSelf = $employee->user_id !== null && (int) $employee->user_id === (int) $me->id;

            if (! $isSelf) {
                return;
            }

            if ($this->input('status') === 'inactive') {
                $validator->errors()->add('status', 'Kamu tidak bisa menonaktifkan akun sendiri.');
            }

            // Mengirim role yang sama dengan sekarang tetap boleh (form edit selalu mengirimnya)
            if ($this->has('role') && $this->input('role') !== $me->role) {
                $validator->errors()->add('role', 'Kamu tidak bisa mengubah role akunmu sendiri.');
            }
        });
    }
}