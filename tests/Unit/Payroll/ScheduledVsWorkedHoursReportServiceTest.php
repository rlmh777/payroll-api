<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollRun;
use App\Modules\Payroll\Services\ScheduledVsWorkedHoursReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ScheduledVsWorkedHoursReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ScheduledVsWorkedHoursReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            'group',
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ScheduledVsWorkedHoursReportService::class)->build(
            Carbon::parse('2026-10-23'),
            Carbon::parse('2026-10-19'),
            ScheduledVsWorkedHoursReportService::SCOPE_COMPANY,
        );
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ScheduledVsWorkedHoursReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            ScheduledVsWorkedHoursReportService::SCOPE_DEPARTMENT,
        );
    }

    public function test_payroll_run_without_schedule_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $payrollRun = new PayrollRun();
        $payrollRun->id = '00000000-0000-0000-0000-000000000001';

        app(ScheduledVsWorkedHoursReportService::class)->buildFromPayrollRun(
            $payrollRun,
            ScheduledVsWorkedHoursReportService::SCOPE_COMPANY,
        );
    }
}
