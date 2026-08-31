<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Account;
use App\Models\Allowance;
use App\Models\Employee;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\PayrollRun;
use App\Modules\Payroll\Services\PayPeriodHelper;
use App\Modules\Payroll\Services\PayrollAllowanceAuthorizationService;
use App\Modules\Payroll\Services\PayrollAllowanceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HistoricalEmployeeAllowanceController extends Controller
{
    private const RELATIONS = [
        'employee.person',
        'allowance',
        'payrollRun.payPeriodSchedule.payPeriodGroup',
        'chartOfAccount',
        'department',
    ];

    public function __construct(
        private readonly PayrollAllowanceAuthorizationService $authorization,
        private readonly PayrollAllowanceImportService $importService,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $visibleEmployeeIds = $this->authorization->visibleEmployeeIds($user);

        $draftRuns = PayPeriodHelper::upcomingEditablePayrollRuns();

        $employees = Employee::query()
            ->with([
                'person',
                'employmentDetails' => function ($query) {
                    $query->where('isActive', true)->with('department');
                },
            ])
            ->when(
                $visibleEmployeeIds !== null,
                fn ($query) => $query->whereIn('employee.id', $visibleEmployeeIds->all()),
            )
            ->orderByPersonName('lastName')
            ->limit(500)
            ->get()
            ->map(function (Employee $employee) {
                $firstName = (string) ($employee->firstName ?? '');
                $lastName = (string) ($employee->lastName ?? '');
                $displayName = trim($lastName.($lastName && $firstName ? ', ' : '').$firstName);
                $departmentName = (string) ($employee->employmentDetails
                    ->first()
                    ?->department
                    ?->name ?? '');

                return [
                    'id' => $employee->id,
                    'code' => $employee->code,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'departmentName' => $departmentName,
                    'displayName' => $displayName !== '' ? $displayName : ($employee->code ?? 'Employee'),
                ];
            })
            ->values();

        return response()->json([
            'draftRuns' => $draftRuns,
            'employees' => $employees,
            'allowances' => Allowance::query()->orderBy('name')->get(),
            'accounts' => Account::query()->orderBy('name')->limit(500)->get(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_run_id' => ['required', 'uuid', 'exists:payroll_runs,id'],
            'employee_id' => ['nullable', 'uuid', 'exists:employee,id'],
            'allowance_id' => ['nullable', 'uuid', 'exists:allowance,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $visibleEmployeeIds = $this->authorization->visibleEmployeeIds($user);

        $query = HistoricalEmployeeAllowance::query()
            ->with(self::RELATIONS)
            ->where('payroll_run_id', $validated['payroll_run_id']);

        if ($visibleEmployeeIds !== null) {
            $query->whereIn('employee_id', $visibleEmployeeIds->all());
        }

        if (! empty($validated['employee_id'])) {
            if (! $this->authorization->canAccessEmployee($user, $validated['employee_id'])) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            $query->where('employee_id', $validated['employee_id']);
        }

        if (! empty($validated['allowance_id'])) {
            $query->where('allowance_id', $validated['allowance_id']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($inner) use ($search) {
                $inner->where('note', 'ilike', "%{$search}%")
                    ->orWhereHas('allowance', fn ($q) => $q->where('name', 'ilike', "%{$search}%"));
            });
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json($rows);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_run_id' => ['required', 'uuid', 'exists:payroll_runs,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.employeeIdentifier' => ['required', 'string'],
            'rows.*.employeeName' => ['nullable', 'string'],
            'rows.*.allowanceName' => ['required', 'string'],
            'rows.*.accountCode' => ['nullable', 'string'],
            'rows.*.accountId' => ['nullable', 'uuid', 'exists:accounts,id'],
            'rows.*.allowanceDate' => ['required', 'date'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'rows.*.unitAmount' => ['required', 'numeric', 'min:0'],
            'rows.*.note' => ['nullable', 'string', 'max:5000'],
        ]);

        $payrollRun = PayrollRun::query()->with('payPeriodSchedule')->findOrFail($validated['payroll_run_id']);

        return response()->json($this->importService->preview($payrollRun, $validated['rows']));
    }

    public function importConfirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_run_id' => ['required', 'uuid', 'exists:payroll_runs,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.employeeIdentifier' => ['required', 'string'],
            'rows.*.employeeName' => ['nullable', 'string'],
            'rows.*.allowanceName' => ['required', 'string'],
            'rows.*.accountCode' => ['nullable', 'string'],
            'rows.*.accountId' => ['nullable', 'uuid', 'exists:accounts,id'],
            'rows.*.allowanceDate' => ['required', 'date'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'rows.*.unitAmount' => ['required', 'numeric', 'min:0'],
            'rows.*.note' => ['nullable', 'string', 'max:5000'],
        ]);

        $payrollRun = PayrollRun::query()->with('payPeriodSchedule')->findOrFail($validated['payroll_run_id']);
        $result = $this->importService->confirm($payrollRun, $validated['rows']);

        if (($result['errorCount'] ?? 0) > 0) {
            return response()->json([
                'message' => 'Resolve import errors before posting other payments.',
                ...$result,
            ], 422);
        }

        return response()->json([
            'message' => 'Payroll other payments imported successfully.',
            ...$result,
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $payload = $this->validatedPayload($request);
            $user = $request->user();

            if (! $this->authorization->canAccessEmployee($user, $payload['employee_id'])) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $payrollRun = PayrollRun::query()->with('payPeriodSchedule')->findOrFail($payload['payroll_run_id']);
            $this->authorization->assertUpcomingEditablePayrollRun($payrollRun);
            $this->assertAllowanceDateWithinPayPeriod($payrollRun, $payload['allowance_date'] ?? null);

            $record = HistoricalEmployeeAllowance::query()->create($payload);

            return response()->json([
                'message' => 'Payroll other payment created successfully',
                'data' => $record->load(self::RELATIONS),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function show(Request $request, HistoricalEmployeeAllowance $historicalEmployeeAllowance): JsonResponse
    {
        if (! $this->authorization->canAccessEmployee($request->user(), (string) $historicalEmployeeAllowance->employee_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($historicalEmployeeAllowance->load(self::RELATIONS));
    }

    public function update(Request $request, HistoricalEmployeeAllowance $historicalEmployeeAllowance): JsonResponse
    {
        try {
            if ($request->isMethod('put') && empty($request->all())) {
                return response()->json(['message' => 'No data provided for update'], 422);
            }

            $payload = $this->validatedPayload($request, false);
            $user = $request->user();

            if (! $this->authorization->canAccessEmployee($user, (string) $historicalEmployeeAllowance->employee_id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $employeeId = $payload['employee_id'] ?? (string) $historicalEmployeeAllowance->employee_id;
            if (! $this->authorization->canAccessEmployee($user, $employeeId)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $payrollRunId = $payload['payroll_run_id'] ?? (string) $historicalEmployeeAllowance->payroll_run_id;
            $payrollRun = PayrollRun::query()->with('payPeriodSchedule')->findOrFail($payrollRunId);
            $this->authorization->assertUpcomingEditablePayrollRun($payrollRun);

            if (array_key_exists('allowance_date', $payload)) {
                $this->assertAllowanceDateWithinPayPeriod($payrollRun, $payload['allowance_date']);
            }

            $historicalEmployeeAllowance->update($payload);

            return response()->json([
                'message' => 'Payroll other payment updated successfully',
                'data' => $historicalEmployeeAllowance->fresh(self::RELATIONS),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function destroy(Request $request, HistoricalEmployeeAllowance $historicalEmployeeAllowance): JsonResponse
    {
        if (! $this->authorization->canAccessEmployee($request->user(), (string) $historicalEmployeeAllowance->employee_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $payrollRun = PayrollRun::query()->findOrFail($historicalEmployeeAllowance->payroll_run_id);
        $this->authorization->assertUpcomingEditablePayrollRun($payrollRun);

        $historicalEmployeeAllowance->delete();

        return response()->json(['message' => 'Payroll other payment deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $creating = true): array
    {
        $rules = [
            'employeeId' => [$creating ? 'required' : 'sometimes', 'uuid', 'exists:employee,id'],
            'employee_id' => ['sometimes', 'uuid', 'exists:employee,id'],
            'allowanceId' => [$creating ? 'required' : 'sometimes', 'uuid', 'exists:allowance,id'],
            'allowance_id' => ['sometimes', 'uuid', 'exists:allowance,id'],
            'accountId' => [$creating ? 'required' : 'sometimes', 'uuid', 'exists:accounts,id'],
            'account_id' => ['sometimes', 'uuid', 'exists:accounts,id'],
            'payrollRunId' => [$creating ? 'required' : 'sometimes', 'uuid', 'exists:payroll_runs,id'],
            'payroll_run_id' => ['sometimes', 'uuid', 'exists:payroll_runs,id'],
            'quantity' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0.0001', 'max:999999999.9999'],
            'unitAmount' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:999999999999.99'],
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.99'],
            'allowanceDate' => [$creating ? 'required' : 'sometimes', 'date', 'date_format:Y-m-d'],
            'allowance_date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:5000'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $data = $validator->validated();

        $employeeId = $data['employee_id'] ?? $data['employeeId'] ?? null;
        $allowanceId = $data['allowance_id'] ?? $data['allowanceId'] ?? null;
        $accountId = $data['account_id'] ?? $data['accountId'] ?? null;
        $payrollRunId = $data['payroll_run_id'] ?? $data['payrollRunId'] ?? null;
        $allowanceDate = $data['allowance_date'] ?? $data['allowanceDate'] ?? null;

        $payload = array_filter([
            'employee_id' => $employeeId,
            'allowance_id' => $allowanceId,
            'account_id' => $accountId,
            'payroll_run_id' => $payrollRunId,
            'allowance_date' => $allowanceDate,
            'note' => array_key_exists('note', $data) ? ($data['note'] ?? '') : null,
            'departmentId' => $data['departmentId'] ?? null,
        ], fn ($value, $key) => $value !== null || $key === 'note', ARRAY_FILTER_USE_BOTH);

        if (array_key_exists('quantity', $data) || array_key_exists('unitAmount', $data) || array_key_exists('amount', $data)) {
            $quantity = array_key_exists('quantity', $data)
                ? round((float) $data['quantity'], 4)
                : null;
            $unitAmount = array_key_exists('unitAmount', $data)
                ? round((float) $data['unitAmount'], 2)
                : null;

            if ($quantity !== null) {
                $payload['quantity'] = $quantity;
            }
            if ($unitAmount !== null) {
                $payload['unitAmount'] = $unitAmount;
            }

            if ($quantity !== null && $unitAmount !== null) {
                $payload['amount'] = round($quantity * $unitAmount, 2);
            } elseif (array_key_exists('amount', $data)) {
                $payload['amount'] = round((float) $data['amount'], 2);
            }
        }

        if ($allowanceId && array_key_exists('amount', $payload)) {
            $allowance = Allowance::query()->find($allowanceId);
            $amount = (float) $payload['amount'];
            $payload['taxableAmount'] = ($allowance?->isTaxable ?? true) ? $amount : 0;
            $payload['ssSubjectAmount'] = ($allowance?->isSocialSecurityDeductable ?? true) ? $amount : 0;
        }

        if (array_key_exists('note', $payload) && $payload['note'] === null) {
            $payload['note'] = '';
        }

        return $payload;
    }

    private function assertAllowanceDateWithinPayPeriod(PayrollRun $payrollRun, ?string $allowanceDate): void
    {
        if (! $allowanceDate) {
            abort(422, 'Other payment date is required.');
        }

        $schedule = $payrollRun->payPeriodSchedule;
        if (! $schedule) {
            abort(422, 'The payroll run does not have a pay period schedule.');
        }

        $date = Carbon::parse($allowanceDate)->startOfDay();
        $start = Carbon::parse($schedule->start_date)->startOfDay();
        $end = Carbon::parse($schedule->end_date)->startOfDay();

        if ($date->lt($start) || $date->gt($end)) {
            abort(422, 'Other payment date must fall within the selected pay period.');
        }
    }
}
