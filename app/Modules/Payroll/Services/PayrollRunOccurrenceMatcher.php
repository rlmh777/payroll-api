<?php

namespace App\Modules\Payroll\Services;

use App\Enums\PayrollItemOccurrence;
use App\Enums\PayPeriodCadence;
use App\Models\PayPeriodSchedule;
use App\Models\PayrollRun;
use Carbon\Carbon;

class PayrollRunOccurrenceMatcher
{
    /** @var array<string, PayrollRunOccurrenceContext> */
    private array $contextByRunId = [];

    public function __construct(
        private readonly PayPeriodCadenceResolver $cadenceResolver = new PayPeriodCadenceResolver(),
    ) {
    }

    public function contextForRunId(?string $payrollRunId): PayrollRunOccurrenceContext
    {
        if (! filled($payrollRunId)) {
            return PayrollRunOccurrenceContext::unmatched();
        }

        if (isset($this->contextByRunId[$payrollRunId])) {
            return $this->contextByRunId[$payrollRunId];
        }

        $run = PayrollRun::query()
            ->with('payPeriodSchedule.payPeriodGroup')
            ->find($payrollRunId);

        $context = $run
            ? $this->contextForRun($run)
            : PayrollRunOccurrenceContext::unmatched();

        $this->contextByRunId[$payrollRunId] = $context;

        return $context;
    }

    public function contextForRun(PayrollRun $payrollRun): PayrollRunOccurrenceContext
    {
        $payrollRun->loadMissing('payPeriodSchedule.payPeriodGroup');
        $schedule = $payrollRun->payPeriodSchedule;
        if (! $schedule) {
            return PayrollRunOccurrenceContext::unmatched();
        }

        $groupId = (string) ($schedule->pay_period_group_id ?? '');
        $schedules = $groupId === ''
            ? collect([$schedule])
            : PayPeriodSchedule::query()
                ->where('pay_period_group_id', $groupId)
                ->get();

        if ($schedules->every(fn (PayPeriodSchedule $row) => (string) $row->id !== (string) $schedule->id)) {
            $schedules = $schedules->push($schedule);
        }

        $cadence = $this->cadenceResolver->fromGroup($schedule->payPeriodGroup);
        $payDate = $this->schedulePayDate($schedule);

        return $this->contextForSchedule(
            (string) $schedule->id,
            $schedules
                ->map(fn (PayPeriodSchedule $row) => [
                    'id' => (string) $row->id,
                    'pay_date' => $this->schedulePayDate($row),
                ])
                ->all(),
            $cadence,
            $payDate !== null
                ? $this->cadenceResolver->epochDate($cadence, $payDate, $schedule->payPeriodGroup?->rules)
                : null,
        );
    }

    /**
     * @param  list<array{id: string, pay_date?: string|null}>  $schedules
     */
    public function contextForSchedule(
        string $scheduleId,
        array $schedules,
        PayPeriodCadence $cadence = PayPeriodCadence::Unknown,
        ?string $epochDate = null,
    ): PayrollRunOccurrenceContext
    {
        $normalized = [];
        foreach ($schedules as $schedule) {
            $id = (string) ($schedule['id'] ?? '');
            $payDate = $this->normalizeDate($schedule['pay_date'] ?? null);
            if ($id === '' || $payDate === null) {
                continue;
            }
            $normalized[] = ['id' => $id, 'pay_date' => $payDate];
        }

        usort($normalized, function (array $left, array $right): int {
            return [$left['pay_date'], $left['id']] <=> [$right['pay_date'], $right['id']];
        });

        $current = null;
        foreach ($normalized as $index => $schedule) {
            if ($schedule['id'] === $scheduleId) {
                $current = ['index' => $index, 'schedule' => $schedule];
                break;
            }
        }

        if ($current === null) {
            return PayrollRunOccurrenceContext::unmatched();
        }

        $payDate = $current['schedule']['pay_date'];
        $monthKey = substr($payDate, 0, 7);
        $monthPeers = array_values(array_filter(
            $normalized,
            fn (array $schedule) => str_starts_with($schedule['pay_date'], $monthKey),
        ));
        $monthSlot = $this->monthSlot($payDate, $cadence, $monthPeers, $scheduleId);
        $cycle = $this->expectedCycle(
            $payDate,
            $cadence,
            $epochDate,
            $current['index'] + 1,
            $monthSlot['count'],
        );

        return new PayrollRunOccurrenceContext(
            $scheduleId,
            $payDate,
            $monthSlot['index'],
            $monthSlot['count'],
            $cycle['index'],
            $cycle['count'],
        );
    }

    public function matches(
        ?string $occurrence,
        ?int $cycleLength,
        ?int $cycleOffset,
        PayrollRunOccurrenceContext $context,
    ): bool {
        $occurrence = PayrollItemOccurrence::fromStored($occurrence);

        if ($occurrence === PayrollItemOccurrence::EveryPayroll) {
            return true;
        }

        if (! $context->isMatched()) {
            return false;
        }

        return match ($occurrence) {
            PayrollItemOccurrence::EveryPayroll => true,
            PayrollItemOccurrence::FirstOfMonth => $context->monthIndex === 1,
            PayrollItemOccurrence::LastOfMonth => $context->monthIndex === $context->monthCount && $context->monthCount > 0,
            PayrollItemOccurrence::NthOfMonth => $cycleOffset !== null
                && $cycleOffset > 0
                && $context->monthIndex === $cycleOffset,
            PayrollItemOccurrence::Cycle => $this->matchesCycle($cycleLength, $cycleOffset, $context),
        };
    }

