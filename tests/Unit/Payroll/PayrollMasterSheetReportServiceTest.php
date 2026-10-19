<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PayrollMasterSheetReportService;
use InvalidArgumentException;
use Tests\TestCase;

class PayrollMasterSheetReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayrollMasterSheetReportService::class)->build(
            'group',
            null,
            [['year' => 2024, 'month' => 11]],
        );
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayrollMasterSheetReportService::class)->build(
            PayrollMasterSheetReportService::SCOPE_DEPARTMENT,
            null,
            [['year' => 2024, 'month' => 11]],
        );
    }

    public function test_empty_periods_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayrollMasterSheetReportService::class)->build(
            PayrollMasterSheetReportService::SCOPE_COMPANY,
            null,
            [],
        );
    }

    public function test_invalid_month_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayrollMasterSheetReportService::class)->build(
            PayrollMasterSheetReportService::SCOPE_COMPANY,
            null,
            [['year' => 2024, 'month' => 13]],
        );
    }
}
