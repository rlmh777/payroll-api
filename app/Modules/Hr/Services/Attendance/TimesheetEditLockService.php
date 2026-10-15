<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\PayrollSetting;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetEditLockService
{
    private ?PayrollSetting $payrollSetting = null;

    /**
     * @return array{
     *   isLocked: bool,
     *   isPayDatePassed: bool,
     *   isDateUnlocked: bool,
     *   lockReason: ?string,
     *   payDate: ?string,
     *   lockBeforeDate: ?string
     * }
     */
    public function lockInfo(Timesheet $timesheet): array
    {
        $setting = $this->payrollSetting ??= PayrollSetting::current();
        $lockBeforeDate = $setting->timesheetLockBeforeDate
            ? Carbon::parse($setting->timesheetLockBeforeDate)->toDateString()
            : null;
        $workDate = $timesheet->date?->toDateString();
        $unlockWindow = $this->unlockWindow($setting);
        $isDateUnlocked = $this->isWorkDateInUnlockWindow($workDate, $unlockWindow);

        $isLocked = ! $isDateUnlocked
            && $lockBeforeDate !== null
            && $workDate !== null
            && $workDate < $lockBeforeDate;

        return [
            'isLocked' => $isLocked,
            'isPayDatePassed' => false,
            'isDateUnlocked' => $isDateUnlocked,
            'lockReason' => $this->lockReason($isLocked, $isDateUnlocked, $lockBeforeDate, $unlockWindow),
            'payDate' => null,
            'lockBeforeDate' => $lockBeforeDate,
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
    }

    /**
     * @return array{start: ?string, end: ?string}
     */
    private function unlockWindow(PayrollSetting $setting): array
    {
        return [
            'start' => $setting->timesheetUnlockStartDate?->toDateString(),
            'end' => $setting->timesheetUnlockEndDate?->toDateString(),
        ];
    }

    /**
     * @param array{start: ?string, end: ?string} $unlockWindow
     */
    private function isWorkDateInUnlockWindow(?string $workDate, array $unlockWindow): bool
    {
        if ($workDate === null || $unlockWindow['start'] === null || $unlockWindow['end'] === null) {
            return false;
        }

        return $workDate >= $unlockWindow['start'] && $workDate <= $unlockWindow['end'];
    }

    /**
     * @param array{start: ?string, end: ?string} $unlockWindow
     */
    private function lockReason(
        bool $isLocked,
        bool $isDateUnlocked,
        ?string $lockBeforeDate,
        array $unlockWindow,
    ): ?string {
        if ($isDateUnlocked) {
            return null;
        }

        if (! $isLocked) {
            return null;
        }

        if ($lockBeforeDate !== null) {
            return sprintf(
                'Timesheet is locked because the work date is before the payroll lock date (%s).',
                $lockBeforeDate,
            );
        }

        if ($unlockWindow['start'] !== null && $unlockWindow['end'] !== null) {
            return sprintf(
                'Timesheet is locked. Temporary unlock window is %s to %s.',
                $unlockWindow['start'],
                $unlockWindow['end'],
            );
        }

        return 'Timesheet is locked and cannot be edited.';
    }
}
