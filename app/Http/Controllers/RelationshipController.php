<?php

namespace App\Http\Controllers;

use App\Models\Relationship;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RelationshipController extends Controller
{
    /**
     * Display a listing of relationships.
     */
    public function index(Request $request)
    {
        $query = Relationship::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Sort
        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 10);
        $relationships = $query->paginate($perPage);

        return response()->json($relationships);
    }

    /**
     * Store a newly created relationship.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:128|unique:relationship,name'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $relationship = Relationship::create($request->all());

        return response()->json([
            'message' => 'Relationship created successfully',
            'data' => $relationship
        ], 201);
    }

    /**
     * Display the specified relationship.
     */
    public function show(Relationship $relationship)
    {
        return response()->json($relationship);
    }

    /**
     * Update the specified relationship.
     */
    public function update(Request $request, Relationship $relationship)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:128|unique:relationship,name,' . $relationship->id
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $relationship->update($request->all());

        return response()->json([
            'message' => 'Relationship updated successfully',
            'data' => $relationship
        ]);
    }

    /**
     * Remove the specified relationship.
     */
    public function destroy(Relationship $relationship)
    {
        // Check if there are any associated contacts
        if ($relationship->contacts()->exists()) {
            return response()->json([
                'message' => 'Cannot delete relationship with associated contacts'
            ], 422);
        }

        $relationship->delete();

        return response()->json([
            'message' => 'Relationship deleted successfully'
        ]);
    }
} 