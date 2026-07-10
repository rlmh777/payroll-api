<?php

namespace App\Http\Controllers;

use App\Models\PayrollSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->formatSetting(PayrollSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incomeTaxRate' => ['required', 'numeric', 'min:0', 'max:1'],
            'secondReliefAmount' => ['required', 'numeric', 'min:0'],
            'timesheetUnlockStartDate' => ['nullable', 'date', 'required_with:timesheetUnlockEndDate'],
            'timesheetUnlockEndDate' => [
                'nullable',
                'date',
                'required_with:timesheetUnlockStartDate',
                'after_or_equal:timesheetUnlockStartDate',
            ],
        ]);

        $setting = PayrollSetting::current();
        $setting->update([
            'incomeTaxRate' => $validated['incomeTaxRate'],
            'secondReliefAmount' => $validated['secondReliefAmount'],
            'timesheetUnlockStartDate' => $validated['timesheetUnlockStartDate'] ?? null,
            'timesheetUnlockEndDate' => $validated['timesheetUnlockEndDate'] ?? null,
        ]);

        return response()->json([
            'message' => 'Payroll settings updated.',
            'data' => $this->formatSetting($setting->fresh()),
        ]);
    }

    /**
     * @return array{
     *     incomeTaxRate: float,
     *     incomeTaxRatePercent: float,
     *     secondReliefAmount: float,
     *     timesheetUnlockStartDate: ?string,
     *     timesheetUnlockEndDate: ?string
     * }
     */
    private function formatSetting(PayrollSetting $setting): array
    {
        $rate = round((float) $setting->incomeTaxRate, 4);

        return [
            'incomeTaxRate' => $rate,
            'incomeTaxRatePercent' => round($rate * 100, 2),
            'secondReliefAmount' => round((float) $setting->secondReliefAmount, 2),
            'timesheetUnlockStartDate' => $setting->timesheetUnlockStartDate?->format('Y-m-d'),
            'timesheetUnlockEndDate' => $setting->timesheetUnlockEndDate?->format('Y-m-d'),
        ];
    }
}
