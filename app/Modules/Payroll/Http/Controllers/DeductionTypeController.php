<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\DeductionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeductionTypeController extends Controller
{
    /**
     * Display a listing of deduction types.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DeductionType::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Filter by defaultAmount range
        if ($request->has('min_amount')) {
            $query->where('defaultAmount', '>=', $request->min_amount);
        }
        if ($request->has('max_amount')) {
            $query->where('defaultAmount', '<=', $request->max_amount);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['name', 'defaultAmount'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $deductionTypes = $query->paginate($perPage);

        return response()->json($deductionTypes);
    }

    /**
     * Store a newly created deduction type.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'note' => 'nullable|string|max:1024',
                'defaultAmount' => 'required|numeric|min:0|max:9999999999.99',
            ]);

            $deductionType = DeductionType::create($validatedData);
            return response()->json([
                'message' => 'Deduction Type created successfully',
                'data' => $deductionType
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified deduction type.
     */
    public function show(DeductionType $deductionType): JsonResponse
    {
        return response()->json($deductionType, 200);
    }

    /**
     * Update the specified deduction type.
     */
    public function update(Request $request, DeductionType $deductionType): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $deductionType
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'note' => 'sometimes|nullable|string|max:1024',
                'defaultAmount' => 'sometimes|numeric|min:0|max:9999999999.99',
            ]);

            $deductionType->update($validatedData);
            return response()->json([
                'message' => 'Deduction Type updated successfully',
                'data' => $deductionType
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified deduction type.
     */
    public function destroy(DeductionType $deductionType): JsonResponse
    {
        $deductionType->delete();
        return response()->json(['message' => 'Deduction Type deleted successfully.']);
    }
} 