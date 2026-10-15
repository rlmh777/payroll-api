<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\ContractType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ContractType::query()->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->input('search') . '%');
        }

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate((int) $request->get('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'unique:contract_type,name'],
        ]);

        $type = ContractType::create($validated);

        return response()->json(['message' => 'Contract type created', 'data' => $type], 201);
    }

    public function show(ContractType $contractType): JsonResponse
    {
        return response()->json($contractType);
    }

    public function update(Request $request, ContractType $contractType): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128', Rule::unique('contract_type', 'name')->ignore($contractType->id)],
        ]);

        $contractType->update($validated);

        return response()->json(['message' => 'Contract type updated', 'data' => $contractType->fresh()]);
    }

    public function destroy(ContractType $contractType): JsonResponse
    {
        if ($contractType->employmentDetails()->exists()) {
            return response()->json(['message' => 'Cannot delete contract type in use'], 422);
        }

        $contractType->delete();

        return response()->json(['message' => 'Contract type deleted']);
    }
}