    public function matchesAssignment(object $assignment, PayrollRunOccurrenceContext $context): bool
    {
        return $this->matches(
            isset($assignment->occurrence) ? (string) $assignment->occurrence : null,
            isset($assignment->occurrenceCycleLength) ? (int) $assignment->occurrenceCycleLength : null,
            isset($assignment->occurrenceCycleOffset) ? (int) $assignment->occurrenceCycleOffset : null,
            $context,
        );
    }

    private function matchesCycle(?int $cycleLength, ?int $cycleOffset, PayrollRunOccurrenceContext $context): bool
    {
        if ($cycleLength === null || $cycleLength < 2 || $cycleOffset === null || $cycleOffset < 1 || $cycleOffset > $cycleLength) {
            return false;
        }

        $slot = (($context->cycleIndex - 1) % $cycleLength) + 1;

        return $slot === $cycleOffset;
    }

    /**
     * @return array{index: int, count: int}
     */
    private function expectedCycle(
        string $payDate,
        PayPeriodCadence $cadence,
        ?string $epochDate,
        int $generatedIndex,
        int $expectedMonthCount,
    ): array {
        $date = Carbon::parse($payDate)->startOfDay();
        $epoch = Carbon::parse($epochDate ?: $date->copy()->startOfYear())->startOfDay();
        if ($epoch->gt($date)) {
            $epoch = $date->copy()->startOfYear();
        }

        $index = match ($cadence) {
            PayPeriodCadence::SemiMonthly => $this->semiMonthlyCycleIndex($date, $epoch),
            PayPeriodCadence::Monthly => $this->monthlyCycleIndex($date, $epoch),
            PayPeriodCadence::Biweekly, PayPeriodCadence::Weekly => $this->steppedCycleIndex(
                $date,
                $epoch,
                (int) $cadence->stepDays(),
            ),
            PayPeriodCadence::Unknown => $generatedIndex,
        };

        return [
            'index' => max(1, $index),
            'count' => max(1, $expectedMonthCount),
        ];
    }

    private function semiMonthlyCycleIndex(Carbon $payDate, Carbon $epoch): int
    {
        $monthsBefore = ($payDate->year - $epoch->year) * 12 + ($payDate->month - $epoch->month);
        $half = $payDate->day <= 15 ? 1 : 2;
        $epochHalf = $epoch->day <= 15 ? 1 : 2;
        $index = $monthsBefore * 2 + $half - $epochHalf + 1;

        return max(1, $index);
    }

    private function monthlyCycleIndex(Carbon $payDate, Carbon $epoch): int
    {
        return ($payDate->year - $epoch->year) * 12 + ($payDate->month - $epoch->month) + 1;
    }

    private function steppedCycleIndex(Carbon $payDate, Carbon $epoch, int $stepDays): int
    {
        $aligned = $payDate->copy()->startOfDay();
        while ($aligned->copy()->subDays($stepDays)->gte($epoch)) {
            $aligned->subDays($stepDays);
        }

        $days = abs($aligned->diffInDays($payDate));

        return (int) round($days / $stepDays) + 1;
    }

    /**
     * @param  list<array{id: string, pay_date: string}>  $monthPeers
     * @return array{index: int, count: int}
     */
    private function monthSlot(
        string $payDate,
        PayPeriodCadence $cadence,
        array $monthPeers,
        string $scheduleId,
    ): array {
        $date = Carbon::parse($payDate)->startOfDay();

        return match ($cadence) {
            PayPeriodCadence::SemiMonthly => [
                'index' => $date->day <= 15 ? 1 : 2,
                'count' => 2,
            ],
            PayPeriodCadence::Monthly => [
                'index' => 1,
                'count' => 1,
            ],
            PayPeriodCadence::Biweekly, PayPeriodCadence::Weekly => $this->steppedMonthSlot(
                $date,
                (int) $cadence->stepDays(),
            ),
            PayPeriodCadence::Unknown => $this->generatedMonthSlot($date, $monthPeers, $scheduleId),
        };
    }

    /**
     * @return array{index: int, count: int}
     */
    private function steppedMonthSlot(Carbon $payDate, int $stepDays): array
    {
        $monthKey = $payDate->format('Y-m');
        $index = 1;
        $cursor = $payDate->copy()->subDays($stepDays);
        while ($cursor->format('Y-m') === $monthKey) {
            $index++;
            $cursor->subDays($stepDays);
        }

        $count = $index;
        $cursor = $payDate->copy()->addDays($stepDays);
        while ($cursor->format('Y-m') === $monthKey) {
            $count++;
            $cursor->addDays($stepDays);
        }

        return [
            'index' => $index,
            'count' => $count,
        ];
    }

    /**
     * @param  list<array{id: string, pay_date: string}>  $monthPeers
     * @return array{index: int, count: int}
     */
    private function generatedMonthSlot(Carbon $payDate, array $monthPeers, string $scheduleId): array
    {
        $index = 0;
        foreach ($monthPeers as $peerIndex => $schedule) {
            if ($schedule['id'] === $scheduleId) {
                $index = $peerIndex + 1;
                break;
            }
        }

        $count = count($monthPeers);
        if ($count <= 1 && $payDate->day <= 15) {
            $count = 2;
        }

        return [
            'index' => max(1, $index),
            'count' => max(1, $count),
        ];
    }

    private function schedulePayDate(PayPeriodSchedule $schedule): ?string
    {
        return $this->normalizeDate($schedule->pay_date)
            ?? $this->normalizeDate($schedule->end_date)
            ?? $this->normalizeDate($schedule->start_date);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
