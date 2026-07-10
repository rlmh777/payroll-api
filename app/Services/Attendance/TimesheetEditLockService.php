<?php

namespace App\Services\Attendance;

use App\Models\EmploymentDetail;
use App\Models\PayPeriodSchedule;
use App\Models\PayrollSetting;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetEditLockService
{
    /** @var array<string, PayPeriodSchedule|null> */
    private array $scheduleCache = [];

    private ?PayrollSetting $payrollSetting = null;

    /**
     * @return array{
     *   isLocked: bool,
     *   isPayDatePassed: bool,
     *   isDateUnlocked: bool,
     *   lockReason: ?string,
     *   payDate: ?string
     * }
     */
    public function lockInfo(Timesheet $timesheet): array
    {
        $schedule = $this->resolveSchedule($timesheet);
        $payDate = $schedule?->pay_date
            ? Carbon::parse($schedule->pay_date)->startOfDay()
            : null;

        if (!$payDate) {
            return [
                'isLocked' => false,
                'isPayDatePassed' => false,
                'isDateUnlocked' => false,
                'lockReason' => null,
                'payDate' => null,
            ];
        }

        $isPayDatePassed = Carbon::today()->gte($payDate);
        $isDateUnlocked = $isPayDatePassed && $this->isWorkDateUnlocked($timesheet);
        $isLocked = $isPayDatePassed && !$isDateUnlocked;

        return [
            'isLocked' => $isLocked,
            'isPayDatePassed' => $isPayDatePassed,
            'isDateUnlocked' => $isDateUnlocked,
            'lockReason' => $isLocked
                ? sprintf(
                    'Timesheet is locked because the pay date (%s) has been reached. Unlock work dates under Payroll settings.',
                    $payDate->toDateString(),
                )
                : ($isDateUnlocked
                    ? sprintf(
                        'Pay date (%s) has passed, but this work date is unlocked in Payroll settings.',
                        $payDate->toDateString(),
                    )
                    : null),
            'payDate' => $payDate->toDateString(),
        ];
    }

    public function isLocked(Timesheet $timesheet): bool
    {
        return $this->lockInfo($timesheet)['isLocked'];
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    public function warmCache(Collection $timesheets): void
    {
        $this->payrollSetting = PayrollSetting::current();

        $groupIds = $timesheets
            ->map(function (Timesheet $timesheet) {
                $employmentDetail = $timesheet->relationLoaded('employmentDetail')
                    ? $timesheet->employmentDetail
                    : null;

                return $employmentDetail?->defaultPayPeriodGroupId
                    ? (string) $employmentDetail->defaultPayPeriodGroupId
                    : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($groupIds->isEmpty()) {
            return;
        }

        $dates = $timesheets
            ->map(fn (Timesheet $timesheet) => $timesheet->date?->toDateString())
            ->filter()
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $minDate = $dates->min();
        $maxDate = $dates->max();

        $schedules = PayPeriodSchedule::query()
            ->whereIn('pay_period_group_id', $groupIds)
            ->whereDate('start_date', '<=', $maxDate)
            ->whereDate('end_date', '>=', $minDate)
            ->get();

        foreach ($timesheets as $timesheet) {
            $key = $this->cacheKey($timesheet);
            if ($key === null || array_key_exists($key, $this->scheduleCache)) {
                continue;
            }

            $workDate = $timesheet->date?->toDateString();
            $groupId = $timesheet->employmentDetail?->defaultPayPeriodGroupId
                ? (string) $timesheet->employmentDetail->defaultPayPeriodGroupId
                : null;

            if (!$workDate || !$groupId) {
                $this->scheduleCache[$key] = null;
                continue;
            }

            $this->scheduleCache[$key] = $schedules->first(function (PayPeriodSchedule $schedule) use ($groupId, $workDate) {
                return (string) $schedule->pay_period_group_id === $groupId
                    && $schedule->start_date?->toDateString() <= $workDate
                    && $schedule->end_date?->toDateString() >= $workDate;
            });
        }
    }

    private function isWorkDateUnlocked(Timesheet $timesheet): bool
    {
        $workDate = $timesheet->date?->toDateString();
        if (!$workDate) {
            return false;
        }

        $setting = $this->payrollSetting ??= PayrollSetting::current();
        $unlockStart = $setting->timesheetUnlockStartDate
            ? Carbon::parse($setting->timesheetUnlockStartDate)->toDateString()
            : null;
        $unlockEnd = $setting->timesheetUnlockEndDate
            ? Carbon::parse($setting->timesheetUnlockEndDate)->toDateString()
            : null;

        if (!$unlockStart || !$unlockEnd) {
            return false;
        }

        return $workDate >= $unlockStart && $workDate <= $unlockEnd;
    }

    private function resolveSchedule(Timesheet $timesheet): ?PayPeriodSchedule
    {
        $key = $this->cacheKey($timesheet);
        if ($key !== null && array_key_exists($key, $this->scheduleCache)) {
            return $this->scheduleCache[$key];
        }

        $workDate = $timesheet->date?->toDateString();
        if (!$workDate) {
            return null;
        }

        $groupId = $this->resolvePayPeriodGroupId($timesheet);
        if (!$groupId) {
            if ($key !== null) {
                $this->scheduleCache[$key] = null;
            }

            return null;
        }

        $schedule = PayPeriodSchedule::query()
            ->where('pay_period_group_id', $groupId)
            ->whereDate('start_date', '<=', $workDate)
            ->whereDate('end_date', '>=', $workDate)
            ->orderByDesc('start_date')
            ->first();

        if ($key !== null) {
            $this->scheduleCache[$key] = $schedule;
        }

        return $schedule;
    }

    private function resolvePayPeriodGroupId(Timesheet $timesheet): ?string
    {
        if ($timesheet->relationLoaded('employmentDetail') && $timesheet->employmentDetail?->defaultPayPeriodGroupId) {
            return (string) $timesheet->employmentDetail->defaultPayPeriodGroupId;
        }

        if ($timesheet->employmentDetailId) {
            $groupId = EmploymentDetail::query()
                ->whereKey($timesheet->employmentDetailId)
                ->value('defaultPayPeriodGroupId');

            return $groupId ? (string) $groupId : null;
        }

        $groupId = EmploymentDetail::query()
            ->where('employeeId', $timesheet->employeeId)
            ->where('isActive', true)
            ->orderByDesc('startDate')
            ->value('defaultPayPeriodGroupId');

        return $groupId ? (string) $groupId : null;
    }

    private function cacheKey(Timesheet $timesheet): ?string
    {
        $workDate = $timesheet->date?->toDateString();
        if (!$workDate) {
            return null;
        }

        $groupId = $timesheet->relationLoaded('employmentDetail')
            ? ($timesheet->employmentDetail?->defaultPayPeriodGroupId
                ? (string) $timesheet->employmentDetail->defaultPayPeriodGroupId
                : null)
            : ($timesheet->employmentDetailId
                ? 'detail:'.$timesheet->employmentDetailId
                : 'employee:'.(string) $timesheet->employeeId);

        return $groupId.'|'.$workDate;
    }
}
