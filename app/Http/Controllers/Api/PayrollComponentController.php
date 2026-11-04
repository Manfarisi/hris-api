<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollComponentController extends Controller
{
    public function index(): JsonResponse
    {
        $components = PayrollComponent::orderBy('type', 'desc')->orderBy('id')->get();

        return response()->json([
            'data' => $components->map(fn ($c) => $this->format($c))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        $component = PayrollComponent::create($data);

        return response()->json(['data' => $this->format($component)], 201);
    }

    public function update(Request $request, PayrollComponent $payrollComponent): JsonResponse
    {
        $data = $request->validate($this->rules($request, $payrollComponent));

        $payrollComponent->update($data);

        return response()->json(['data' => $this->format($payrollComponent->fresh())]);
    }

    /** Aman dihapus kapan saja: slip gaji lama menyimpan snapshot, tidak terpengaruh */
    public function destroy(PayrollComponent $payrollComponent): JsonResponse
    {
        $payrollComponent->delete();

        return response()->json(['message' => 'Komponen gaji dihapus.']);
    }

    private function rules(Request $request, ?PayrollComponent $component = null): array
    {
        $sometimes = $component ? ['sometimes'] : [];

        // Untuk update sebagian, cek batas persen berdasarkan calc_type yang berlaku
        $calcType = $request->input('calc_type', $component?->calc_type);

        return [
            'name' => [
                ...$sometimes, 'required', 'string', 'max:100',
                Rule::unique('payroll_components', 'name')->ignore($component?->id),
            ],
            'type' => [...$sometimes, 'required', Rule::in(['earning', 'deduction'])],
            'calc_type' => [...$sometimes, 'required', Rule::in(['fixed', 'percent'])],
            'value' => [
                ...$sometimes, 'required', 'numeric', 'min:0',
                Rule::when($calcType === 'percent', ['max:100']),
            ],
        ];
    }

    private function format(PayrollComponent $component): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'type' => $component->type,
            'calc_type' => $component->calc_type,
            'value' => (float) $component->value,
        ];
    }
}