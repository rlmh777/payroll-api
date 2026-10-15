<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\SsBenefitType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SsBenefitTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SsBenefitType::query()->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate((int) $request->get('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'unique:ss_benefit_type,name'],
        ]);

        $type = SsBenefitType::create($validated);

        return response()->json([
            'message' => 'SS benefit type created successfully',
            'data' => $type,
        ], 201);
    }

    public function show(SsBenefitType $ssBenefitType): JsonResponse
    {
        return response()->json($ssBenefitType);
    }

    public function update(Request $request, SsBenefitType $ssBenefitType): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128', Rule::unique('ss_benefit_type', 'name')->ignore($ssBenefitType->id)],
        ]);

        $ssBenefitType->update($validated);

        return response()->json([
            'message' => 'SS benefit type updated successfully',
            'data' => $ssBenefitType->fresh(),
        ]);
    }

    public function destroy(SsBenefitType $ssBenefitType): JsonResponse
    {
        if ($ssBenefitType->benefitStatuses()->exists()) {
            return response()->json([
                'message' => 'Cannot delete benefit type used on employee records',
            ], 422);
        }

        $ssBenefitType->delete();

        return response()->json(['message' => 'SS benefit type deleted successfully']);
    }
}
