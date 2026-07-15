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

        $isLocked = $lockBeforeDate !== null
            && $workDate !== null
            && $workDate < $lockBeforeDate;

        return [
            'isLocked' => $isLocked,
            'isPayDatePassed' => false,
            'isDateUnlocked' => false,
            'lockReason' => $isLocked
                ? sprintf(
                    'Timesheet is locked because the work date is before the payroll lock date (%s).',
                    $lockBeforeDate,
                )
                : null,
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
}
