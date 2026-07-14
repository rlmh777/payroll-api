<?php

namespace Tests\Unit\Attendance;

use App\Http\Requests\Attendance\ApproveTimesheetRequest;
use App\Models\EmploymentDetail;
use App\Models\PayPeriodSchedule;
use App\Models\PayrollSetting;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetEditLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class TimesheetEditLockServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approve_timesheet_request_allows_pending_status(): void
    {
        $request = new ApproveTimesheetRequest();
        $validator = Validator::make(
            ['approvalStatus' => 'PENDING'],
            $request->rules(),
            $request->messages(),
        );

        $this->assertFalse($validator->fails());
    }

    public function test_timesheet_is_locked_on_and_after_pay_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06'));

        $schedule = new PayPeriodSchedule([
            'id' => (string) Str::uuid(),
            'pay_period_group_id' => (string) Str::uuid(),
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'pay_date' => '2026-07-05',
        ]);

        $lockInfo = $this->lockInfoForSchedule($schedule, '2026-06-15');

        $this->assertTrue($lockInfo['isLocked']);
        $this->assertFalse($lockInfo['isDateUnlocked']);
        $this->assertSame('2026-07-05', $lockInfo['payDate']);
        $this->assertNotNull($lockInfo['lockReason']);
    }

    public function test_timesheet_is_editable_before_pay_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04'));

        $schedule = new PayPeriodSchedule([
            'id' => (string) Str::uuid(),
            'pay_period_group_id' => (string) Str::uuid(),
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'pay_date' => '2026-07-05',
        ]);

        $lockInfo = $this->lockInfoForSchedule($schedule, '2026-06-15');

        $this->assertFalse($lockInfo['isLocked']);
        $this->assertSame('2026-07-05', $lockInfo['payDate']);
        $this->assertNull($lockInfo['lockReason']);
    }

    public function test_work_dates_in_payroll_settings_range_are_unlocked_after_pay_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06'));

        $schedule = new PayPeriodSchedule([
            'id' => (string) Str::uuid(),
            'pay_period_group_id' => (string) Str::uuid(),
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'pay_date' => '2026-07-05',
        ]);

        $lockInfo = $this->lockInfoForSchedule(
            $schedule,
            '2026-06-15',
            unlockStart: '2026-06-01',
            unlockEnd: '2026-06-30',
        );

        $this->assertFalse($lockInfo['isLocked']);
        $this->assertTrue($lockInfo['isDateUnlocked']);
    }

    public function test_work_dates_outside_payroll_settings_range_stay_locked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06'));

        $schedule = new PayPeriodSchedule([
            'id' => (string) Str::uuid(),
            'pay_period_group_id' => (string) Str::uuid(),
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'pay_date' => '2026-07-05',
        ]);

        $lockInfo = $this->lockInfoForSchedule(
            $schedule,
            '2026-06-15',
            unlockStart: '2026-05-01',
            unlockEnd: '2026-05-31',
        );

        $this->assertTrue($lockInfo['isLocked']);
        $this->assertFalse($lockInfo['isDateUnlocked']);
    }

    /**
     * @return array{
     *   isLocked: bool,
     *   isPayDatePassed: bool,
     *   isDateUnlocked: bool,
     *   lockReason: ?string,
     *   payDate: ?string
     * }
     */
    private function lockInfoForSchedule(
        PayPeriodSchedule $schedule,
        string $workDate,
        ?string $unlockStart = null,
        ?string $unlockEnd = null,
    ): array {
        $employmentDetail = new EmploymentDetail([
            'id' => (string) Str::uuid(),
            'defaultPayPeriodGroupId' => (string) $schedule->pay_period_group_id,
        ]);

        $timesheet = new Timesheet([
            'id' => (string) Str::uuid(),
            'employeeId' => (string) Str::uuid(),
            'employmentDetailId' => $employmentDetail->id,
            'date' => $workDate,
        ]);
        $timesheet->setRelation('employmentDetail', $employmentDetail);

        $service = new TimesheetEditLockService();
        $cacheKey = (new ReflectionMethod(TimesheetEditLockService::class, 'cacheKey'))
            ->invoke($service, $timesheet);

        $scheduleCache = new \ReflectionProperty(TimesheetEditLockService::class, 'scheduleCache');
        $scheduleCache->setAccessible(true);
        $scheduleCache->setValue($service, [$cacheKey => $schedule]);

        $setting = new PayrollSetting([
            'timesheetUnlockStartDate' => $unlockStart,
            'timesheetUnlockEndDate' => $unlockEnd,
        ]);
        $payrollSetting = new \ReflectionProperty(TimesheetEditLockService::class, 'payrollSetting');
        $payrollSetting->setAccessible(true);
        $payrollSetting->setValue($service, $setting);

        return $service->lockInfo($timesheet);
    }
}
