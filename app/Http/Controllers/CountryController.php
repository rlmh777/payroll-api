<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CountryController extends Controller
{
    /**
     * Display a listing of countries.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Country::query();

        // Search by name, code1, code2, or nationalityName
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code1', 'ilike', "%{$search}%")
                    ->orWhere('code2', 'ilike', "%{$search}%")
                    ->orWhere('nationalityName', 'ilike', "%{$search}%");
            });
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['name', 'code1', 'code2', 'nationalityName'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 20);
        $countries = $query->paginate($perPage);

        return response()->json($countries);
    }

    /**
     * Store a newly created country.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'code1' => 'required|string|size:2',
                'code2' => 'required|string|size:3',
                'nationalityName' => 'required|string|max:255',
            ]);

            $country = Country::create($validatedData);
            return response()->json([
                'message' => 'Country created successfully',
                'data' => $country
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified country.
     */
    public function show(Country $country): JsonResponse
    {
        return response()->json($country, 200);
    }

    /**
     * Update the specified country.
     */
    public function update(Request $request, Country $country): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $country
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'code1' => 'sometimes|string|size:2',
                'code2' => 'sometimes|string|size:3',
                'nationalityName' => 'sometimes|string|max:255',
            ]);

            $country->update($validatedData);
            return response()->json([
                'message' => 'Country updated successfully',
                'data' => $country
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified country.
     */
    public function destroy(Country $country): JsonResponse
    {
        // Check if country has districts
        if ($country->districts()->exists()) {
            return response()->json([
                'error' => 'Cannot delete country with associated districts'
            ], 422);
        }

        // Check if country has employees
        if ($country->employees()->exists()) {
            return response()->json([
                'error' => 'Cannot delete country with associated employees'
            ], 422);
        }

        $country->delete();
        return response()->json(['message' => 'Country deleted successfully.']);
    }

    /**
     * Get districts for a specific country.
     */
    public function districts(Request $request, Country $country): JsonResponse
    {
        $query = $country->districts()->orderBy('name');

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
        }

        // Pagination
        $perPage = $request->get('per_page', 20);
        $districts = $query->paginate($perPage);

        return response()->json([
            'country' => [
                'id' => $country->id,
                'name' => $country->name,
                'code1' => $country->code1,
                'code2' => $country->code2,
                'nationalityName' => $country->nationalityName
            ],
            'districts' => $districts
        ]);
    }
}