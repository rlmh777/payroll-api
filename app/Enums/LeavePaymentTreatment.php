<?php

namespace App\Enums;

enum LeavePaymentTreatment: string
{
    /** Leave is unpaid; payroll should not pay for these days. */
    case Unpaid = 'unpaid';

    /** Vacation days are paid with the next payroll (normal salary for those days, not extra money). */
    case PaidWithPayroll = 'paid_with_payroll';

    /** Vacation was paid as an advance; next payroll records it but must not pay those days again. */
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
