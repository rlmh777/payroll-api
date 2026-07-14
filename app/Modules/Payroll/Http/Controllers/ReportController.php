<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Services\SalaryReviewReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReportController extends Controller
{
    public function __construct(
        private readonly SalaryReviewReportService $salaryReviewReportService,
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
}
