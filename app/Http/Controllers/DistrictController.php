<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DistrictController extends Controller
{
    /**
     * Display a listing of districts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = District::with('country:id,name,code1,code2,nationalityName');

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by country
        if ($request->has('countryId')) {
            $query->where('countryId', $request->countryId);
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
        $districts = $query->paginate($perPage);

        return response()->json($districts);
    }

    /**
     * Store a newly created district.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'countryId' => 'required|exists:country,id',
            ]);

            $district = District::create($validatedData);
            return response()->json([
                'message' => 'District created successfully',
                'data' => $district
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified district.
     */
    public function show(District $district): JsonResponse
    {
        $district->load('country:id,name,code1,code2,nationalityName');
        return response()->json($district, 200);
    }

    /**
     * Update the specified district.
     */
    public function update(Request $request, District $district): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $district
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'countryId' => 'sometimes|exists:country,id',
            ]);

            $district->update($validatedData);
            return response()->json([
                'message' => 'District updated successfully',
                'data' => $district
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified district.
     */
    public function destroy(District $district): JsonResponse
    {
        // Check if district has localities
        if ($district->localities()->exists()) {
            return response()->json([
                'error' => 'Cannot delete district with associated localities'
            ], 422);
        }

        $district->delete();
        return response()->json(['message' => 'District deleted successfully.']);
    }

    /**
     * Get localities for a specific district.
     */
    public function localities(Request $request, District $district): JsonResponse
    {
        $query = $district->localities()->orderBy('name');

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
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $localities = $query->paginate($perPage);

        return response()->json([
            'district' => [
                'id' => $district->id,
                'name' => $district->name,
                'countryId' => $district->countryId
            ],
            'localities' => $localities
        ]);
    }
}