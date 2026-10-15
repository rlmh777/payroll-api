<?php

namespace App\Http\Controllers;

use App\Models\CitizenshipStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CitizenshipStatusController extends Controller
{
    /**
     * Display a listing of citizenship statuses.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CitizenshipStatus::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
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
        $perPage = $request->get('per_page', 20);
        $citizenshipStatuses = $query->paginate($perPage);

        return response()->json($citizenshipStatuses);
    }

    /**
     * Store a newly created citizenship status.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:citizenship_status,name',
            ]);

            $citizenshipStatus = CitizenshipStatus::create($validatedData);
            return response()->json([
                'message' => 'Citizenship status created successfully',
                'data' => $citizenshipStatus
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified citizenship status.
     */
    public function show(CitizenshipStatus $citizenshipStatus): JsonResponse
    {
        return response()->json($citizenshipStatus, 200);
    }

    /**
     * Update the specified citizenship status.
     */
    public function update(Request $request, CitizenshipStatus $citizenshipStatus): JsonResponse
    {
        try {
            // If request has no data, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $citizenshipStatus
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255|unique:citizenship_status,name,' . $citizenshipStatus->id,
            ]);

            $citizenshipStatus->update($validatedData);
            return response()->json([
                'message' => 'Citizenship status updated successfully',
                'data' => $citizenshipStatus
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified citizenship status.
     */
    public function destroy(CitizenshipStatus $citizenshipStatus): JsonResponse
    {
        // Check if citizenship status has associated employees
        if ($citizenshipStatus->employees()->exists()) {
            return response()->json([
                'error' => 'Cannot delete citizenship status with associated employees'
            ], 422);
        }

        $citizenshipStatus->delete();
        return response()->json(['message' => 'Citizenship status deleted successfully.']);
    }
}

