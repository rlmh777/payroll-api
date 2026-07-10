<?php

namespace App\Enums;

enum LeaveStatusCode: string
{
    case Cancelled = 'CANCELLED';
    case PendingSupervisorApproval = 'PENDING_SUPERVISOR_APPROVAL';
    case PendingApproval = 'PENDING_APPROVAL';
    case Scheduled = 'SCHEDULED';
    case Taken = 'TAKEN';
    case Rejected = 'REJECTED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromStored(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtoupper($value));
    }

    /**
     * @return array<int, self>
     */
    public static function scheduledBalanceStatuses(): array
    {
        return [
            self::PendingSupervisorApproval,
            self::PendingApproval,
            self::Scheduled,
        ];
    }

    /**
     * @return array<int, self>
     */
    public static function takenBalanceStatuses(): array
    {
        return [self::Taken];
    }

    /**
     * @return array<int, self>
     */
    public static function activeAbsenceStatuses(): array
    {
        return [
            self::PendingApproval,
            self::Scheduled,
            self::Taken,
        ];
    }

    /**
     * @return array<int, self>
     */
    public static function pendingSupervisorStatuses(): array
    {
        return [self::PendingSupervisorApproval];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Rejected, self::Taken], true);
    }
}
