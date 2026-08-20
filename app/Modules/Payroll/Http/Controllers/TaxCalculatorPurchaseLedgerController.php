<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\TaxCalculatorPurchaseLedgerExcludedName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaxCalculatorPurchaseLedgerController extends Controller
{
    public function exclusions(): JsonResponse
    {
        $items = TaxCalculatorPurchaseLedgerExcludedName::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TaxCalculatorPurchaseLedgerExcludedName $item) => [
                'id' => $item->id,
                'name' => $item->name,
            ])
            ->values();

        return response()->json($items);
    }

    public function storeExclusion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($validated['name']);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Company name is required.',
            ]);
        }

        $existing = TaxCalculatorPurchaseLedgerExcludedName::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) {
            return response()->json([
                'id' => $existing->id,
                'name' => $existing->name,
            ]);
        }

        $item = TaxCalculatorPurchaseLedgerExcludedName::query()->create([
            'name' => $name,
        ]);

        return response()->json([
            'id' => $item->id,
            'name' => $item->name,
        ], 201);
    }

    public function destroyExclusion(TaxCalculatorPurchaseLedgerExcludedName $exclusion): JsonResponse
    {
        $exclusion->delete();

        return response()->json(['message' => 'Excluded company removed']);
    }
}
