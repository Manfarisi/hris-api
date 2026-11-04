<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    private const RELATIONS = ['department', 'position', 'manager', 'user'];

    private const EMPLOYEE_FIELDS = [
        'full_name', 'nip', 'department_id', 'position_id', 'manager_id',
        'join_date', 'base_salary', 'phone', 'address', 'status',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $user = $request->user();

        $employees = Employee::query()
            ->with(self::RELATIONS)
            // Non-HR (manager) hanya melihat bawahan langsungnya
            ->when(! $user->isHr(), fn ($q) => $q->where('manager_id', $user->employee->id))
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($w) use ($search) {
                    $w->where('full_name', 'like', "%{$search}%")
                      ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->when($request->query('department_id'), fn ($q, $id) => $q->where('department_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('full_name')
            ->paginate(max(1, min($request->integer('per_page', 15), 100)));

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $employee = DB::transaction(function () use ($data) {
            $user = null;

            if (! empty($data['email'])) {
                $user = User::create([
                    'name' => $data['full_name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => $data['role'],
                ]);
            }

            $employee = Employee::create(
                ['user_id' => $user?->id] + Arr::only($data, self::EMPLOYEE_FIELDS)
            );

            // Saldo cuti tahun berjalan langsung dibuat untuk karyawan baru
            foreach (LeaveType::all() as $leaveType) {
                LeaveBalance::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveType->id,
                    'year' => now()->year,
                    'quota' => $leaveType->default_quota,
                    'used' => 0,
                ]);
            }

            return $employee;
        });

        return (new EmployeeResource($employee->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $employee) {
            $employee->update(Arr::only($data, self::EMPLOYEE_FIELDS));

            // Sinkronkan nama & role ke akun login (kalau karyawan punya akun)
            if ($employee->user) {
                $employee->user->update(array_filter([
                    'name' => $data['full_name'] ?? null,
                    'role' => $data['role'] ?? null,
                ]));
            }
        });

        // Karyawan yang dinonaktifkan langsung kehilangan semua sesi login
        if (($data['status'] ?? null) === 'inactive') {
            $employee->user?->tokens()->delete();
        }

        return new EmployeeResource($employee->fresh()->load(self::RELATIONS));
    }

    /** DELETE = nonaktifkan karyawan, bukan hapus permanen */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('delete', $employee);

        if ($employee->user_id !== null && $employee->user_id === $request->user()->id) {
            return response()->json([
                'message' => 'Kamu tidak bisa menonaktifkan akun sendiri.',
            ], 422);
        }

        $employee->update(['status' => 'inactive']);
        $employee->user?->tokens()->delete();

        return response()->json(['message' => 'Karyawan dinonaktifkan.']);
    }
}