<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeReporting;
use App\Services\Employee\EmployeeNameSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EmployeeController extends Controller
{
    private const EMPLOYMENT_RELATIONS = [
        'employmentDetails.department',
        'employmentDetails.worksite',
        'employmentDetails.contractType',
        'employmentDetails.defaultPayPeriodGroup',
        'employeeCompensations',
    ];

    /**
     * Display employee record for a user.
     */
    public function byUser(string $userId)
    {
        $employee = Employee::query()
            ->with(array_merge(self::EMPLOYMENT_RELATIONS, [
                'employmentStatus',
                'employeeStatus',
                'timesheetTemplate',
            ]))
            ->where('user_id', $userId)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        return response()->json($employee);
    }

    /**
     * Display employees that report to a supervisor or lead.
     */
    public function subordinates(Request $request, string $employeeId)
    {
        $reportingIds = EmployeeReporting::query()
            ->where('supervisor_id', $employeeId)
            ->where('is_active', true)
            ->pluck('subordinate_id');

        $employees = Employee::query()
            ->with(self::EMPLOYMENT_RELATIONS)
            ->when($reportingIds->isNotEmpty(), function ($query) use ($reportingIds) {
                $query->whereIn('id', $reportingIds);
            }, function ($query) use ($employeeId) {
                $query->where('supervisorId', $employeeId)
                    ->orWhere('leadId', $employeeId);
            });

        if ($request->filled('search')) {
            EmployeeNameSearch::apply($employees, $request->input('search'));
        }

        $employees = $employees
            ->orderBy('lastName', 'asc')
            ->get();

        return response()->json($employees);
    }
    /**
     * Display a listing of employees.
     */
    public function index(Request $request)
    {
        $query = Employee::with([
            'user',
            'locality',
            'honorific',
            'gender',
            'citizenshipStatus',
            'nationality',
            'employmentStatus',
            'employeeStatus',
            'timesheetTemplate',
            ...self::EMPLOYMENT_RELATIONS,
        ]);

        // Fuzzy name/code search (pg_trgm when available, ILIKE fallback)
        if ($request->has('search')) {
            EmployeeNameSearch::apply($query, $request->input('search'));
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

        if ($request->filled('employee_status_id')) {
            $query->where('employeeStatusId', $request->input('employee_status_id'));
        }

        if ($request->filled('employment_status_id')) {
            $query->where('employmentStatusId', $request->input('employment_status_id'));
        }

        // Sort
        $sortBy = $request->input('sort_by', 'lastName');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Log the query
        try {
            $sql = method_exists($query, 'toRawSql') 
                ? $query->toRawSql() 
                : $query->toSql() . ' | Bindings: ' . json_encode($query->getBindings());
        } catch (\Exception $e) {
            $sql = $query->toSql() . ' | Bindings: ' . json_encode($query->getBindings());
        }
        
        Log::info('Employee search query', [
            'sql' => $sql,
            'search' => $request->input('search'),
            'filters' => [
                'gender_id' => $request->input('gender_id'),
                'locality_id' => $request->input('locality_id'),
                'nationality_id' => $request->input('nationality_id'),
                'citizenship_status_id' => $request->input('citizenship_status_id'),
            ],
            'sort' => [
                'by' => $sortBy,
                'direction' => $sortDirection,
            ],
            'per_page' => $request->input('per_page', 10),
        ]);

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
            'user_id' => 'nullable|uuid|exists:users,id|unique:employee,user_id',
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
            'unionMembership' => 'nullable|string',
            'employmentStatusId' => 'nullable|integer|exists:employment_status,id',
            'employeeStatusId' => 'nullable|integer|exists:employee_status,id',
            'timesheetTemplateId' => 'nullable|uuid|exists:timesheet_template,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = Employee::create($request->all());

        return response()->json([
            'message' => 'Employee created successfully',
            'data' => $employee->load([
                'user',
                'locality',
                'honorific',
                'gender',
                'citizenshipStatus',
                'nationality',
                'employmentStatus',
                'employeeStatus',
                'timesheetTemplate',
            ])
        ], 201);
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee)
    {
        return response()->json($employee->load([
            'user',
            'locality',
            'honorific',
            'gender',
            'citizenshipStatus',
            'nationality',
            'employmentStatus',
            'employeeStatus',
            'timesheetTemplate',
            'allowances',
            'employeeBanks',
            'contacts',
            'employeeDefaultDeductions',
            'employmentDetails',
            'employeeCompensations',
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
            'user_id' => 'nullable|uuid|exists:users,id|unique:employee,user_id,' . $employee->id,
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
            'unionMembership' => 'nullable|string',
            'employmentStatusId' => 'nullable|integer|exists:employment_status,id',
            'employeeStatusId' => 'nullable|integer|exists:employee_status,id',
            'timesheetTemplateId' => 'nullable|uuid|exists:timesheet_template,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee->update($request->all());

        return response()->json([
            'message' => 'Employee updated successfully',
            'data' => $employee->load([
                'user',
                'locality',
                'honorific',
                'gender',
                'citizenshipStatus',
                'nationality',
                'employmentStatus',
                'employeeStatus',
                'timesheetTemplate',
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
            $employee->certifications()->exists() ||
            $employee->skills()->exists() ||
            $employee->documents()->exists() ||
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

    /**
     * Upload a picture for the specified employee.
     */
    public function uploadPicture(Request $request, Employee $employee)
    {
        $validator = Validator::make($request->all(), [
            'picture' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // Max 5MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Delete old picture if exists
            if ($employee->picturePath && Storage::disk('public')->exists($employee->picturePath)) {
                Storage::disk('public')->delete($employee->picturePath);
            }

            // Store the new picture
            $file = $request->file('picture');
            $fileName = 'employee_' . $employee->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('employees/pictures', $fileName, 'public');

            // Update employee's picturePath
            $employee->update(['picturePath' => $path]);

            // Return the full URL path
            $url = asset('storage/' . $path);

            return response()->json([
                'message' => 'Picture uploaded successfully',
                'path' => $path,
                'url' => $url
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error uploading employee picture', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to upload picture',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
 
