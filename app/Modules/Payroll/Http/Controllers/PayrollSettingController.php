<?php

namespace App\Modules\Payroll\Http\Controllers;

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
            'incomeTaxRate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
            'secondReliefAmount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'timesheetLockBeforeDate' => ['nullable', 'date'],
        ]);

        $setting = PayrollSetting::current();
        $payload = [];

        if (array_key_exists('incomeTaxRate', $validated)) {
            $payload['incomeTaxRate'] = $validated['incomeTaxRate'];
        }

        if (array_key_exists('secondReliefAmount', $validated)) {
            $payload['secondReliefAmount'] = $validated['secondReliefAmount'];
        }

        if (array_key_exists('timesheetLockBeforeDate', $validated)) {
            $payload['timesheetLockBeforeDate'] = $validated['timesheetLockBeforeDate'];
        }

        if ($payload !== []) {
            $setting->update($payload);
        }

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
     *     timesheetLockBeforeDate: ?string
     * }
     */
    private function formatSetting(PayrollSetting $setting): array
    {
        $rate = round((float) $setting->incomeTaxRate, 4);

        return [
            'incomeTaxRate' => $rate,
            'incomeTaxRatePercent' => round($rate * 100, 2),
            'secondReliefAmount' => round((float) $setting->secondReliefAmount, 2),
            'timesheetLockBeforeDate' => $setting->timesheetLockBeforeDate?->format('Y-m-d'),
        ];
    }
}
