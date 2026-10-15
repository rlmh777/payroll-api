<?php

namespace App\Modules\Hr\Http\Controllers\Attendance;

use App\Enums\CompensationMethod;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDayWork;
use App\Models\EmploymentDetail;
use App\Modules\Hr\Http\Controllers\Controller;
use App\Modules\Hr\Services\Attendance\EmployeeDayWorkAuthorizationService;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeDayWorkController extends Controller
{
    private const RELATIONS = [
        'employee.person',
        'employmentDetail',
        'employeeCompensation',
        'department',
        'approver.person',
        'createdBy.person',
        'updatedBy.person',
    ];

    public function __construct(
        private readonly EmployeeDayWorkAuthorizationService $authorization,
        private readonly EmployeeCompensationResolver $compensationResolver,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $visibleEmployeeIds = $this->authorization->visibleEmployeeIds($user);

        $employeesQuery = Employee::query()
            ->with(['person', 'employmentDetails' => fn ($q) => $q->where('isActive', true)])
            ->whereHas('employeeCompensations', function ($query) {
                $query->where('isActive', true)
                    ->where('compensationMethod', CompensationMethod::DailyRate->value);
            });

        if ($visibleEmployeeIds !== null) {
            $employeesQuery->whereIn('employee.id', $visibleEmployeeIds->all());
        }

        $employees = $employeesQuery
            ->orderByPersonName('lastName')
            ->limit(500)
            ->get()
            ->map(function (Employee $employee) {
                $firstName = (string) ($employee->firstName ?? '');
                $lastName = (string) ($employee->lastName ?? '');
                $displayName = trim($lastName.($lastName && $firstName ? ', ' : '').$firstName);
                $activeContract = $employee->employmentDetails->first();
                $compensation = EmployeeCompensation::query()
                    ->where('employeeId', $employee->id)
                    ->where('isActive', true)
                    ->where('compensationMethod', CompensationMethod::DailyRate->value)
                    ->orderByDesc('effectiveDate')
                    ->first();

                return [
                    'id' => $employee->id,
                    'code' => $employee->code,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'displayName' => $displayName !== '' ? $displayName : ($employee->code ?? 'Employee'),
                    'employmentDetailId' => $activeContract?->id,
                    'departmentId' => $activeContract?->departmentId,
                    'employeeCompensationId' => $compensation?->id,
                    'dailyRate' => $compensation ? (float) ($compensation->dailyRate ?? 0) : 0,
                ];
            })
            ->values();

        return response()->json([
            'employees' => $employees,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'employee_id' => ['nullable', 'uuid', 'exists:employee,id'],
            'approval_status' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $visibleEmployeeIds = $this->authorization->visibleEmployeeIds($user);

        $query = EmployeeDayWork::query()->with(self::RELATIONS);

        if ($visibleEmployeeIds !== null) {
            $query->whereIn('employeeId', $visibleEmployeeIds->all());
        }

        if (! empty($validated['employee_id'])) {
            if (! $this->authorization->canAccessEmployee($user, $validated['employee_id'])) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            $query->where('employeeId', $validated['employee_id']);
        }

        if (! empty($validated['start_date'])) {
            $query->whereDate('date', '>=', $validated['start_date']);
        }

        if (! empty($validated['end_date'])) {
            $query->whereDate('date', '<=', $validated['end_date']);
        }

        if (! empty($validated['approval_status'])) {
            $query->whereRaw('UPPER("approvalStatus") = ?', [strtoupper($validated['approval_status'])]);
        }

        $rows = $query
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $payload = $this->validatedPayload($request);
            $user = $request->user();

            if (! $this->authorization->canAccessEmployee($user, $payload['employeeId'])) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $exists = EmployeeDayWork::query()
                ->where('employeeId', $payload['employeeId'])
                ->whereDate('date', $payload['date'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'errors' => ['date' => ['A day / trip work entry already exists for this employee on that date.']],
                ], 422);
            }

            $actor = $this->authorization->actorEmployee($user);
            $payload['approvalStatus'] = 'APPROVED';
            $payload['approvalDate'] = Carbon::today()->toDateString();
            $payload['approverId'] = $actor?->id;
            $payload['createdById'] = $actor?->id;
            $payload['updatedById'] = $actor?->id;

            $record = EmployeeDayWork::query()->create($payload);

            return response()->json([
                'message' => 'Day / trip work recorded successfully',
                'data' => $record->load(self::RELATIONS),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function show(Request $request, EmployeeDayWork $employeeDayWork): JsonResponse
    {
        if (! $this->authorization->canAccessEmployee($request->user(), (string) $employeeDayWork->employeeId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($employeeDayWork->load(self::RELATIONS));
    }

    public function update(Request $request, EmployeeDayWork $employeeDayWork): JsonResponse
    {
        try {
            if (! $this->authorization->canAccessEmployee($request->user(), (string) $employeeDayWork->employeeId)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $payload = $this->validatedPayload($request, false);
            $user = $request->user();

            if (isset($payload['employeeId'])
                && ! $this->authorization->canAccessEmployee($user, $payload['employeeId'])) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $employeeId = $payload['employeeId'] ?? (string) $employeeDayWork->employeeId;
            $date = $payload['date'] ?? Carbon::parse($employeeDayWork->date)->toDateString();

            $duplicate = EmployeeDayWork::query()
                ->where('employeeId', $employeeId)
                ->whereDate('date', $date)
                ->where('id', '!=', $employeeDayWork->id)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'errors' => ['date' => ['A day / trip work entry already exists for this employee on that date.']],
                ], 422);
            }

            $actor = $this->authorization->actorEmployee($user);
            $payload['updatedById'] = $actor?->id;

            $units = array_key_exists('units', $payload)
                ? (float) $payload['units']
                : (float) $employeeDayWork->units;
            $dailyRate = array_key_exists('dailyRate', $payload)
                ? (float) $payload['dailyRate']
                : (float) $employeeDayWork->dailyRate;
            $payload['amount'] = round($units * $dailyRate, 2);

            $employeeDayWork->update($payload);

            return response()->json([
                'message' => 'Day / trip work updated successfully',
                'data' => $employeeDayWork->fresh(self::RELATIONS),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function destroy(Request $request, EmployeeDayWork $employeeDayWork): JsonResponse
    {
        if (! $this->authorization->canAccessEmployee($request->user(), (string) $employeeDayWork->employeeId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $employeeDayWork->delete();

        return response()->json(['message' => 'Day / trip work deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $creating = true): array
    {
        $rules = [
            'employeeId' => [$creating ? 'required' : 'sometimes', 'uuid', 'exists:employee,id'],
            'employee_id' => ['sometimes', 'uuid', 'exists:employee,id'],
            'date' => [$creating ? 'required' : 'sometimes', 'date'],
            'units' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0.01', 'max:9999.99'],
            'dailyRate' => ['sometimes', 'numeric', 'min:0'],
            'daily_rate' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:5000'],
            'employmentDetailId' => ['nullable', 'uuid', 'exists:employment_detail,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $employeeId = $data['employee_id'] ?? $data['employeeId'] ?? null;
        $date = isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : null;

        $context = $employeeId && $date
            ? $this->resolveWorkContext($employeeId, $date, $data)
            : null;

        $units = array_key_exists('units', $data) ? round((float) $data['units'], 2) : null;
        $dailyRate = array_key_exists('dailyRate', $data) || array_key_exists('daily_rate', $data)
            ? round((float) ($data['dailyRate'] ?? $data['daily_rate'] ?? 0), 2)
            : ($context['dailyRate'] ?? null);

        if ($creating && ($dailyRate === null || $dailyRate <= 0)) {
            throw ValidationException::withMessages([
                'dailyRate' => ['Daily rate is required. Set it on the employee compensation or provide it here.'],
            ]);
        }

        $payload = array_filter([
            'employeeId' => $employeeId,
            'date' => $date,
            'employmentDetailId' => $data['employmentDetailId'] ?? $context['employmentDetailId'] ?? null,
            'employeeCompensationId' => $context['employeeCompensationId'] ?? null,
            'departmentId' => $data['departmentId'] ?? $context['departmentId'] ?? null,
            'note' => array_key_exists('note', $data) ? ($data['note'] ?? null) : null,
        ], fn ($value, $key) => $value !== null || $key === 'note', ARRAY_FILTER_USE_BOTH);

        if ($units !== null) {
            $payload['units'] = $units;
        }

        if ($dailyRate !== null) {
            $payload['dailyRate'] = $dailyRate;
        }

        $finalUnits = $payload['units'] ?? null;
        $finalRate = $payload['dailyRate'] ?? null;
        if ($finalUnits !== null && $finalRate !== null) {
            $payload['amount'] = round($finalUnits * $finalRate, 2);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   employmentDetailId:?string,
     *   employeeCompensationId:?string,
     *   departmentId:?int,
     *   dailyRate:?float
     * }
     */
    private function resolveWorkContext(string $employeeId, string $date, array $data): array
    {
        $asOf = Carbon::parse($date)->startOfDay();

        $employmentDetailId = $data['employmentDetailId'] ?? null;
        $employmentDetail = $employmentDetailId
            ? EmploymentDetail::query()->find($employmentDetailId)
            : EmploymentDetail::query()
                ->where('employeeId', $employeeId)
                ->where('isActive', true)
                ->whereDate('startDate', '<=', $asOf->toDateString())
                ->where(function ($query) use ($asOf) {
                    $query->whereNull('endDate')
                        ->orWhereDate('endDate', '>=', $asOf->toDateString());
                })
                ->orderByDesc('isActive')
                ->orderByDesc('startDate')
                ->first();

        if ($employmentDetail && (string) $employmentDetail->employeeId !== $employeeId) {
            throw ValidationException::withMessages([
                'employmentDetailId' => ['Employment contract does not belong to this employee.'],
            ]);
        }

        $compensations = EmployeeCompensation::query()
            ->where('employeeId', $employeeId)
            ->orderByDesc('effectiveDate')
            ->get();

        $compensation = $this->compensationResolver->compensationForDate(
            $compensations,
            $asOf,
            $employmentDetail?->id ? (string) $employmentDetail->id : null,
        );

        if ($compensation && ! CompensationMethod::fromStored($compensation->compensationMethod)->isDailyRateBased()) {
            $dailyRateCompensation = $compensations->first(function (EmployeeCompensation $record) use ($asOf) {
                if (! CompensationMethod::fromStored($record->compensationMethod)->isDailyRateBased()) {
                    return false;
                }

                $start = Carbon::parse($record->effectiveDate)->startOfDay();
                $end = $record->endDate ? Carbon::parse($record->endDate)->startOfDay() : null;

                return $start->lte($asOf) && (! $end || $end->gte($asOf));
            });
            $compensation = $dailyRateCompensation;
        }

        return [
            'employmentDetailId' => $employmentDetail?->id ? (string) $employmentDetail->id : null,
            'employeeCompensationId' => $compensation?->id ? (string) $compensation->id : null,
            'departmentId' => $employmentDetail?->departmentId !== null
                ? (int) $employmentDetail->departmentId
                : null,
            'dailyRate' => $this->compensationResolver->effectiveDailyRate($compensation),
        ];
    }
}
