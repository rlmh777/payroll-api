<?php

namespace Tests\Unit\Payroll;

use App\Models\PayPeriodSchedule;
use App\Models\PayrollSetting;
use App\Modules\Payroll\Services\TimesheetPayrollLockService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimesheetPayrollLockServiceTest extends TestCase
{
    #[Test]
    public function it_schedules_lock_one_day_after_pay_date_at_five_pm(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        $service = new TimesheetPayrollLockService();
        $setting = new PayrollSetting([
            'timesheetAutoLockEnabled' => true,
            'timesheetAutoLockTime' => '17:00',
            'timesheetAutoLockDaysAfterPayDate' => 1,
        ]);

        $schedule = new PayPeriodSchedule([
            'start_date' => '2026-05-16',
            'end_date' => '2026-05-31',
            'pay_date' => '2026-05-31',
        ]);

        $dueAt = $service->lockDueAt($schedule, $setting);

        $this->assertSame('2026-06-01 17:00:00', $dueAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-01', $service->proposedLockBeforeDate($schedule));

        Carbon::setTestNow();
    }

    #[Test]
    public function it_uses_configured_days_after_pay_date(): void
    {
        $service = new TimesheetPayrollLockService();
        $setting = new PayrollSetting([
            'timesheetAutoLockEnabled' => true,
            'timesheetAutoLockTime' => '17:00',
            'timesheetAutoLockDaysAfterPayDate' => 2,
        ]);

        $schedule = new PayPeriodSchedule([
            'start_date' => '2026-05-16',
            'end_date' => '2026-05-31',
            'pay_date' => '2026-05-31',
        ]);

        $dueAt = $service->lockDueAt($schedule, $setting);

        $this->assertSame('2026-06-02 17:00:00', $dueAt->format('Y-m-d H:i:s'));
    }
}
