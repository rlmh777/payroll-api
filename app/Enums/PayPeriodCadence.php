<?php

namespace App\Enums;

enum PayPeriodCadence: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case SemiMonthly = 'semi_monthly';
    case Monthly = 'monthly';
    case Unknown = 'unknown';

    public function stepDays(): ?int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Biweekly => 14,
            default => null,
        };
    }
}
