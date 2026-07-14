<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Modules\Hr\Services\Employment\EmploymentContractAssignmentService;
use App\Services\SocialSecurity\SocialSecurityContributionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    public function __construct(
        private readonly SocialSecurityContributionService $socialSecurityContributionService,
        private readonly EmploymentContractAssignmentService $contractAssignmentService,
    ) {
    }
    public function index(Request $request): JsonResponse
    {
        $query = Payroll::query()->with([
            'employee',
            'department',
            'employmentDetail.department',
            'employmentDetail.worksite',
            'employmentDetail.contractType',
            'employmentDetail.defaultPayPeriodGroup',
            'employeeCompensation',
            'payrollRun',
            'earningLines.earningCode',
        ]);

        if ($request->filled('payroll_run_id')) {
            $query->where('payroll_run_id', $request->input('payroll_run_id'));
        }

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->filled('employmentDetailId')) {
            $query->where('employmentDetailId', $request->input('employmentDetailId'));
        }

        if ($request->filled('departmentId')) {
            $query->where('departmentId', $request->input('departmentId'));
        }

        $sortField = $request->get('sort_by', 'date');
        $sortDirection = $request->get('sort_direction', 'desc');

        if (in_array($sortField, ['date', 'grossSalary', 'netSalary'], true)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderByDesc('date');
        }

        return response()->json($query->paginate((int) $request->get('per_page', 10)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $payroll = DB::transaction(function () use ($validated, $request) {
            $earningLines = $validated['earning_lines'] ?? [];
            $autoCalculateSs = (bool) ($validated['auto_calculate_ss'] ?? false);
            unset($validated['earning_lines'], $validated['auto_calculate_ss']);

            if ($autoCalculateSs) {
                $validated = $this->applySocialSecurityCalculation($validated, $request);
            }
            $validated = $this->applyPayrollAssignment($validated);

            $payroll = Payroll::create(array_merge($validated, [
                'id' => (string) Str::uuid(),
            ]));

            $this->syncEarningLines($payroll, $earningLines);

            return $payroll->fresh()->load(['employee', 'department', 'employmentDetail.department', 'employeeCompensation', 'payrollRun', 'earningLines.earningCode', 'earningLines.department']);
        });

        return response()->json([
            'message' => 'Payroll created successfully',
            'data' => $payroll,
        ], 201);
    }

    public function show(Payroll $payroll): JsonResponse
    {
        return response()->json(
            $payroll->load([
                'employee',
                'department',
                'employmentDetail.department',
                'employmentDetail.worksite',
                'employmentDetail.contractType',
                'employmentDetail.defaultPayPeriodGroup',
                'employeeCompensation',
                'payrollRun',
                'earningLines.earningCode',
                'earningLines.department',
                'earningLines.account',
            ])
        );
    }

    public function update(Request $request, Payroll $payroll): JsonResponse
    {
        $validated = $this->validatePayload($request, true);

        $payroll = DB::transaction(function () use ($payroll, $validated, $request) {
            $earningLines = $validated['earning_lines'] ?? null;
            $autoCalculateSs = (bool) ($validated['auto_calculate_ss'] ?? false);
            unset($validated['earning_lines'], $validated['auto_calculate_ss']);

            if ($autoCalculateSs) {
                $merged = array_merge($payroll->only([
                    'employeeId',
                    'date',
                    'ssWages',
                    'grossSalary',
                ]), $validated);
                $validated = $this->applySocialSecurityCalculation($merged, $request);
            }
            $validated = $this->applyPayrollAssignment(array_merge($payroll->only([
                'employeeId',
                'employmentDetailId',
                'employeeCompensationId',
                'departmentId',
                'date',
            ]), $validated));

            $payroll->update($validated);

            if ($earningLines !== null) {
                $payroll->earningLines()->delete();
                $this->syncEarningLines($payroll, $earningLines);
            }

            return $payroll->fresh()->load(['employee', 'department', 'employmentDetail.department', 'employeeCompensation', 'payrollRun', 'earningLines.earningCode', 'earningLines.department']);
        });

        return response()->json([
            'message' => 'Payroll updated successfully',
            'data' => $payroll,
        ]);
    }

    public function destroy(Payroll $payroll): JsonResponse
    {
        $payroll->earningLines()->delete();
        $payroll->delete();

        return response()->json(['message' => 'Payroll deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'employeeId' => [$partial ? 'sometimes' : 'required', 'uuid', 'exists:employee,id'],
            'employmentDetailId' => ['nullable', 'uuid', 'exists:employment_detail,id'],
            'employeeCompensationId' => ['nullable', 'uuid', 'exists:employee_compensation,id'],
            'payroll_run_id' => ['nullable', 'uuid', 'exists:payroll_runs,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'date' => [$partial ? 'sometimes' : 'required', 'date'],
            'totalRegularHours' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'totalOvertimeHours' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'holidayHours' => ['nullable', 'numeric', 'min:0'],
            'tipsAmount' => ['nullable', 'numeric', 'min:0'],
            'bonusAmount' => ['nullable', 'numeric', 'min:0'],
            'employeeSocialSecurityAmount' => [$partial ? 'sometimes' : 'required_without:auto_calculate_ss', 'numeric', 'min:0'],
            'employerSocialSecurityAmount' => [$partial ? 'sometimes' : 'required_without:auto_calculate_ss', 'numeric', 'min:0'],
            'incomeTaxAmount' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'grossSalary' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'taxableGross' => ['nullable', 'numeric', 'min:0'],
            'ssWages' => ['nullable', 'numeric', 'min:0'],
            'netSalary' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'employerCostTotal' => ['nullable', 'numeric', 'min:0'],
            'totalDeductions' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'totalAllowances' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'paymentMethodId' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:payment_method,id'],
            'note' => ['nullable', 'string'],
            'earning_lines' => ['nullable', 'array'],
            'earning_lines.*.payroll_earning_code_id' => ['required_with:earning_lines', 'integer', 'exists:payroll_earning_code,id'],
            'earning_lines.*.departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'earning_lines.*.hours' => ['nullable', 'numeric', 'min:0'],
            'earning_lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'earning_lines.*.amount' => ['required_with:earning_lines', 'numeric', 'min:0'],
            'earning_lines.*.accountId' => ['nullable', 'uuid', 'exists:accounts,id'],
            'earning_lines.*.is_taxable' => ['nullable', 'boolean'],
            'earning_lines.*.is_ss_subject' => ['nullable', 'boolean'],
            'earning_lines.*.source_type' => ['nullable', 'string', 'max:32'],
            'earning_lines.*.source_id' => ['nullable', 'uuid'],
            'earning_lines.*.note' => ['nullable', 'string'],
            'auto_calculate_ss' => ['nullable', 'boolean'],
            'weeks_in_period' => ['nullable', 'numeric', 'min:0'],
            'weekly_insurable_earnings' => ['nullable', 'numeric', 'min:0'],
            'applied_ss_rule_id' => ['nullable', 'uuid', 'exists:social_security_contribution_rule,id'],
            'applied_ss_tier_id' => ['nullable', 'uuid', 'exists:social_security,id'],
            'ss_calculation_detail' => ['nullable', 'array'],
        ];

        return $request->validate($rules);
    }

    /**
     * @param array<int, array<string, mixed>> $earningLines
     */
    private function syncEarningLines(Payroll $payroll, array $earningLines): void
    {
        foreach ($earningLines as $line) {
            $earningCode = PayrollEarningCode::find($line['payroll_earning_code_id']);

            if (!$earningCode) {
                throw ValidationException::withMessages([
                    'earning_lines' => ['Invalid earning code on payroll line.'],
                ]);
            }

            PayrollEarningLine::create([
                'id' => (string) Str::uuid(),
                'payroll_run_id' => $payroll->payroll_run_id,
                'employeeId' => $payroll->employeeId,
                'payroll_id' => $payroll->id,
                'departmentId' => $line['departmentId'] ?? $payroll->departmentId,
                'payroll_earning_code_id' => $earningCode->id,
                'hours' => $line['hours'] ?? null,
                'rate' => $line['rate'] ?? null,
                'amount' => $line['amount'],
                'accountId' => $line['accountId'] ?? $earningCode->account_id,
                'is_taxable' => $line['is_taxable'] ?? $earningCode->is_taxable,
                'is_ss_subject' => $line['is_ss_subject'] ?? $earningCode->is_ss_subject,
                'source_type' => $line['source_type'] ?? 'MANUAL',
                'source_id' => $line['source_id'] ?? null,
                'note' => $line['note'] ?? null,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function applySocialSecurityCalculation(array $validated, Request $request): array
    {
        $employee = Employee::findOrFail($validated['employeeId']);
        $asOf = Carbon::parse($validated['date']);
        $weeklyInsurable = (float) ($request->input('weekly_insurable_earnings')
            ?? $validated['ssWages']
            ?? $validated['grossSalary']
            ?? 0);
        $weeksInPeriod = (float) ($request->input('weeks_in_period') ?? 1);

        $result = $this->socialSecurityContributionService->calculate(
            $employee,
            $asOf,
            $weeklyInsurable,
            $weeksInPeriod,
        );

        $validated['employeeSocialSecurityAmount'] = $result['employee_amount'];
        $validated['employerSocialSecurityAmount'] = $result['employer_amount'];
        $validated['applied_ss_rule_id'] = $result['applied_rule_id'];
        $validated['applied_ss_tier_id'] = $result['applied_tier_id'];
        $validated['ss_calculation_detail'] = $result['detail'];

        if (!isset($validated['ssWages'])) {
            $validated['ssWages'] = $weeklyInsurable;
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function applyPayrollAssignment(array $validated): array
    {
        if (empty($validated['employeeId']) || empty($validated['date'])) {
            return $validated;
        }

        $contractId = $this->contractAssignmentService->resolveContractId(
            (string) $validated['employeeId'],
            $validated['employmentDetailId'] ?? null,
            isset($validated['departmentId']) ? (int) $validated['departmentId'] : null,
            (string) $validated['date'],
            (string) $validated['date'],
        );

        if (!$contractId) {
            return $validated;
        }

        $validated['employmentDetailId'] = $contractId;

        if (empty($validated['employeeCompensationId'])) {
            $compensation = $this->contractAssignmentService->compensationForContractDate(
                $contractId,
                (string) $validated['date'],
            );
            $validated['employeeCompensationId'] = $compensation?->id;
        }

        return $validated;
    }
}
