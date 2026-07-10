<?php

namespace Tests\Unit\Employment;

use App\Enums\CompensationMethod;
use App\Models\EmployeeCompensation;
use App\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EmployeeCompensationResolverTest extends TestCase
{
    private EmployeeCompensationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(EmployeeCompensationResolver::class);
    }

    public function test_compensation_for_date_prefers_active_record_in_range(): void
    {
        $records = Collection::make([
            $this->makeCompensation('2024-01-01', '2024-06-30', false, 'HOURLY_OT', true, 10, 0, null),
            $this->makeCompensation('2024-07-01', null, true, 'HOURLY_OT', true, 15, 0, null),
        ]);

        $match = $this->resolver->compensationForDate($records, Carbon::parse('2024-08-01'));

        $this->assertNotNull($match);
        $this->assertSame(15.0, (float) $match->hourlyRate);
    }

    public function test_payroll_snapshot_for_hourly_method_includes_derived_annual_base(): void
    {
        $compensation = $this->makeCompensation('2024-01-01', null, true, 'HOURLY_OT', true, 20, 0, 40);

        $snapshot = $this->resolver->payrollSnapshot($compensation);

        $this->assertSame('HOURLY_OT', $snapshot['payType']);
        $this->assertTrue($snapshot['requiresClocking']);
        $this->assertSame(20.0, $snapshot['hourlyRate']);
        $this->assertSame(41600.0, $snapshot['baseSalary']);
        $this->assertSame(40.0, $snapshot['standardWeeklyHours']);
    }

    public function test_payroll_snapshot_for_base_rate_method_uses_standard_weekly_hours(): void
    {
        $compensation = $this->makeCompensation('2024-01-01', null, true, 'BASE_NO_OT', false, 0, 52000, 40);

        $snapshot = $this->resolver->payrollSnapshot($compensation);

        $this->assertSame('BASE_NO_OT', $snapshot['payType']);
        $this->assertFalse($snapshot['requiresClocking']);
        $this->assertSame(25.0, $snapshot['hourlyRate']);
        $this->assertSame(52000.0, $snapshot['baseSalary']);
        $this->assertSame(40.0, $snapshot['standardWeeklyHours']);
    }

    public function test_derived_hourly_rate_uses_custom_standard_weekly_hours(): void
    {
        $compensation = $this->makeCompensation('2024-01-01', null, true, 'BASE_NO_OT', false, 0, 54000, 45);

        $this->assertSame(23.08, $this->resolver->derivedHourlyRateFromYearly(54000, $compensation));
    }

    public function test_derived_monthly_rate_from_yearly(): void
    {
        $this->assertSame(5000.0, $this->resolver->derivedMonthlyRateFromYearly(60000));
    }

    public function test_flat_period_base_pay_requires_base_non_clocking_compensation(): void
    {
        $hourlyOt = $this->makeCompensation('2024-01-01', null, true, 'HOURLY_OT', true, 18, 37440, 40);
        $baseOt = $this->makeCompensation('2024-01-01', null, true, 'BASE_OT', true, 0, 60000, 40);

        $this->assertNull($this->resolver->flatPeriodBasePay($hourlyOt, 1));
        $this->assertNull($this->resolver->flatPeriodBasePay($baseOt, 1));
    }

    public function test_derived_yearly_and_weekly_rates_from_hourly(): void
    {
        $compensation = $this->makeCompensation('2024-01-01', null, true, 'HOURLY_NO_OT', true, 20, 0, 40);

        $this->assertSame(800.0, $this->resolver->derivedWeeklyRateFromHourly(20, $compensation));
        $this->assertSame(41600.0, $this->resolver->derivedYearlyRateFromHourly(20, $compensation));
    }

    public function test_flat_period_base_pay_applies_to_no_overtime_methods(): void
    {
        $hourlyNoOt = $this->makeCompensation('2024-01-01', null, true, 'HOURLY_NO_OT', true, 20, 41600, 40);
        $baseNoOt = $this->makeCompensation('2024-01-01', null, true, 'BASE_NO_OT', false, 24.04, 52000, 40);

        $this->assertNull($this->resolver->flatPeriodBasePay($hourlyNoOt, null));
        $this->assertNull($this->resolver->flatPeriodBasePay($baseNoOt, null));
    }

    public function test_legacy_hourly_maps_to_hourly_ot(): void
    {
        $this->assertSame(CompensationMethod::HourlyOt, CompensationMethod::fromStored('HOURLY'));
    }

    private function makeCompensation(
        string $effectiveDate,
        ?string $endDate,
        bool $isActive,
        string $method,
        bool $requiresClocking,
        float $hourlyRate,
        float $yearlyRate,
        ?float $standardWeeklyHours,
    ): EmployeeCompensation {
        $record = new EmployeeCompensation();
        $record->effectiveDate = $effectiveDate;
        $record->endDate = $endDate;
        $record->isActive = $isActive;
        $record->compensationMethod = $method;
        $record->requiresClocking = $requiresClocking;
        $record->hourlyRate = $hourlyRate;
        $record->yearlyRate = $yearlyRate;
        $record->standardWeeklyHours = $standardWeeklyHours;

        return $record;
    }
}
