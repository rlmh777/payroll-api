<?php

namespace App\Enums;

enum LeaveStatusCode: string
{
    case Cancelled = 'CANCELLED';
    case PendingSupervisorApproval = 'PENDING_SUPERVISOR_APPROVAL';
    case PendingApproval = 'PENDING_APPROVAL';
    case PendingHrApproval = 'PENDING_HR_APPROVAL';
    case PendingAccountsConfirmation = 'PENDING_ACCOUNTS_CONFIRMATION';
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
            self::PendingHrApproval,
            self::PendingAccountsConfirmation,
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
     * Fully approved absences that affect timesheets / payroll.
     *
     * @return array<int, self>
     */
    public static function activeAbsenceStatuses(): array
    {
        return [
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

    /**
     * Statuses awaiting an approval / confirmation action.
     *
     * @return array<int, self>
     */
    public static function pendingActionStatuses(): array
    {
        return [
            self::PendingSupervisorApproval,
            self::PendingApproval,
            self::PendingHrApproval,
            self::PendingAccountsConfirmation,
        ];
    }

    /**
     * @return array<int, self>
     */
    public static function cancellableStatuses(): array
    {
        return [
            self::PendingSupervisorApproval,
            self::PendingApproval,
            self::PendingHrApproval,
            self::PendingAccountsConfirmation,
            self::Scheduled,
        ];
    }

    public function isPendingAction(): bool
    {
        return in_array($this, self::pendingActionStatuses(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Rejected, self::Taken], true);
    }
}
