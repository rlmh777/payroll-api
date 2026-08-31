<?php

namespace App\Modules\Payroll\Services;

use App\Models\PayPeriodSchedule;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TimesheetPayrollLockService
{
    public function lockDueAt(PayPeriodSchedule $schedule, ?PayrollSetting $setting = null): Carbon
    {
        $setting ??= PayrollSetting::current();
        $timezone = (string) config('app.timezone', 'UTC');
        $payDate = Carbon::parse(
            $schedule->pay_date ?? $schedule->end_date,
            $timezone,
        )->startOfDay();
        $daysAfter = max(0, (int) ($setting->timesheetAutoLockDaysAfterPayDate ?? config('payroll.timesheet_auto_lock.days_after_pay_date', 1)));
        [$hour, $minute] = $this->parseLockTime(
            (string) ($setting->timesheetAutoLockTime ?? config('payroll.timesheet_auto_lock.lock_time', '17:00')),
        );

        return $payDate
            ->copy()
            ->addDays($daysAfter)
            ->setTime($hour, $minute, 0);
    }

    public function proposedLockBeforeDate(PayPeriodSchedule $schedule): string
    {
        return Carbon::parse($schedule->end_date)->addDay()->toDateString();
    }

    /**
     * @return array{applied: int, skipped?: string}
     */
    public function applyDueLocks(?Carbon $asOf = null): array
    {
        $setting = PayrollSetting::current();

        if (! $setting->timesheetAutoLockEnabled) {
            return [
                'applied' => 0,
                'skipped' => 'disabled',
            ];
        }

        $asOf ??= Carbon::now((string) config('app.timezone', 'UTC'));
        $applied = 0;

        DB::transaction(function () use ($asOf, &$applied) {
            $setting = PayrollSetting::query()->lockForUpdate()->first()
                ?? PayrollSetting::current();

            $maxLockBeforeDate = $setting->timesheetLockBeforeDate?->toDateString();

            $runs = PayrollRun::query()
                ->whereRaw('LOWER(status) = ?', ['posted'])
                ->whereNull('timesheet_lock_applied_at')
                ->with('payPeriodSchedule')
                ->lockForUpdate()
                ->get();

            foreach ($runs as $run) {
                $schedule = $run->payPeriodSchedule;
                if (! $schedule?->end_date) {
                    continue;
                }

                if ($asOf->lt($this->lockDueAt($schedule, $setting))) {
                    continue;
                }

                $proposedLockBeforeDate = $this->proposedLockBeforeDate($schedule);

                if ($maxLockBeforeDate === null || $proposedLockBeforeDate > $maxLockBeforeDate) {
                    $maxLockBeforeDate = $proposedLockBeforeDate;
                }

                $run->update([
                    'timesheet_lock_applied_at' => $asOf->copy(),
                ]);
                $applied++;
            }

            if ($maxLockBeforeDate !== $setting->timesheetLockBeforeDate?->toDateString()) {
                $setting->update([
                    'timesheetLockBeforeDate' => $maxLockBeforeDate,
                ]);
            }
        });

        return ['applied' => $applied];
    }

    /**
     * @return list<array{
     *   payrollRunId: string,
     *   payrollNumber: string,
     *   periodStart: ?string,
     *   periodEnd: ?string,
     *   payDate: ?string,
     *   lockDueAt: string,
     *   proposedLockBeforeDate: string,
     *   isOverdue: bool
     * }>
     */
    public function pendingLockSummaries(?Carbon $asOf = null): array
    {
        $setting = PayrollSetting::current();
        $asOf ??= Carbon::now((string) config('app.timezone', 'UTC'));

        if (! $setting->timesheetAutoLockEnabled) {
            return [];
        }

        return PayrollRun::query()
            ->whereRaw('LOWER(status) = ?', ['posted'])
            ->whereNull('timesheet_lock_applied_at')
            ->with('payPeriodSchedule.payPeriodGroup')
            ->get()
            ->map(function (PayrollRun $run) use ($setting, $asOf) {
                $schedule = $run->payPeriodSchedule;
                if (! $schedule?->end_date) {
                    return null;
                }

                $lockDueAt = $this->lockDueAt($schedule, $setting);

                return [
                    'payrollRunId' => (string) $run->id,
                    'payrollNumber' => (string) $run->payrollNumberFormatted,
                    'periodStart' => $schedule->start_date?->toDateString(),
                    'periodEnd' => $schedule->end_date?->toDateString(),
                    'payDate' => $schedule->pay_date?->toDateString(),
                    'lockDueAt' => $lockDueAt->toIso8601String(),
                    'proposedLockBeforeDate' => $this->proposedLockBeforeDate($schedule),
                    'isOverdue' => $asOf->gte($lockDueAt),
                ];
            })
            ->filter()
            ->sortBy('lockDueAt')
            ->values()
            ->all();
    }

    /**
     * @return array{hour: int, minute: int}
     */
    private function parseLockTime(string $lockTime): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($lockTime), $matches)) {
            return [17, 0];
        }

        return [
            max(0, min(23, (int) $matches[1])),
            max(0, min(59, (int) $matches[2])),
        ];
    }
}
