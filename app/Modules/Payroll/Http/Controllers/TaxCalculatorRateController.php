<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\TaxCalculatorRate;
use App\Modules\Payroll\Services\TaxCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxCalculatorRateController extends Controller
{
    public function index(): JsonResponse
    {
        $rates = TaxCalculatorRate::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($rates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['applies_to_accounts'] = $validated['applies_to_accounts'] ?? false;

        $rate = TaxCalculatorRate::create($validated);

        return response()->json([
            'message' => 'Tax calculator rate created successfully',
            'data' => $rate,
        ], 201);
    }

    public function show(TaxCalculatorRate $taxCalculatorRate): JsonResponse
    {
        return response()->json($taxCalculatorRate);
    }

    public function update(Request $request, TaxCalculatorRate $taxCalculatorRate): JsonResponse
    {
        $validated = $request->validate($this->rules($taxCalculatorRate->id));

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper(trim($validated['code']));
        }

        $taxCalculatorRate->update($validated);

        return response()->json([
            'message' => 'Tax calculator rate updated successfully',
            'data' => $taxCalculatorRate->fresh(),
        ]);
    }

    public function destroy(TaxCalculatorRate $taxCalculatorRate): JsonResponse
    {
        $taxCalculatorRate->delete();

        return response()->json(['message' => 'Tax calculator rate deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?string $ignoreId = null): array
    {
        return [
            'category' => ['required', 'string', Rule::in([
                TaxCalculatorService::CATEGORY_BUSINESS_TAX,
                TaxCalculatorService::CATEGORY_GST,
                TaxCalculatorService::CATEGORY_BTB,
                TaxCalculatorService::CATEGORY_ADJUSTMENT,
            ])],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('tax_calculator_rates', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:128'],
            'rate' => ['required', 'numeric'],
            'iris_line' => ['nullable', 'string', 'max:32'],
            'applies_to_accounts' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
