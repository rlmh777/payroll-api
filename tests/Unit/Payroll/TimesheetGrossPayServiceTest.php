<?php

namespace Tests\Unit\Payroll;

use App\Enums\CompensationMethod;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use App\Modules\Payroll\Services\PayrollTimesheetScopeService;
use App\Modules\Payroll\Services\TimesheetGrossPayService;
use Tests\TestCase;

class TimesheetGrossPayServiceTest extends TestCase
{
    private function service(): TimesheetGrossPayService
    {
        return new TimesheetGrossPayService(
            new PayrollTimesheetScopeService(),
            app(EmployeeCompensationResolver::class),
        );
    }

    public function test_hourly_pay_includes_overtime_multiplier(): void
    {
        config(['payroll.overtime_multiplier' => 1.5]);

        $timesheet = new Timesheet([
            'isPaid' => true,
            'payType' => CompensationMethod::HourlyOt->value,
            'hourlyRate' => 20,
            'regularHours' => 40,
            'overtimeHours' => 5,
            'holidayHours' => 0,
        ]);

        $service = $this->service();

        $this->assertSame(950.0, $service->payForTimesheet($timesheet));
    }

    public function test_unpaid_timesheet_returns_zero(): void
    {
        $timesheet = new Timesheet([
            'isPaid' => false,
            'payType' => CompensationMethod::HourlyOt->value,
            'hourlyRate' => 20,
            'regularHours' => 40,
            'overtimeHours' => 0,
            'holidayHours' => 0,
        ]);

        $service = $this->service();

        $this->assertSame(0.0, $service->payForTimesheet($timesheet));
    }
}
