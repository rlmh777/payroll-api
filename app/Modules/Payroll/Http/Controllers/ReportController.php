<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Services\BankUploadReportService;
use App\Modules\Payroll\Services\PayeEmploymentDetailsReportService;
use App\Modules\Payroll\Services\SalaryReviewReportService;
use App\Modules\Payroll\Services\ScheduledVsWorkedHoursReportService;
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
        private readonly SocialSecurityPaymentsByMonthReportService $socialSecurityPaymentsByMonthReportService,
        private readonly BankUploadReportService $bankUploadReportService,
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
        ]);

        $payrollRun = PayrollRun::query()->findOrFail($validated['payroll_run_id']);

        try {
            return response()->json(
                $this->scheduledVsWorkedHoursReportService->build($payrollRun),
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
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'scope' => ['nullable', Rule::in(['yearly', 'monthly'])],
        ]);

        $scope = $validated['scope'] ?? (isset($validated['month']) ? 'monthly' : 'yearly');
        $month = $scope === 'monthly'
            ? (int) ($validated['month'] ?? 0)
            : null;

        if ($scope === 'monthly' && ($month < 1 || $month > 12)) {
            return response()->json([
                'message' => 'Month is required when generating a monthly PAYE report.',
            ], 422);
        }

        try {
            return response()->json(
                $this->payeEmploymentDetailsReportService->build(
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
