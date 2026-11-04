<?php

namespace App\Http\Controllers\Api;

use App\Exports\PayrollExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollResource;
use App\Models\Payroll;
use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $payrolls)
    {
    }

    /** Hitung draft payroll satu periode (semua karyawan aktif, atau satu karyawan) */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $summary = $this->payrolls->generate(
            (int) $data['month'],
            (int) $data['year'],
            $data['employee_id'] ?? null
        );

        return response()->json([
            'message' => 'Perhitungan payroll selesai. Hasilnya masih draft sampai difinalisasi.',
            'period' => ['month' => (int) $data['month'], 'year' => (int) $data['year']],
            'summary' => $summary,
        ]);
    }

    /** Finalisasi draft: setelah final, payroll tidak bisa dihitung ulang atau dihapus */
    public function finalize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $count = $this->payrolls->finalize(
            (int) $data['month'],
            (int) $data['year'],
            $data['employee_id'] ?? null
        );

        if ($count === 0) {
            throw ValidationException::withMessages([
                'month' => ['Tidak ada draft payroll di periode itu.'],
            ]);
        }

        return response()->json([
            'message' => "{$count} payroll difinalisasi.",
            'finalized' => $count,
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $params = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'status' => ['nullable', Rule::in(['draft', 'final'])],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = function ($query) use ($params) {
            $query
                ->when($params['month'] ?? null, fn ($q, $v) => $q->where('period_month', $v))
                ->when($params['year'] ?? null, fn ($q, $v) => $q->where('period_year', $v))
                ->when($params['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($params['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
                ->when($params['department_id'] ?? null, fn ($q, $v) => $q->whereHas(
                    'employee',
                    fn ($e) => $e->where('department_id', $v)
                ));
        };

        $list = Payroll::with(['employee.department', 'employee.position']);
        $filters($list);

        $items = $list
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('employee_id')
            ->paginate($params['per_page'] ?? 15);

        // Total dihitung dari seluruh hasil filter, bukan cuma halaman ini
        $sumQuery = Payroll::query();
        $filters($sumQuery);

        $sum = $sumQuery->toBase()->selectRaw(
            'COALESCE(SUM(base_salary), 0) as base_salary,
             COALESCE(SUM(total_earnings), 0) as total_earnings,
             COALESCE(SUM(total_deductions), 0) as total_deductions,
             COALESCE(SUM(net_salary), 0) as net_salary'
        )->first();

        return PayrollResource::collection($items)->additional([
            'totals' => [
                'base_salary' => (float) $sum->base_salary,
                'total_earnings' => (float) $sum->total_earnings,
                'total_deductions' => (float) $sum->total_deductions,
                'net_salary' => (float) $sum->net_salary,
            ],
        ]);
    }

    /** Slip gaji milik sendiri (hanya yang sudah final) */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            abort(403, 'Akun kamu belum terhubung ke data karyawan.');
        }

        $items = Payroll::with(['employee.department', 'employee.position', 'items'])
            ->where('employee_id', $employee->id)
            ->where('status', 'final')
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();

        return PayrollResource::collection($items);
    }

    public function show(Payroll $payroll): PayrollResource
    {
        Gate::authorize('view', $payroll);

        return new PayrollResource($payroll->load(['employee.department', 'employee.position', 'items']));
    }

    public function destroy(Payroll $payroll): JsonResponse
    {
        if ($payroll->status === 'final') {
            throw ValidationException::withMessages([
                'status' => ['Payroll yang sudah final tidak bisa dihapus.'],
            ]);
        }

        $payroll->items()->delete();
        $payroll->delete();

        return response()->json(['message' => 'Draft payroll dihapus.']);
    }

    public function pdf(Payroll $payroll)
    {
        Gate::authorize('view', $payroll);

        $payroll->load(['employee.department', 'employee.position', 'items']);
        $items = $payroll->items->sortBy('id');

        $pdf = Pdf::loadView('payroll.payslip', [
            'payroll' => $payroll,
            'employee' => $payroll->employee,
            'period' => $this->periodLabel($payroll->period_month, $payroll->period_year),
            'company' => config('hris.company_name'),
            'earnings' => $items->where('type', 'earning')->values(),
            'deductions' => $items->where('type', 'deduction')->values(),
        ])->setPaper('a4');

        return $pdf->download(sprintf(
            'slip-gaji-%s-%d-%02d.pdf',
            $payroll->employee->nip,
            $payroll->period_year,
            $payroll->period_month
        ));
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $payrolls = Payroll::with(['employee.department', 'employee.position'])
            ->where('period_month', $data['month'])
            ->where('period_year', $data['year'])
            ->get()
            ->sortBy(fn ($p) => $p->employee->full_name)
            ->values();

        if ($payrolls->isEmpty()) {
            return response()->json([
                'message' => 'Belum ada data payroll untuk periode ini.',
            ], 422);
        }

        $suffix = sprintf('%d-%02d', $data['year'], $data['month']);

        return Excel::download(new PayrollExport($payrolls, "Payroll {$suffix}"), "payroll-{$suffix}.xlsx");
    }

    private function periodLabel(int $month, int $year): string
    {
        return Carbon::create($year, $month, 1)->locale('id')->translatedFormat('F Y');
    }
}