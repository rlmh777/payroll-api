<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InstitutionController extends Controller
{
    /**
     * Display a listing of institutions.
     */
    public function index(Request $request)
    {
        $query = Institution::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Sort
        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 20);
        $institutions = $query->paginate($perPage);

        return response()->json($institutions);
    }

    /**
     * Store a newly created institution.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:256|unique:institution,name'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $institution = Institution::create($request->all());

        return response()->json([
            'message' => 'Institution created successfully',
            'data' => $institution
        ], 201);
    }

    /**
     * Display the specified institution.
     */
    public function show(Institution $institution)
    {
        return response()->json($institution);
    }

    /**
     * Update the specified institution.
     */
    public function update(Request $request, Institution $institution)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:256|unique:institution,name,' . $institution->id
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $institution->update($request->all());

        return response()->json([
            'message' => 'Institution updated successfully',
            'data' => $institution
        ]);
    }

    /**
     * Remove the specified institution.
     */
    public function destroy(Institution $institution)
    {
        // Check if there are any associated qualifications
        if ($institution->qualifications()->exists()) {
            return response()->json([
                'message' => 'Cannot delete institution with associated qualifications'
            ], 422);
        }

        $institution->delete();

        return response()->json([
            'message' => 'Institution deleted successfully'
        ]);
    }
} 