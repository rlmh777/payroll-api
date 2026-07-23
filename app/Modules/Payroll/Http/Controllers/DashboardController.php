<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_run_id' => ['nullable', 'uuid', 'exists:payroll_runs,id'],
            'months' => ['nullable', 'integer', 'min:3', 'max:24'],
        ]);

        return response()->json(
            $this->dashboardService->build(
                $validated['payroll_run_id'] ?? null,
                (int) ($validated['months'] ?? 6),
            ),
        );
    }
}
