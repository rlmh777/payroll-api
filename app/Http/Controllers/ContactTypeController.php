<?php

namespace App\Http\Controllers;

use App\Models\ContactType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContactTypeController extends Controller
{
    /**
     * Display a listing of contact types.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ContactType::query();

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
        $contactTypes = $query->paginate($perPage);

        return response()->json($contactTypes);
    }

    /**
     * Store a newly created contact type.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:512',
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
     * Display the specified contact type.
     */
    public function show(ContactType $contactType): JsonResponse
    {
        return response()->json($contactType, 200);
    }

    /**
     * Update the specified contact type.
     */
    public function update(Request $request, ContactType $contactType): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $contactType
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:512',
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
     * Remove the specified contact type.
     */
    public function destroy(ContactType $contactType): JsonResponse
    {
        $contactType->delete();
        return response()->json(['message' => 'Contact Type deleted successfully.']);
    }
}