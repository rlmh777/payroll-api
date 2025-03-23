<?php

namespace App\Http\Controllers;

use App\Models\Honorific;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HonorificController extends Controller
{
    /**
     * Display a listing of honorifics.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Honorific::query();

        // Search by name
        if ($request->has('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->search) . '%']);
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
        $honorifics = $query->paginate($perPage);

        return response()->json($honorifics);
    }

    /**
     * Store a newly created honorific.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:64',
            ]);

            $honorific = Honorific::create($validatedData);
            return response()->json([
                'message' => 'Honorific created successfully',
                'data' => $honorific
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified honorific.
     */
    public function show(Honorific $honorific): JsonResponse
    {
        return response()->json($honorific, 200);
    }

    /**
     * Update the specified honorific.
     */
    public function update(Request $request, Honorific $honorific): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if ($request->isEmpty()) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $honorific
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:64',
            ]);

            $honorific->update($validatedData);
            return response()->json([
                'message' => 'Honorific updated successfully',
                'data' => $honorific
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified honorific.
     */
    public function destroy(Honorific $honorific): JsonResponse
    {
        // Check if honorific has employees
        if ($honorific->employees()->exists()) {
            return response()->json([
                'error' => 'Cannot delete honorific with associated employees'
            ], 422);
        }

        $honorific->delete();
        return response()->json(['message' => 'Honorific deleted successfully.']);
    }
} 