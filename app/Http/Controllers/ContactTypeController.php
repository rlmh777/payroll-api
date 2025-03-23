<?php

namespace App\Http\Controllers;

use App\Models\ContactType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContactTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ContactType::query();

        // Search by name
        if ($request->has('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->search) . '%']);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['name', 'created_at'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $contactTypes = $query->paginate($perPage);

        return response()->json($contactTypes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:contact_type,name',
                'description' => 'nullable|string|max:1024',
                'is_active' => 'boolean',
            ]);

            $contactType = ContactType::create($validatedData);
            return response()->json([
                'message' => 'Contact Type created successfully',
                'data' => $contactType
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ContactType $contactType): JsonResponse
    {
        return response()->json($contactType, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ContactType $contactType): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if ($request->isEmpty()) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $contactType
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255|unique:contact_type,name,' . $contactType->id,
                'description' => 'sometimes|nullable|string|max:1024',
                'is_active' => 'sometimes|boolean',
            ]);

            $contactType->update($validatedData);
            return response()->json([
                'message' => 'Contact Type updated successfully',
                'data' => $contactType
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ContactType $contactType): JsonResponse
    {
        $contactType->delete();
        return response()->json(['message' => 'Contact Type deleted successfully.']);
    }
} 