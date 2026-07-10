<?php

namespace App\Http\Controllers;

use App\Models\EmployeeContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeContactController extends Controller
{
    /**
     * Display a listing of employee contacts.
     */
    public function index(Request $request)
    {
        $query = EmployeeContact::with(['employee', 'relationship', 'locality']);

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('firstName', 'ilike', "%{$search}%")
                ->orWhere('lastName', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%");
        }

        // Filter by employee
        if ($request->filled('employeeId') || $request->filled('employee_id')) {
            $query->where(
                'employeeId',
                $request->input('employeeId', $request->input('employee_id')),
            );
        }

        // Filter by relationship
        if ($request->has('relationship_id')) {
            $query->where('relationshipId', $request->input('relationship_id'));
        }

        // Filter by locality
        if ($request->has('locality_id')) {
            $query->where('localityId', $request->input('locality_id'));
        }

        // Filter by type
        if ($request->has('is_dependent')) {
            $query->where('isDependent', $request->boolean('is_dependent'));
        }
        if ($request->has('is_professional_reference')) {
            $query->where('isProfessionalReference', $request->boolean('is_professional_reference'));
        }

        // Sort
        $sortBy = $request->input('sort_by', 'lastName');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 10);
        $contacts = $query->paginate($perPage);

        return response()->json($contacts);
    }

    /**
     * Store a newly created employee contact.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'middleName' => 'nullable|string|max:255',
            'lastName' => 'required|string|max:255',
            'phoneNumber1' => 'required|string|max:20',
            'phoneNumber2' => 'nullable|string|max:20',
            'email' => 'required|email|max:255',
            'address1' => 'required|string|max:512',
            'address2' => 'nullable|string|max:512',
            'localityId' => 'required|uuid|exists:locality,id',
            'relationshipId' => 'required|exists:relationship,id',
            'employeeId' => 'required|uuid|exists:employee,id',
            'isDependent' => 'boolean',
            'isProfessionalReference' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $contact = EmployeeContact::create($request->all());

        return response()->json([
            'message' => 'Employee contact created successfully',
            'data' => $contact->load(['employee', 'relationship', 'locality'])
        ], 201);
    }

    /**
     * Display the specified employee contact.
     */
    public function show(EmployeeContact $employeeContact)
    {
        return response()->json($employeeContact->load(['employee', 'relationship', 'locality']));
    }

    /**
     * Update the specified employee contact.
     */
    public function update(Request $request, EmployeeContact $employeeContact)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'firstName' => 'string|max:255',
            'middleName' => 'nullable|string|max:255',
            'lastName' => 'string|max:255',
            'phoneNumber1' => 'string|max:20',
            'phoneNumber2' => 'nullable|string|max:20',
            'email' => 'email|max:255',
            'address1' => 'string|max:512',
            'address2' => 'nullable|string|max:512',
            'localityId' => 'uuid|exists:locality,id',
            'relationshipId' => 'exists:relationship,id',
            'employeeId' => 'uuid|exists:employee,id',
            'isDependent' => 'boolean',
            'isProfessionalReference' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeContact->update($request->all());

        return response()->json([
            'message' => 'Employee contact updated successfully',
            'data' => $employeeContact->load(['employee', 'relationship', 'locality'])
        ]);
    }

    /**
     * Remove the specified employee contact.
     */
    public function destroy(EmployeeContact $employeeContact)
    {
        $employeeContact->delete();

        return response()->json([
            'message' => 'Employee contact deleted successfully'
        ]);
    }
} 