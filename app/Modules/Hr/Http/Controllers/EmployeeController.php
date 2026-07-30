<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeReporting;
use App\Modules\Hr\Services\EmployeeNameSearch;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Modules\Hr\Services\Employee\EmployeeCodeGenerator;
use App\Modules\Hr\Services\Activity\EmployeeTimeTravelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    private const EMPLOYMENT_RELATIONS = [
        'employmentDetails.department',
        'employmentDetails.worksite',
        'employmentDetails.contractType',
        'employmentDetails.jobTitle',
        'employmentDetails.defaultPayPeriodGroup',
        'employeeCompensations',
    ];

    /** Slim payload for scheduler/timesheet browsing (avoids heavy person lookups). */
    private const SCHEDULER_RELATIONS = [
        'employmentDetails.department:id,name',
        'employmentDetails.worksite:id,name',
        'employmentDetails.contractType:id,name',
        'employmentDetails.jobTitle:id,name',
        'employmentDetails.defaultPayPeriodGroup:id,name',
        'employeeCompensations',
    ];

    public function __construct(
        private readonly EmployeeCodeGenerator $codeGenerator,
        private readonly EmployeeTimeTravelService $employeeTimeTravelService,
    ) {
    }

    /**
     * @return array<int, string>
     */
    private function relationsForRequest(Request $request): array
    {
        if ($request->input('context') === 'scheduler') {
            return self::SCHEDULER_RELATIONS;
        }

        return array_merge(self::EMPLOYMENT_RELATIONS, EmployeePersonSync::defaultRelations());
    }

    public function byUser(Request $request, string $userId)
    {
        $employee = Employee::query()
            ->with($this->relationsForRequest($request))
            ->where('user_id', $userId)
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        return response()->json($employee);
    }

    public function subordinates(Request $request, string $employeeId)
    {
        $reportingIds = EmployeeReporting::query()
            ->where('supervisor_id', $employeeId)
            ->where('is_active', true)
            ->pluck('subordinate_id');

        $employees = Employee::query()
            ->with($this->relationsForRequest($request))
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
            ->orderByPersonName('lastName', 'asc')
            ->get();

        return response()->json($employees);
    }

    public function index(Request $request)
    {
        $query = Employee::with($this->relationsForRequest($request));

        if ($request->has('search')) {
            EmployeeNameSearch::apply($query, $request->input('search'));
        }

        if ($request->has('gender_id')) {
            $query->whereHas('person', fn ($person) => $person->where('genderId', $request->input('gender_id')));
        }

        if ($request->has('locality_id')) {
            $query->whereHas('person', fn ($person) => $person->where('localityId', $request->input('locality_id')));
        }

        if ($request->has('nationality_id')) {
            $query->whereHas('person', fn ($person) => $person->where('nationalityId', $request->input('nationality_id')));
        }

        if ($request->has('citizenship_status_id')) {
            $query->whereHas('person', fn ($person) => $person->where('citizenshipStatusId', $request->input('citizenship_status_id')));
        }

        if ($request->filled('employee_status_ids')) {
            $statusIds = collect($request->input('employee_status_ids'))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            if (!empty($statusIds)) {
                $query->whereIn('employeeStatusId', $statusIds);
            }
        } elseif ($request->filled('employee_status_id')) {
            $query->where('employeeStatusId', $request->input('employee_status_id'));
        }

        if ($request->filled('department_id')) {
            $departmentId = (int) $request->input('department_id');
            $query->whereHas('employmentDetails', function ($employment) use ($departmentId) {
                $employment->where('departmentId', $departmentId)
                    ->where('isActive', true);
            });
        }

        if ($request->filled('employment_status_id')) {
            $query->where('employmentStatusId', $request->input('employment_status_id'));
        }

        $sortBy = $request->input('sort_by', 'lastName');
        $sortDirection = $request->input('sort_direction', 'asc');

        if (in_array($sortBy, ['firstName', 'middleName', 'lastName', 'maidenName'], true)) {
            $query->orderByPersonName($sortBy, $sortDirection);
        } elseif (in_array($sortBy, ['code', 'created_at'], true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderByPersonName('lastName', 'asc');
        }

        $perPage = $request->input('per_page', 20);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $input = $request->all();
        $code = trim((string) ($input['code'] ?? ''));

        if ($code === '') {
            $request->merge([
                'code' => $this->codeGenerator->generate(
                    (string) ($input['firstName'] ?? ''),
                    (string) ($input['lastName'] ?? ''),
                ),
            ]);
        }

        $validator = Validator::make($request->all(), self::validationRules());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $employee = EmployeePersonSync::create($data);
        $employee->load(EmployeePersonSync::defaultRelations());

        $response = [
            'message' => 'Employee created successfully',
            'data' => $employee,
        ];

        if ($employee->user) {
            $loginUsername = Str::before($employee->user->email, '@');
            $response['login'] = [
                'username' => $loginUsername,
                'email' => $employee->user->email,
            ];
        }

        return response()->json($response, 201);
    }

    public function show(Employee $employee)
    {
        return response()->json($employee->load(EmployeePersonSync::detailRelations()));
    }

    public function update(Request $request, Employee $employee)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), self::validationRules($employee->id, partial: true));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee = EmployeePersonSync::update($employee, $validator->validated());

        return response()->json([
            'message' => 'Employee updated successfully',
            'data' => $employee->load(EmployeePersonSync::defaultRelations()),
        ]);
    }

    public function destroy(Employee $employee)
    {
        if ($employee->allowances()->exists() ||
            $employee->employeeBanks()->exists() ||
            $employee->contacts()->exists() ||
            $employee->employeeDefaultDeductions()->exists() ||
            $employee->employmentDetails()->exists() ||
            $employee->qualifications()->exists() ||
            $employee->certifications()->exists() ||
            $employee->skills()->exists() ||
            $employee->documents()->exists() ||
            $employee->incidents()->exists() ||
            $employee->loans()->exists() ||
            $employee->payrolls()->exists() ||
            $employee->leaves()->exists()) {
            return response()->json([
                'message' => 'Cannot delete employee with associated records',
            ], 422);
        }

        $person = $employee->person;
        $employee->delete();
        $person?->delete();

        return response()->json([
            'message' => 'Employee deleted successfully',
        ]);
    }

    public function uploadPicture(Request $request, Employee $employee)
    {
        $validator = Validator::make($request->all(), [
            'picture' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $person = $employee->person;

            if ($person?->picturePath && Storage::disk('public')->exists($person->picturePath)) {
                Storage::disk('public')->delete($person->picturePath);
            }

            $file = $request->file('picture');
            $fileName = 'employee_'.$employee->id.'_'.time().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('employees/pictures', $fileName, 'public');

            $person?->update(['picturePath' => $path]);

            return response()->json([
                'message' => 'Picture uploaded successfully',
                'path' => $path,
                'url' => asset('storage/'.$path),
            ]);
        } catch (\Exception $e) {
            Log::error('Error uploading employee picture', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to upload picture',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function timeTravel(Request $request, Employee $employee)
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'in:created,updated,deleted'],
            'resource' => ['nullable', 'string', 'max:128'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(
            $this->employeeTimeTravelService->timeline($employee, $filters),
        );
    }

    private static function validationRules(?string $employeeId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $stringRequired = $partial ? 'sometimes|string' : 'required|string';

        return [
            'user_id' => 'nullable|uuid|exists:users,id|unique:employee,user_id'.($employeeId ? ','.$employeeId : ''),
            'code' => ($partial ? 'sometimes' : 'nullable').'|string|max:64|unique:employee,code'.($employeeId ? ','.$employeeId : ''),
            'internalId1' => 'nullable|string|max:64',
            'internalId2' => 'nullable|string|max:64',
            'honorificId' => 'nullable|exists:honorific,id',
            'firstName' => $stringRequired.'|max:255',
            'middleName' => 'nullable|string|max:255',
            'lastName' => $stringRequired.'|max:255',
            'maidenName' => 'nullable|string|max:255',
            'birthdate' => ($partial ? 'sometimes' : 'required').'|date',
            'address1' => $stringRequired.'|max:255',
            'address2' => 'nullable|string|max:255',
            'localityId' => ($partial ? 'sometimes' : 'required').'|uuid|exists:locality,id',
            'phone' => 'nullable|string|max:12',
            'email' => 'nullable|email|max:255',
            'genderId' => ($partial ? 'sometimes' : 'required').'|exists:gender,id',
            'socialSecurityNumber' => $stringRequired.'|max:255',
            'socialSecurityExpirationDate' => 'nullable|date',
            'taxIdentificationNumber' => 'nullable|string|max:255',
            'passportNumber' => 'nullable|string|max:255',
            'votersId' => 'nullable|string|max:255',
            'citizenshipStatusId' => 'nullable|exists:citizenship_status,id',
            'nationalityId' => 'nullable|uuid|exists:country,id',
            'payrateFrequencyId' => 'nullable|exists:payrate_frequency,id',
            'paymentMethodId' => ($partial ? 'sometimes' : 'required').'|exists:payment_method,id',
            'notes' => 'nullable|string',
            'picturePath' => 'nullable|string',
            'health' => 'nullable|string',
            'unionMembership' => 'nullable|string',
            'employmentStatusId' => 'nullable|integer|exists:employment_status,id',
            'employeeStatusId' => 'nullable|integer|exists:employee_status,id',
            'timesheetTemplateId' => 'nullable|uuid|exists:timesheet_template,id',
            'supervisorId' => array_values(array_filter([
                'nullable',
                'uuid',
                'exists:employee,id',
                $employeeId ? 'not_in:'.$employeeId : null,
            ])),
            'leadId' => array_values(array_filter([
                'nullable',
                'uuid',
                'exists:employee,id',
                $employeeId ? 'not_in:'.$employeeId : null,
            ])),
        ];
    }
}
