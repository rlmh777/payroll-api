<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees.
     */
    public function index(Request $request)
    {
        $query = Employee::with([
            'locality',
            'honorific',
            'gender',
            'citizenshipStatus',
            'nationality',
            'defaultPayrateFrequency',
            'paymentMethods',
            'employmentDetails.department'
        ]);

        // Search by name or code
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                // Exact matches
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('firstName', 'like', "%{$search}%")
                  ->orWhere('lastName', 'like', "%{$search}%")
                  ->orWhere('middleName', 'like', "%{$search}%")
                  ->orWhere('maidenName', 'like', "%{$search}%");
                
                // Full name search with Levenshtein distance
                $q->orWhereRaw("CONCAT(firstName, ' ', lastName) LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("CONCAT(lastName, ', ', firstName) LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("CONCAT(firstName, ' ', middleName, ' ', lastName) LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("CONCAT(lastName, ', ', firstName, ' ', middleName) LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("CONCAT(firstName, ' ', middleName, ' ', lastName, ' ', maidenName) LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("CONCAT(lastName, ', ', firstName, ' ', middleName, ' ', maidenName) LIKE ?", ["%{$search}%"]);
            });
        }

        // Filter by gender
        if ($request->has('gender_id')) {
            $query->where('genderId', $request->input('gender_id'));
        }

        // Filter by locality
        if ($request->has('locality_id')) {
            $query->where('localityId', $request->input('locality_id'));
        }

        // Filter by nationality
        if ($request->has('nationality_id')) {
            $query->where('nationalityId', $request->input('nationality_id'));
        }

        // Filter by citizenship status
        if ($request->has('citizenship_status_id')) {
            $query->where('citizenshipStatusId', $request->input('citizenship_status_id'));
        }

        // Sort
        $sortBy = $request->input('sort_by', 'lastName');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 10);
        $employees = $query->paginate($perPage);

        return response()->json($employees);
    }

    /**
     * Store a newly created employee.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:64|unique:employee,code',
            'internalId1' => 'nullable|string|max:64',
            'internalId2' => 'nullable|string|max:64',
            'honorificId' => 'nullable|exists:honorific,id',
            'firstName' => 'required|string|max:255',
            'middleName' => 'nullable|string|max:255',
            'lastName' => 'required|string|max:255',
            'maidenName' => 'nullable|string|max:255',
            'birthdate' => 'required|date',
            'address1' => 'required|string|max:255',
            'address2' => 'nullable|string|max:255',
            'localityId' => 'required|uuid|exists:locality,id',
            'phone' => 'nullable|string|max:12',
            'email' => 'nullable|email|max:255',
            'genderId' => 'required|exists:gender,id',
            'socialSecurityNumber' => 'required|string|max:255',
            'taxIdentificationNumber' => 'nullable|string|max:255',
            'passportNumber' => 'nullable|string|max:255',
            'votersId' => 'nullable|string|max:255',
            'citizenshipStatusId' => 'nullable|exists:citizenship_status,id',
            'nationalityId' => 'nullable|uuid|exists:country,id',
            'payrateFrequencyId' => 'required|exists:payrate_frequency,id',
            'paymentMethodId' => 'required|exists:payment_method,id',
            'notes' => 'nullable|string',
            'picturePath' => 'nullable|string',
            'health' => 'nullable|string',
            'unionMembership' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::create($request->all());

        return response()->json([
            'message' => 'Employee created successfully',
            'data' => $employee->load([
                'locality',
                'honorific',
                'gender',
                'citizenshipStatus',
                'nationality',
                'defaultPayrateFrequency',
                'paymentMethods'
            ])
        ], 201);
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee)
    {
        return response()->json($employee->load([
            'locality',
            'honorific',
            'gender',
            'citizenshipStatus',
            'nationality',
            'defaultPayrateFrequency',
            'paymentMethods',
            'allowances',
            'employeeBanks',
            'contacts',
            'employeeDefaultDeductions',
            'employmentDetails',
            'qualifications'
        ]));
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, Employee $employee)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'string|max:64|unique:employee,code,' . $employee->id,
            'internalId1' => 'nullable|string|max:64',
            'internalId2' => 'nullable|string|max:64',
            'honorificId' => 'nullable|exists:honorific,id',
            'firstName' => 'string|max:255',
            'middleName' => 'nullable|string|max:255',
            'lastName' => 'string|max:255',
            'maidenName' => 'nullable|string|max:255',
            'birthdate' => 'date',
            'address1' => 'string|max:255',
            'address2' => 'nullable|string|max:255',
            'localityId' => 'uuid|exists:locality,id',
            'phone' => 'nullable|string|max:12',
            'email' => 'nullable|email|max:255',
            'genderId' => 'exists:gender,id',
            'socialSecurityNumber' => 'string|max:255',
            'taxIdentificationNumber' => 'nullable|string|max:255',
            'passportNumber' => 'nullable|string|max:255',
            'votersId' => 'nullable|string|max:255',
            'citizenshipStatusId' => 'nullable|exists:citizenship_status,id',
            'nationalityId' => 'nullable|uuid|exists:country,id',
            'payrateFrequencyId' => 'exists:payrate_frequency,id',
            'paymentMethodId' => 'exists:payment_method,id',
            'notes' => 'nullable|string',
            'picturePath' => 'nullable|string',
            'health' => 'nullable|string',
            'unionMembership' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee->update($request->all());

        return response()->json([
            'message' => 'Employee updated successfully',
            'data' => $employee->load([
                'locality',
                'honorific',
                'gender',
                'citizenshipStatus',
                'nationality',
                'defaultPayrateFrequency',
                'paymentMethods'
            ])
        ]);
    }

    /**
     * Remove the specified employee.
     */
    public function destroy(Employee $employee)
    {
        // Check if there are any associated records
        if ($employee->allowances()->exists() ||
            $employee->employeeBanks()->exists() ||
            $employee->contacts()->exists() ||
            $employee->employeeDefaultDeductions()->exists() ||
            $employee->employmentDetails()->exists() ||
            $employee->qualifications()->exists() ||
            $employee->loans()->exists() ||
            $employee->payrolls()->exists() ||
            $employee->leaves()->exists()) {
            return response()->json([
                'message' => 'Cannot delete employee with associated records'
            ], 422);
        }

        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully'
        ]);
    }
}
 