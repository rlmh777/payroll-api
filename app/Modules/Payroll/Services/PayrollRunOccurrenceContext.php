<?php

namespace App\Modules\Payroll\Services;

class PayrollRunOccurrenceContext
{
    public function __construct(
        public readonly ?string $scheduleId,
        public readonly ?string $payDate,
        public readonly int $monthIndex,
        public readonly int $monthCount,
        public readonly int $cycleIndex,
        public readonly int $cycleCount,
    ) {
    }

    public static function unmatched(): self
    {
        return new self(null, null, 0, 0, 0, 0);
    }

    public function isMatched(): bool
    {
        return $this->scheduleId !== null && $this->payDate !== null && $this->monthIndex > 0;
    }
}
