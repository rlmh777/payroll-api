<?php

namespace App\Http\Controllers;

use App\Models\Qualification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class QualificationController extends Controller
{
    /**
     * Display a listing of qualifications.
     */
    public function index(Request $request)
    {
        $query = Qualification::with(['employee', 'institution', 'degree']);

        // Search by employee name or institution name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            })->orWhereHas('institution', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // Filter by employee
        if ($request->has('employee_id')) {
            $query->where('employeeId', $request->input('employee_id'));
        }

        // Filter by institution
        if ($request->has('institution_id')) {
            $query->where('institutionId', $request->input('institution_id'));
        }

        // Filter by degree
        if ($request->has('degree_id')) {
            $query->where('degreeId', $request->input('degree_id'));
        }

        // Sort
        $sortBy = $request->input('sort_by', 'from');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 10);
        $qualifications = $query->paginate($perPage);

        return response()->json($qualifications);
    }

    /**
     * Store a newly created qualification.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => 'required|uuid|exists:employee,id',
            'institutionId' => 'required|exists:institution,id',
            'degreeId' => 'required|exists:degree,id',
            'from' => 'required|date',
            'to' => 'nullable|date|after_or_equal:from',
            'note' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $qualification = Qualification::create($request->all());

        return response()->json([
            'message' => 'Qualification created successfully',
            'data' => $qualification->load(['employee', 'institution', 'degree'])
        ], 201);
    }

    /**
     * Display the specified qualification.
     */
    public function show(Qualification $qualification)
    {
        return response()->json($qualification->load(['employee', 'institution', 'degree']));
    }

    /**
     * Update the specified qualification.
     */
    public function update(Request $request, Qualification $qualification)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => 'uuid|exists:employee,id',
            'institutionId' => 'exists:institution,id',
            'degreeId' => 'exists:degree,id',
            'from' => 'date',
            'to' => 'nullable|date|after_or_equal:from',
            'note' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $qualification->update($request->all());

        return response()->json([
            'message' => 'Qualification updated successfully',
            'data' => $qualification->load(['employee', 'institution', 'degree'])
        ]);
    }

    /**
     * Remove the specified qualification.
     */
    public function destroy(Qualification $qualification)
    {
        $qualification->delete();

        return response()->json([
            'message' => 'Qualification deleted successfully'
        ]);
    }
} 