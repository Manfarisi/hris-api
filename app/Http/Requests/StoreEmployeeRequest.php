<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Employee::class);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'nip' => ['required', 'string', 'max:30', 'unique:employees,nip'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'manager_id' => ['nullable', 'exists:employees,id'],
            'join_date' => ['required', 'date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],

            // Akun login (opsional). Kalau email diisi, password dan role wajib
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required_with:email', 'nullable', 'string', 'min:8'],
            'role' => [
                'required_with:email',
                'nullable',
                Rule::in([User::ROLE_HR, User::ROLE_MANAGER, User::ROLE_EMPLOYEE]),
            ],
        ];
    }
}