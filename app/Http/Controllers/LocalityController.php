<?php

namespace App\Http\Controllers;

use App\Models\Locality;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocalityController extends Controller
{
    /**
     * Display a listing of localities.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Locality::with('district.country:id,name,code1,code2,nationalityName');

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Filter by district
        if ($request->has('districtId')) {
            $query->where('districtId', $request->districtId);
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
        $localities = $query->paginate($perPage);

        return response()->json($localities);
    }

    /**
     * Store a newly created locality.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'districtId' => 'required|exists:district,id',
            ]);

            $locality = Locality::create($validatedData);
            $locality->load('district.country:id,name,code1,code2,nationalityName');

            return response()->json([
                'message' => 'Locality created successfully',
                'data' => $locality,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified locality.
     */
    public function show(Locality $locality): JsonResponse
    {
        $locality->load('district.country:id,name,code1,code2,nationalityName');
        return response()->json($locality, 200);
    }

    /**
     * Update the specified locality.
     */
    public function update(Request $request, Locality $locality): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $locality
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'districtId' => 'sometimes|exists:district,id',
            ]);

            $locality->update($validatedData);
            $locality->load('district.country:id,name,code1,code2,nationalityName');

            return response()->json([
                'message' => 'Locality updated successfully',
                'data' => $locality,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified locality.
     */
    public function destroy(Locality $locality): JsonResponse
    {
        // Check if locality has worksites
        if ($locality->worksites()->exists()) {
            return response()->json([
                'error' => 'Cannot delete locality with associated worksites'
            ], 422);
        }

        // Check if locality has employees
        if ($locality->employees()->exists()) {
            return response()->json([
                'error' => 'Cannot delete locality with associated employees'
            ], 422);
        }

        // Check if locality has contacts
        if ($locality->contacts()->exists()) {
            return response()->json([
                'error' => 'Cannot delete locality with associated contacts'
            ], 422);
        }

        // Check if locality has companies
        if ($locality->companies()->exists()) {
            return response()->json([
                'error' => 'Cannot delete locality with associated companies'
            ], 422);
        }

        $locality->delete();
        return response()->json(['message' => 'Locality deleted successfully.']);
    }
}