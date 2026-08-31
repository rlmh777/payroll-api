<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\PayrollSetting;
use App\Modules\Payroll\Services\TimesheetPayrollLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayrollSettingController extends Controller
{
    public function __construct(
        private readonly TimesheetPayrollLockService $timesheetPayrollLockService,
    ) {
    }

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
            'timesheetAutoLockEnabled' => ['sometimes', 'boolean'],
            'timesheetAutoLockTime' => ['sometimes', 'date_format:H:i'],
            'timesheetAutoLockDaysAfterPayDate' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'timesheetUnlockStartDate' => ['nullable', 'date'],
            'timesheetUnlockEndDate' => ['nullable', 'date'],
        ]);

        if (
            array_key_exists('timesheetUnlockStartDate', $validated)
            && array_key_exists('timesheetUnlockEndDate', $validated)
            && $validated['timesheetUnlockStartDate'] !== null
            && $validated['timesheetUnlockEndDate'] !== null
            && $validated['timesheetUnlockEndDate'] < $validated['timesheetUnlockStartDate']
        ) {
            throw ValidationException::withMessages([
                'timesheetUnlockEndDate' => ['Unlock end date must be on or after the start date.'],
            ]);
        }

        $setting = PayrollSetting::current();
        $payload = [];

        foreach ([
            'incomeTaxRate',
            'secondReliefAmount',
            'timesheetLockBeforeDate',
            'timesheetAutoLockEnabled',
            'timesheetAutoLockTime',
            'timesheetAutoLockDaysAfterPayDate',
            'timesheetUnlockStartDate',
            'timesheetUnlockEndDate',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
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
     * @return array<string, mixed>
     */
    private function formatSetting(PayrollSetting $setting): array
    {
        $rate = round((float) $setting->incomeTaxRate, 4);
        $unlockStart = $setting->timesheetUnlockStartDate?->format('Y-m-d');
        $unlockEnd = $setting->timesheetUnlockEndDate?->format('Y-m-d');

        return [
            'incomeTaxRate' => $rate,
            'incomeTaxRatePercent' => round($rate * 100, 2),
            'secondReliefAmount' => round((float) $setting->secondReliefAmount, 2),
            'timesheetLockBeforeDate' => $setting->timesheetLockBeforeDate?->format('Y-m-d'),
            'timesheetAutoLockEnabled' => (bool) ($setting->timesheetAutoLockEnabled ?? true),
            'timesheetAutoLockTime' => (string) ($setting->timesheetAutoLockTime ?? config('payroll.timesheet_auto_lock.lock_time', '17:00')),
            'timesheetAutoLockDaysAfterPayDate' => (int) ($setting->timesheetAutoLockDaysAfterPayDate ?? config('payroll.timesheet_auto_lock.days_after_pay_date', 1)),
            'timesheetUnlockStartDate' => $unlockStart,
            'timesheetUnlockEndDate' => $unlockEnd,
            'timesheetUnlockActive' => $unlockStart !== null
                && $unlockEnd !== null
                && now()->toDateString() <= $unlockEnd,
            'pendingTimesheetLocks' => $this->timesheetPayrollLockService->pendingLockSummaries(),
        ];
    }
}
