<?php

namespace App\Http\Controllers;

use App\Models\Degree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DegreeController extends Controller
{
    /**
     * Display a listing of degrees.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Degree::query();

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
        $perPage = $request->get('per_page', 10);
        $degrees = $query->paginate($perPage);

        return response()->json($degrees);
    }

    /**
     * Store a newly created degree.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:256',
            ]);

            $degree = Degree::create($validatedData);
            return response()->json([
                'message' => 'Degree created successfully',
                'data' => $degree
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified degree.
     */
    public function show(Degree $degree): JsonResponse
    {
        return response()->json($degree, 200);
    }

    /**
     * Update the specified degree.
     */
    public function update(Request $request, Degree $degree): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $degree
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:256',
            ]);

            $degree->update($validatedData);
            return response()->json([
                'message' => 'Degree updated successfully',
                'data' => $degree
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified degree.
     */
    public function destroy(Degree $degree): JsonResponse
    {
        // Check if degree has qualifications
        if ($degree->qualifications()->exists()) {
            return response()->json([
                'error' => 'Cannot delete degree with associated qualifications'
            ], 422);
        }

        $degree->delete();
        return response()->json(['message' => 'Degree deleted successfully.']);
    }
}