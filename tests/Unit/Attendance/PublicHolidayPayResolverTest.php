<?php

namespace Tests\Unit\Attendance;

use App\Modules\Hr\Services\Attendance\PublicHolidayPayResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PublicHolidayPayResolverTest extends TestCase
{
    #[Test]
    public function it_calculates_payable_holiday_hours_with_multiplier(): void
    {
        $resolver = new class extends PublicHolidayPayResolver {
            public function payMultiplierForDate($date): ?float
            {
                return 1.5;
            }
        };

        $this->assertSame(12.0, $resolver->payableHolidayHours(8, '2026-01-01'));
    }

    #[Test]
    public function it_returns_zero_payable_holiday_hours_on_non_holidays(): void
    {
        $resolver = new class extends PublicHolidayPayResolver {
            public function payMultiplierForDate($date): ?float
            {
                return null;
            }
        };

        $this->assertSame(0.0, $resolver->payableHolidayHours(8, '2026-01-02'));
    }
}
