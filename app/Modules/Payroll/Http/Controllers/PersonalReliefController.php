<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\PersonalRelief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PersonalReliefController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PersonalRelief::query();

        // Search by range
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereRaw('CAST("startRange" AS TEXT) ILIKE ?', ["%{$search}%"])
                  ->orWhereRaw('CAST("endRange" AS TEXT) ILIKE ?', ["%{$search}%"]);
            });
        }

        // Sorting
        $sortField = $request->get('sort_by', 'startRange');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        $allowedSortFields = [
            'startRange',
            'endRange',
            'personalRelief',
            'created_at',
            'updated_at'
        ];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('startRange', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $personalReliefs = $query->paginate($perPage);

        return response()->json($personalReliefs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'startRange' => 'required|numeric|min:0',
                'endRange' => 'required|numeric|min:0|gte:startRange',
                'personalRelief' => 'required|numeric|min:0',
            ]);

            $personalRelief = PersonalRelief::create($validatedData);
            return response()->json([
                'message' => 'Personal relief record created successfully',
                'data' => $personalRelief
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $personalRelief = PersonalRelief::findOrFail($id);
        return response()->json($personalRelief, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $personalRelief = PersonalRelief::findOrFail($id);

            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $personalRelief
                ], 200);
            }

            $validatedData = $request->validate([
                'startRange' => 'sometimes|numeric|min:0',
                'endRange' => 'sometimes|numeric|min:0',
                'personalRelief' => 'sometimes|numeric|min:0',
            ]);

            // Validate that end range is greater than or equal to start range if both are provided
            if ($request->has('startRange') || $request->has('endRange')) {
                $startRange = $validatedData['startRange'] ?? $personalRelief->startRange;
                $endRange = $validatedData['endRange'] ?? $personalRelief->endRange;
                
                if ($endRange < $startRange) {
                    return response()->json([
                        'errors' => ['endRange' => ['End range must be greater than or equal to start range']]
                    ], 422);
                }
            }

            $personalRelief->update($validatedData);
            return response()->json([
                'message' => 'Personal relief record updated successfully',
                'data' => $personalRelief
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $personalRelief = PersonalRelief::findOrFail($id);
        $personalRelief->delete();
        return response()->json(['message' => 'Personal relief record deleted successfully.'], 200);
    }
}

