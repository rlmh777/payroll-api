<?php

namespace App\Http\Controllers;

use App\Models\Gender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GenderController extends Controller
{
    /**
     * Display a listing of genders.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Gender::query();

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
        $genders = $query->paginate($perPage);

        return response()->json($genders);
    }

    /**
     * Store a newly created gender.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:64|unique:gender,name',
            ]);

            $gender = Gender::create($validatedData);
            return response()->json([
                'message' => 'Gender created successfully',
                'data' => $gender
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified gender.
     */
    public function show(Gender $gender): JsonResponse
    {
        return response()->json($gender, 200);
    }

    /**
     * Update the specified gender.
     */
    public function update(Request $request, Gender $gender): JsonResponse
    {
        try {
            // If request has no data, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $gender
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:64|unique:gender,name,' . $gender->id,
            ]);

            $gender->update($validatedData);
            return response()->json([
                'message' => 'Gender updated successfully',
                'data' => $gender
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified gender.
     */
    public function destroy(Gender $gender): JsonResponse
    {
        // Check if gender has associated employees
        if ($gender->employees()->exists()) {
            return response()->json([
                'error' => 'Cannot delete gender with associated employees'
            ], 422);
        }

        $gender->delete();
        return response()->json(['message' => 'Gender deleted successfully.']);
    }
} 