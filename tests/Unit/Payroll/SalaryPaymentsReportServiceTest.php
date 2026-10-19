<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollEarningCode;
use App\Modules\Payroll\Services\SalaryPaymentsReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class SalaryPaymentsReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SalaryPaymentsReportService::class)->build(
            Carbon::parse('2022-11-01'),
            Carbon::parse('2023-07-30'),
            'group',
        );
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SalaryPaymentsReportService::class)->build(
            Carbon::parse('2022-11-01'),
            Carbon::parse('2023-07-30'),
            SalaryPaymentsReportService::SCOPE_DEPARTMENT,
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SalaryPaymentsReportService::class)->build(
            Carbon::parse('2023-07-30'),
            Carbon::parse('2022-11-01'),
            SalaryPaymentsReportService::SCOPE_COMPANY,
        );
    }

    public function test_columns_come_from_employee_department_contract_compensation_and_earning_codes(): void
    {
        $regular = new PayrollEarningCode([
            'code' => 'REG',
            'name' => 'Regular Pay',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_REGULAR,
            'is_active' => true,
        ]);
        $regular->id = 1;
        $tips = new PayrollEarningCode([
            'code' => 'TIPS',
            'name' => 'Tips',
            'source' => PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK,
            'is_active' => true,
        ]);
        $tips->id = 2;

        $columns = SalaryPaymentsReportService::describeColumns(collect([$regular, $tips]), [], ['earning:2:pay']);
        $categories = array_values(array_unique(array_column($columns, 'category')));
        $keys = array_column($columns, 'key');

        $this->assertEqualsCanonicalizing(
            [
                SalaryPaymentsReportService::CATEGORY_EMPLOYEE,
                SalaryPaymentsReportService::CATEGORY_DEPARTMENT,
                SalaryPaymentsReportService::CATEGORY_CONTRACT,
                SalaryPaymentsReportService::CATEGORY_COMPENSATION,
                SalaryPaymentsReportService::CATEGORY_EARNINGS,
            ],
            $categories,
        );
        $this->assertContains('employeeCode', $keys);
        $this->assertContains('department', $keys);
        $this->assertContains('jobTitle', $keys);
        $this->assertContains('hourlyRate', $keys);
        $this->assertContains('earning:1:hours', $keys);
        $this->assertContains('earning:1:pay', $keys);
        $this->assertContains('earning:2:pay', $keys);
        $this->assertContains('gross', $keys);
        $this->assertNotContains('butler', $keys);

        $tipsColumn = collect($columns)->firstWhere('key', 'earning:2:pay');
        $this->assertTrue($tipsColumn['defaultVisible']);
        $this->assertSame('Tips', $tipsColumn['label']);
    }
}
