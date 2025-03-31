<?php

namespace App\Http\Controllers;

use App\Models\CalculationMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CalculationModeController extends Controller
{
    /**
     * Display a listing of calculation modes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CalculationMode::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['name'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $calculationModes = $query->paginate($perPage);

        return response()->json($calculationModes);
    }

    /**
     * Store a newly created calculation mode.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:100|unique:calculation_mode,name',
            ]);

            $calculationMode = CalculationMode::create($validatedData);
            return response()->json([
                'message' => 'Calculation Mode created successfully',
                'data' => $calculationMode
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified calculation mode.
     */
    public function show(CalculationMode $calculationMode): JsonResponse
    {
        return response()->json($calculationMode, 200);
    }

    /**
     * Update the specified calculation mode.
     */
    public function update(Request $request, CalculationMode $calculationMode): JsonResponse
    {
        try {
            // If request has no data, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $calculationMode
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:100|unique:calculation_mode,name,' . $calculationMode->id,
            ]);

            $calculationMode->update($validatedData);
            return response()->json([
                'message' => 'Calculation Mode updated successfully',
                'data' => $calculationMode
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified calculation mode.
     */
    public function destroy(CalculationMode $calculationMode): JsonResponse
    {
        // Check if calculation mode has associated payrolls
        if ($calculationMode->payrolls()->exists()) {
            return response()->json([
                'error' => 'Cannot delete calculation mode with associated payrolls'
            ], 422);
        }

        $calculationMode->delete();
        return response()->json(['message' => 'Calculation Mode deleted successfully.']);
    }
} 