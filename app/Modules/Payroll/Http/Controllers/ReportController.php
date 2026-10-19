<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Services\BankUploadReportService;
use App\Modules\Payroll\Services\CompanyPayrollAnalysisByDepartmentReportService;
use App\Modules\Payroll\Services\DepartmentHoursAndPayReportService;
use App\Modules\Payroll\Services\LaborCostToDateReportService;
use App\Modules\Payroll\Services\PayeEmploymentDetailsReportService;
use App\Modules\Payroll\Services\PayeReconciliationEmployeeDetailsReportService;
use App\Modules\Payroll\Services\PayrollMasterSheetReportService;
use App\Modules\Payroll\Services\PayeReportService;
use App\Modules\Payroll\Services\PayrollRegistryReportService;
use App\Modules\Payroll\Services\RegistrySummaryByOfficeDepartmentReportService;
use App\Modules\Payroll\Services\SalaryPaymentsReportService;
use App\Modules\Payroll\Services\SalaryReviewReportService;
use App\Modules\Payroll\Services\ScheduledVsWorkedHoursReportService;
use App\Modules\Payroll\Services\SocialSecurityContributionsReportService;
use App\Modules\Payroll\Services\SocialSecurityDeductionReportService;
use App\Modules\Payroll\Services\EmolumentsAndWithheldTaxReportService;
use App\Modules\Payroll\Services\Td4SummaryReportService;
use App\Modules\Payroll\Services\Td4SupplementaryReportService;
use App\Modules\Payroll\Services\SocialSecurityPaymentsByMonthReportService;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ReportController extends Controller
{
    public function __construct(
        private readonly SalaryReviewReportService $salaryReviewReportService,
        private readonly ScheduledVsWorkedHoursReportService $scheduledVsWorkedHoursReportService,
        private readonly PayeEmploymentDetailsReportService $payeEmploymentDetailsReportService,
        private readonly PayeReconciliationEmployeeDetailsReportService $payeReconciliationEmployeeDetailsReportService,
        private readonly SocialSecurityPaymentsByMonthReportService $socialSecurityPaymentsByMonthReportService,
        private readonly BankUploadReportService $bankUploadReportService,
        private readonly LaborCostToDateReportService $laborCostToDateReportService,
        private readonly DepartmentHoursAndPayReportService $departmentHoursAndPayReportService,
        private readonly CompanyPayrollAnalysisByDepartmentReportService $companyPayrollAnalysisByDepartmentReportService,
        private readonly PayrollMasterSheetReportService $payrollMasterSheetReportService,
        private readonly SalaryPaymentsReportService $salaryPaymentsReportService,
        private readonly PayrollRegistryReportService $payrollRegistryReportService,
        private readonly RegistrySummaryByOfficeDepartmentReportService $registrySummaryByOfficeDepartmentReportService,
        private readonly PayeReportService $payeReportService,
        private readonly SocialSecurityContributionsReportService $socialSecurityContributionsReportService,
        private readonly SocialSecurityDeductionReportService $socialSecurityDeductionReportService,
        private readonly Td4SummaryReportService $td4SummaryReportService,
        private readonly Td4SupplementaryReportService $td4SupplementaryReportService,
        private readonly EmolumentsAndWithheldTaxReportService $emolumentsAndWithheldTaxReportService,
    ) {
    }

    public function salaryReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            return response()->json(
                $this->salaryReviewReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function scheduledVsWorkedHours(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_run_id' => ['required', 'uuid', 'exists:payroll_runs,id'],
            'period' => ['required', Rule::in(['dates', 'payroll_run'])],
            'payroll_run_id' => ['required_if:period,payroll_run', 'nullable', 'uuid', 'exists:payroll_runs,id'],
            'start_date' => ['required_if:period,dates', 'nullable', 'date'],
            'end_date' => ['required_if:period,dates', 'nullable', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department'])],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(['department', 'name', 'position'])],
        ]);

        $scope = (string) $validated['scope'];
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $sortBy = $validated['sort'] ?? [];

        try {
            if ($validated['period'] === 'payroll_run') {
                $payrollRun = PayrollRun::query()->findOrFail($validated['payroll_run_id']);

                return response()->json(
                    $this->scheduledVsWorkedHoursReportService->buildFromPayrollRun(
                        $payrollRun,
                        $scope,
                        $departmentId,
                        $sortBy,
                    ),
                );
            }

            return response()->json(
                $this->scheduledVsWorkedHoursReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    $scope,
                    $departmentId,
                    $sortBy,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function payeEmploymentDetails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2017', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            return response()->json(
                $this->payeEmploymentDetailsReportService->build(
                    (int) $validated['year'],
                    (int) $validated['month'],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function payeEmploymentDetailsPeriods(): JsonResponse
    {
        return response()->json(
            $this->payeEmploymentDetailsReportService->availablePeriods(),
        );
    }

    public function payeReconciliationEmployeeDetails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2017', 'max:2100'],
            'period' => ['required', 'string', Rule::in([
                'annual', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12',
            ])],
        ]);

        try {
            $month = $validated['period'] === 'annual' ? null : (int) $validated['period'];

            return response()->json(
                $this->payeReconciliationEmployeeDetailsReportService->build(
                    (int) $validated['year'],
                    $month,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function payeReconciliationEmployeeDetailsPeriods(): JsonResponse
    {
        return response()->json(
            $this->payeReconciliationEmployeeDetailsReportService->availablePeriods(),
        );
    }

    public function socialSecurityPaymentsByMonth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2017', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            return response()->json(
                $this->socialSecurityPaymentsByMonthReportService->get(
                    (int) $validated['year'],
                    (int) $validated['month'],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function socialSecurityPaymentsByMonthPeriods(): JsonResponse
    {
        return response()->json(
            $this->socialSecurityPaymentsByMonthReportService->availablePeriods(),
        );
    }

    public function recalculateSocialSecurityPaymentsByMonth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2017', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            return response()->json(
                $this->socialSecurityPaymentsByMonthReportService->recalculate(
                    (int) $validated['year'],
                    (int) $validated['month'],
                    $request->user()?->id,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function salaryPayments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department'])],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(SalaryPaymentsReportService::sortKeys())],
        ]);

        $scope = (string) $validated['scope'];
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;

        try {
            return response()->json(
                $this->salaryPaymentsReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    $scope,
                    $departmentId,
                    $validated['sort'] ?? [],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function laborCostToDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department', 'group'])],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'employee_group_id' => ['nullable', 'uuid', 'exists:employee_group,id'],
        ]);

        try {
            return response()->json(
                $this->laborCostToDateReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    (string) $validated['scope'],
                    isset($validated['department_id']) ? (int) $validated['department_id'] : null,
                    $validated['employee_group_id'] ?? null,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function departmentHoursAndPay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', Rule::in(['dates', 'payroll_run'])],
            'payroll_run_id' => ['required_if:period,payroll_run', 'nullable', 'uuid', 'exists:payroll_runs,id'],
            'start_date' => ['required_if:period,dates', 'nullable', 'date'],
            'end_date' => ['required_if:period,dates', 'nullable', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department', 'group'])],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'employee_group_id' => ['nullable', 'uuid', 'exists:employee_group,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(['department', 'name', 'position'])],
        ]);

        $scope = (string) $validated['scope'];
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $employeeGroupId = $validated['employee_group_id'] ?? null;
        $sortBy = $validated['sort'] ?? [];

        try {
            if ($validated['period'] === 'payroll_run') {
                $payrollRun = PayrollRun::query()->findOrFail($validated['payroll_run_id']);

                return response()->json(
                    $this->departmentHoursAndPayReportService->buildFromPayrollRun(
                        $payrollRun,
                        $scope,
                        $departmentId,
                        $employeeGroupId,
                        $sortBy,
                    ),
                );
            }

            return response()->json(
                $this->departmentHoursAndPayReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    $scope,
                    $departmentId,
                    $employeeGroupId,
                    $sortBy,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function companyPayrollAnalysisByDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', Rule::in(['dates', 'payroll_run'])],
            'payroll_run_id' => ['required_if:period,payroll_run', 'nullable', 'uuid', 'exists:payroll_runs,id'],
            'start_date' => ['required_if:period,dates', 'nullable', 'date'],
            'end_date' => ['required_if:period,dates', 'nullable', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            if ($validated['period'] === 'payroll_run') {
                $payrollRun = PayrollRun::query()->findOrFail($validated['payroll_run_id']);

                return response()->json(
                    $this->companyPayrollAnalysisByDepartmentReportService->buildFromPayrollRun($payrollRun),
                );
            }

            return response()->json(
                $this->companyPayrollAnalysisByDepartmentReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function payrollMasterSheet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['company', 'department'])],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'periods' => ['required', 'array', 'min:1'],
            'periods.*.year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'periods.*.month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $scope = (string) $validated['scope'];
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;

        try {
            return response()->json(
                $this->payrollMasterSheetReportService->build(
                    $scope,
                    $departmentId,
                    $validated['periods'],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function payrollRegistry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', Rule::in(['dates', 'payroll_run'])],
            'payroll_run_id' => ['required_if:period,payroll_run', 'nullable', 'uuid', 'exists:payroll_runs,id'],
            'start_date' => ['required_if:period,dates', 'nullable', 'date'],
            'end_date' => ['required_if:period,dates', 'nullable', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department'])],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(PayrollRegistryReportService::sortKeys())],
        ]);

        $scope = (string) $validated['scope'];
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $sortBy = $validated['sort'] ?? [];

        try {
            if ($validated['period'] === 'payroll_run') {
                $payrollRun = PayrollRun::query()->findOrFail($validated['payroll_run_id']);

                return response()->json(
                    $this->payrollRegistryReportService->buildFromPayrollRun(
                        $payrollRun,
                        $scope,
                        $departmentId,
                        $sortBy,
                    ),
                );
            }

            return response()->json(
                $this->payrollRegistryReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    $scope,
                    $departmentId,
                    $sortBy,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function registrySummaryByOfficeDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', Rule::in(['dates', 'payroll_run'])],
            'payroll_run_id' => ['required_if:period,payroll_run', 'nullable', 'uuid', 'exists:payroll_runs,id'],
            'start_date' => ['required_if:period,dates', 'nullable', 'date'],
            'end_date' => ['required_if:period,dates', 'nullable', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            if ($validated['period'] === 'payroll_run') {
                $payrollRun = PayrollRun::query()->findOrFail($validated['payroll_run_id']);

                return response()->json(
                    $this->registrySummaryByOfficeDepartmentReportService->buildFromPayrollRun($payrollRun),
                );
            }

            return response()->json(
                $this->registrySummaryByOfficeDepartmentReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function paye(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department', 'employee'])],
            'department_id' => ['required_if:scope,department', 'nullable', 'integer', 'exists:department,id'],
            'employee_id' => ['required_if:scope,employee', 'nullable', 'uuid', 'exists:employee,id'],
        ]);

        try {
            return response()->json(
                $this->payeReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    (string) $validated['scope'],
                    isset($validated['department_id']) ? (int) $validated['department_id'] : null,
                    isset($validated['employee_id']) ? (string) $validated['employee_id'] : null,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function emolumentsWithheldTaxYears(): JsonResponse
    {
        return response()->json($this->emolumentsAndWithheldTaxReportService->availableYears());
    }

    public function emolumentsWithheldTax(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'scope' => ['required', Rule::in(['company', 'department'])],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(EmolumentsAndWithheldTaxReportService::SORT_KEYS)],
        ]);

        try {
            return response()->json(
                $this->emolumentsAndWithheldTaxReportService->build(
                    (int) $validated['year'],
                    (string) $validated['scope'],
                    isset($validated['department_id']) ? (int) $validated['department_id'] : null,
                    $validated['sort'] ?? [],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function td4Supplementary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department', 'employee'])],
            'department_id' => ['required_if:scope,department', 'nullable', 'integer', 'exists:department,id'],
            'employee_id' => ['required_if:scope,employee', 'nullable', 'uuid', 'exists:employee,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(Td4SupplementaryReportService::SORT_KEYS)],
        ]);

        try {
            return response()->json(
                $this->td4SupplementaryReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    (string) $validated['scope'],
                    isset($validated['department_id']) ? (int) $validated['department_id'] : null,
                    isset($validated['employee_id']) ? (string) $validated['employee_id'] : null,
                    $validated['sort'] ?? [],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function td4SummaryYears(): JsonResponse
    {
        return response()->json($this->td4SummaryReportService->availableYears());
    }

    public function td4Summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        try {
            return response()->json(
                $this->td4SummaryReportService->build((int) $validated['year']),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function socialSecurityContributions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department'])],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:department,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(SocialSecurityContributionsReportService::SORT_KEYS)],
        ]);

        try {
            return response()->json(
                $this->socialSecurityContributionsReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    (string) $validated['scope'],
                    array_map('intval', $validated['department_ids'] ?? []),
                    $validated['sort'] ?? [],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function socialSecurityDeduction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['company', 'department'])],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:department,id'],
            'sort' => ['nullable', 'array'],
            'sort.*' => ['string', Rule::in(SocialSecurityDeductionReportService::SORT_KEYS)],
        ]);

        try {
            return response()->json(
                $this->socialSecurityDeductionReportService->build(
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->startOfDay(),
                    (string) $validated['scope'],
                    array_map('intval', $validated['department_ids'] ?? []),
                    $validated['sort'] ?? [],
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function bankUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_run_id' => ['required', 'uuid', 'exists:payroll_runs,id'],
        ]);

        $payrollRun = PayrollRun::query()->findOrFail($validated['payroll_run_id']);

        try {
            return response()->json(
                $this->bankUploadReportService->build($payrollRun),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
