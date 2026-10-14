<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Support\MoneyPrecision;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyPrecisionTest extends TestCase
{
    #[Test]
    public function it_keeps_many_decimal_places_when_storing(): void
    {
        $this->assertEqualsWithDelta(12.3456789012, MoneyPrecision::store(12.34567890123), 0.00000000001);
        $this->assertSame(100.1, MoneyPrecision::store('100.1'));
    }

    #[Test]
    public function it_rounds_display_values_to_two_decimals(): void
    {
        $this->assertEqualsWithDelta(12.35, MoneyPrecision::display(12.3456789012), 0.0001);
        $this->assertSame(100.1, MoneyPrecision::display(100.1));
    }
}
