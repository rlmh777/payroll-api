<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\SocialSecurity\SocialSecurityContributionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialSecurityContributionController extends Controller
{
    public function __construct(
        private readonly SocialSecurityContributionService $contributionService,
    ) {
    }

    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'as_of' => ['required', 'date'],
            'weekly_insurable_earnings' => ['required', 'numeric', 'min:0'],
            'weeks_in_period' => ['nullable', 'numeric', 'min:0'],
        ]);

        $employee = Employee::findOrFail($validated['employeeId']);
        $asOf = Carbon::parse($validated['as_of']);
        $weeks = (float) ($validated['weeks_in_period'] ?? 1);

        $result = $this->contributionService->calculate(
            $employee,
            $asOf,
            (float) $validated['weekly_insurable_earnings'],
            $weeks,
        );

        return response()->json($result);
    }
}
