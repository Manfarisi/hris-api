<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    private const RELATIONS = ['employee.department', 'employee.manager', 'leaveType', 'approvals.approver'];

    private const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function __construct(private LeaveService $leaves)
    {
    }

    /** Pengajuan milik sendiri */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $this->currentEmployee($request);

        $params = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = LeaveRequest::with(self::RELATIONS)
            ->where('employee_id', $employee->id)
            ->when($params['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($params['per_page'] ?? 15);

        return LeaveRequestResource::collection($items);
    }

    /**
     * Daftar pengajuan untuk approver: HR melihat semua, atasan melihat bawahan langsungnya.
     * ?awaiting_me=1 hanya menampilkan yang menunggu keputusanku sekarang.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user->isHr() && ! $user->employee) {
            abort(403, 'Akun kamu belum terhubung ke data karyawan.');
        }

        $params = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'awaiting_me' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = LeaveRequest::with(self::RELATIONS)
            ->when(! $user->isHr(), fn ($q) => $q->whereHas(
                'employee',
                fn ($e) => $e->where('manager_id', $user->employee->id)
            ))
            ->when($params['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($params['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            // from/to: pengajuan yang beririsan dengan rentang tanggal itu
            ->when($params['from'] ?? null, fn ($q, $from) => $q->whereDate('end_date', '>=', $from))
            ->when($params['to'] ?? null, fn ($q, $to) => $q->whereDate('start_date', '<=', $to))
            ->when($request->boolean('awaiting_me'), fn ($q) => $this->awaitingMe($q, $user))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($params['per_page'] ?? 15);

        return LeaveRequestResource::collection($items);
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('view', $leaveRequest);

        return new LeaveRequestResource($leaveRequest->load(self::RELATIONS));
    }

    public function store(SubmitLeaveRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $leave = $this->leaves->submit($employee, $request->validated());

        return (new LeaveRequestResource($leave->load(self::RELATIONS)))
            ->additional(['message' => 'Pengajuan cuti terkirim.'])
            ->response()
            ->setStatusCode(201);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('act', $leaveRequest);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $leave = $this->leaves->approve($leaveRequest->id, $request->user(), $data['notes'] ?? null);

        return (new LeaveRequestResource($leave->load(self::RELATIONS)))
            ->additional(['message' => $leave->status === 'approved'
                ? 'Pengajuan cuti disetujui sepenuhnya.'
                : 'Tahap persetujuan kamu tercatat, menunggu tahap berikutnya.']);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('act', $leaveRequest);

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $leave = $this->leaves->reject($leaveRequest->id, $request->user(), $data['notes']);

        return (new LeaveRequestResource($leave->load(self::RELATIONS)))
            ->additional(['message' => 'Pengajuan cuti ditolak.']);
    }

    public function cancel(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('cancel', $leaveRequest);

        $leave = $this->leaves->cancel($leaveRequest->id);

        return (new LeaveRequestResource($leave->load(self::RELATIONS)))
            ->additional(['message' => 'Pengajuan cuti dibatalkan.']);
    }

    private function currentEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;

        if (! $employee || $employee->status !== 'active') {
            abort(403, 'Akun kamu belum terhubung ke data karyawan yang aktif.');
        }

        return $employee;
    }

    /** Pengajuan yang bisa aku putuskan sekarang (bukan punyaku sendiri) */
    private function awaitingMe(BuilderContract $query, $user): void
    {
        $query->where('status', 'pending')
            ->where('employee_id', '!=', $user->employee?->id ?? 0)
            ->where(function ($w) use ($user) {
                $w->where(function ($s) use ($user) {
                    $s->where('current_step', 1)
                        ->whereHas('employee.manager', fn ($m) => $m->where('user_id', $user->id));
                });

                if ($user->isHr()) {
                    $w->orWhere('current_step', 2);
                }
            });
    }
}