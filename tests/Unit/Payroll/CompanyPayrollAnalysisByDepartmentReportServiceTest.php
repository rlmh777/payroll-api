<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollRun;
use App\Modules\Payroll\Services\CompanyPayrollAnalysisByDepartmentReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class CompanyPayrollAnalysisByDepartmentReportServiceTest extends TestCase
{
    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CompanyPayrollAnalysisByDepartmentReportService::class)->build(
            Carbon::parse('2026-10-23'),
            Carbon::parse('2026-10-19'),
        );
    }

    public function test_payroll_run_without_schedule_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $payrollRun = new PayrollRun();
        $payrollRun->id = '00000000-0000-0000-0000-000000000001';

        app(CompanyPayrollAnalysisByDepartmentReportService::class)->buildFromPayrollRun($payrollRun);
    }
}
