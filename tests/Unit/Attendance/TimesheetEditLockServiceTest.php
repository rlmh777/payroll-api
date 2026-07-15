<?php

namespace Tests\Unit\Attendance;

use App\Http\Requests\Attendance\ApproveTimesheetRequest;
use App\Models\PayrollSetting;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetEditLockService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimesheetEditLockServiceTest extends TestCase
{
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

    public function test_timesheet_is_locked_before_lock_date(): void
    {
        $lockInfo = $this->lockInfoFor('2026-06-15', '2026-07-01');

        $this->assertTrue($lockInfo['isLocked']);
        $this->assertSame('2026-07-01', $lockInfo['lockBeforeDate']);
        $this->assertNotNull($lockInfo['lockReason']);
    }

    public function test_timesheet_is_editable_on_and_after_lock_date(): void
    {
        $onDate = $this->lockInfoFor('2026-07-01', '2026-07-01');
        $afterDate = $this->lockInfoFor('2026-07-02', '2026-07-01');

        $this->assertFalse($onDate['isLocked']);
        $this->assertFalse($afterDate['isLocked']);
        $this->assertNull($onDate['lockReason']);
    }

    public function test_timesheet_is_editable_when_lock_date_unset(): void
    {
        $lockInfo = $this->lockInfoFor('2026-06-15', null);

        $this->assertFalse($lockInfo['isLocked']);
        $this->assertNull($lockInfo['lockBeforeDate']);
    }

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
    private function lockInfoFor(string $workDate, ?string $lockBeforeDate): array
    {
        $timesheet = new Timesheet([
            'id' => (string) Str::uuid(),
            'employeeId' => (string) Str::uuid(),
            'date' => $workDate,
        ]);

        $service = new TimesheetEditLockService();
        $setting = new PayrollSetting([
            'timesheetLockBeforeDate' => $lockBeforeDate,
        ]);
        $payrollSetting = new \ReflectionProperty(TimesheetEditLockService::class, 'payrollSetting');
        $payrollSetting->setAccessible(true);
        $payrollSetting->setValue($service, $setting);

        return $service->lockInfo($timesheet);
    }
}
