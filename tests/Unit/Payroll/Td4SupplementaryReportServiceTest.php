<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\Td4SupplementaryReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class Td4SupplementaryReportServiceTest extends TestCase
{
    public function test_build_rejects_an_end_date_before_the_start_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Td4SupplementaryReportService::class)->build(
            Carbon::parse('2025-02-01'),
            Carbon::parse('2025-01-01'),
            Td4SupplementaryReportService::SCOPE_COMPANY,
        );
    }

    public function test_build_requires_a_department_when_scoped_to_a_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Td4SupplementaryReportService::class)->build(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31'),
            Td4SupplementaryReportService::SCOPE_DEPARTMENT,
        );
    }
}
