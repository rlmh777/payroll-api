<?php

namespace App\Enums;

enum LeavePaymentTreatment: string
{
    /** Leave is unpaid; payroll should not pay for these days. */
    case Unpaid = 'unpaid';

    /** Leave is paid through the normal payroll run. */
    case PaidWithPayroll = 'paid_with_payroll';

    /** Leave pay was already issued outside this run; payroll must not pay again. */
    case AlreadyPaid = 'already_paid';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function fromStored(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower($value));
    }

    public function shouldExcludeFromPayrollPay(): bool
    {
        return in_array($this, [self::Unpaid, self::AlreadyPaid], true);
    }

    public function defaultMultiplier(): float
    {
        return $this === self::PaidWithPayroll ? 1.0 : 0.0;
    }
}
