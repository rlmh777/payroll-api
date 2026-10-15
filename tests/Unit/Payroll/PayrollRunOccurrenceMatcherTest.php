<?php

namespace Tests\Unit\Payroll;

use App\Enums\PayrollItemOccurrence;
use App\Enums\PayPeriodCadence;
use App\Modules\Payroll\Services\PayrollRunOccurrenceMatcher;
use Tests\TestCase;

class PayrollRunOccurrenceMatcherTest extends TestCase
{
    private PayrollRunOccurrenceMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new PayrollRunOccurrenceMatcher();
    }

    public function test_semi_monthly_fifteenth_and_thirtieth_use_calendar_month_not_period_length(): void
    {
        $schedules = [
            ['id' => 'jan-15', 'pay_date' => '2026-01-15'],
            ['id' => 'jan-30', 'pay_date' => '2026-01-30'],
            ['id' => 'feb-15', 'pay_date' => '2026-02-15'],
            ['id' => 'feb-28', 'pay_date' => '2026-02-28'],
        ];

        $jan15 = $this->matcher->contextForSchedule('jan-15', $schedules, PayPeriodCadence::SemiMonthly);
        $jan30 = $this->matcher->contextForSchedule('jan-30', $schedules, PayPeriodCadence::SemiMonthly);
        $feb15 = $this->matcher->contextForSchedule('feb-15', $schedules, PayPeriodCadence::SemiMonthly);
        $feb28 = $this->matcher->contextForSchedule('feb-28', $schedules, PayPeriodCadence::SemiMonthly);

        $this->assertSame(1, $jan15->monthIndex);
        $this->assertSame(2, $jan15->monthCount);
        $this->assertSame(2, $jan30->monthIndex);
        $this->assertSame(1, $feb15->monthIndex);
        $this->assertSame(2, $feb28->monthIndex);
        $this->assertSame(2, $feb28->monthCount);

        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::EveryPayroll->value, null, null, $jan15));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::EveryPayroll->value, null, null, $jan30));

        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $jan15));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $jan30));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $feb15));

        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $jan15));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $jan30));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $feb28));

        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::NthOfMonth->value, null, 1, $jan15));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::NthOfMonth->value, null, 2, $jan30));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::NthOfMonth->value, null, 2, $jan15));
    }

    public function test_semi_monthly_lone_fifteenth_is_not_last_of_month(): void
    {
        $schedules = [
            ['id' => 'jan-15', 'pay_date' => '2026-01-15'],
        ];

        $jan15 = $this->matcher->contextForSchedule('jan-15', $schedules, PayPeriodCadence::SemiMonthly);

        $this->assertSame(1, $jan15->monthIndex);
        $this->assertSame(2, $jan15->monthCount);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $jan15));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $jan15));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::NthOfMonth->value, null, 2, $jan15));
    }

    public function test_biweekly_projects_remaining_paydays_when_later_schedules_are_missing(): void
    {
        $schedules = [
            ['id' => 'mar-02', 'pay_date' => '2026-03-02'],
        ];

        $first = $this->matcher->contextForSchedule('mar-02', $schedules, PayPeriodCadence::Biweekly);

        $this->assertSame(1, $first->monthIndex);
        $this->assertSame(3, $first->monthCount);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $first));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $first));
    }

    public function test_unknown_cadence_does_not_treat_early_lone_payday_as_last(): void
    {
        $schedules = [
            ['id' => 'jan-15', 'pay_date' => '2026-01-15'],
        ];

        $jan15 = $this->matcher->contextForSchedule('jan-15', $schedules);

        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $jan15));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $jan15));
    }

    public function test_cycle_uses_expected_paydays_from_cadence_not_generated_schedules(): void
    {
        $schedules = [
            ['id' => 'p1', 'pay_date' => '2026-01-15'],
            ['id' => 'p2', 'pay_date' => '2026-01-30'],
            ['id' => 'p3', 'pay_date' => '2026-02-15'],
            ['id' => 'p4', 'pay_date' => '2026-02-28'],
        ];

        $p1 = $this->matcher->contextForSchedule('p1', $schedules, PayPeriodCadence::SemiMonthly);
        $p2 = $this->matcher->contextForSchedule('p2', $schedules, PayPeriodCadence::SemiMonthly);
        $p3 = $this->matcher->contextForSchedule('p3', $schedules, PayPeriodCadence::SemiMonthly);
        $p4 = $this->matcher->contextForSchedule('p4', $schedules, PayPeriodCadence::SemiMonthly);

        $this->assertSame(1, $p1->cycleIndex);
        $this->assertSame(2, $p1->cycleCount);
        $this->assertSame(3, $p3->cycleIndex);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 1, $p1));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 1, $p2));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 1, $p3));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 2, $p2));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 2, $p4));
    }

    public function test_cycle_projects_year_sequence_when_earlier_schedules_are_missing(): void
    {
        $june15 = $this->matcher->contextForSchedule(
            'jun-15',
            [['id' => 'jun-15', 'pay_date' => '2026-06-15']],
            PayPeriodCadence::SemiMonthly,
        );
        $june30 = $this->matcher->contextForSchedule(
            'jun-30',
            [['id' => 'jun-30', 'pay_date' => '2026-06-30']],
            PayPeriodCadence::SemiMonthly,
        );

        $this->assertSame(11, $june15->cycleIndex);
        $this->assertSame(2, $june15->cycleCount);
        $this->assertSame(12, $june30->cycleIndex);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 1, $june15));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 1, $june30));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 2, $june30));
    }

    public function test_biweekly_cycle_uses_expected_steps_not_lone_generated_schedule(): void
    {
        $middle = $this->matcher->contextForSchedule(
            'mar-16',
            [['id' => 'mar-16', 'pay_date' => '2026-03-16']],
            PayPeriodCadence::Biweekly,
        );

        $this->assertSame(2, $middle->monthIndex);
        $this->assertSame(3, $middle->monthCount);
        $this->assertSame(6, $middle->cycleIndex);
        $this->assertSame(3, $middle->cycleCount);
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 1, $middle));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 2, $middle));
    }

    public function test_cycle_uses_rules_anchor_date_instead_of_calendar_year(): void
    {
        $june15 = $this->matcher->contextForSchedule(
            'jun-15',
            [['id' => 'jun-15', 'pay_date' => '2026-06-15']],
            PayPeriodCadence::SemiMonthly,
            '2026-06-15',
        );

        $this->assertSame(1, $june15->cycleIndex);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 1, $june15));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::Cycle->value, 2, 2, $june15));
    }

    public function test_biweekly_month_with_three_paydays_first_and_last_skip_the_middle(): void
    {
        $schedules = [
            ['id' => 'mar-02', 'pay_date' => '2026-03-02'],
            ['id' => 'mar-16', 'pay_date' => '2026-03-16'],
            ['id' => 'mar-30', 'pay_date' => '2026-03-30'],
        ];

        $first = $this->matcher->contextForSchedule('mar-02', $schedules, PayPeriodCadence::Biweekly);
        $middle = $this->matcher->contextForSchedule('mar-16', $schedules, PayPeriodCadence::Biweekly);
        $last = $this->matcher->contextForSchedule('mar-30', $schedules, PayPeriodCadence::Biweekly);

        $this->assertSame(3, $first->monthCount);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $first));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $middle));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $middle));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $last));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::NthOfMonth->value, null, 2, $middle));
    }

    public function test_monthly_single_payday_matches_first_and_last(): void
    {
        $schedules = [
            ['id' => 'jan', 'pay_date' => '2026-01-31'],
            ['id' => 'feb', 'pay_date' => '2026-02-28'],
        ];

        $jan = $this->matcher->contextForSchedule('jan', $schedules, PayPeriodCadence::Monthly);

        $this->assertSame(1, $jan->monthCount);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $jan));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $jan));
        $this->assertFalse($this->matcher->matches(PayrollItemOccurrence::NthOfMonth->value, null, 2, $jan));
    }

    public function test_monthly_in_period_pay_date_is_still_the_only_payroll_of_the_month(): void
    {
        $june25 = $this->matcher->contextForSchedule(
            'jun-25',
            [['id' => 'jun-25', 'pay_date' => '2026-06-25']],
            PayPeriodCadence::Monthly,
        );

        $this->assertSame(1, $june25->monthIndex);
        $this->assertSame(1, $june25->monthCount);
        $this->assertSame(6, $june25->cycleIndex);
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::FirstOfMonth->value, null, null, $june25));
        $this->assertTrue($this->matcher->matches(PayrollItemOccurrence::LastOfMonth->value, null, null, $june25));
    }
}
