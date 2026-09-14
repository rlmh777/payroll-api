<?php

namespace App\Support;

use App\Models\User;

/**
 * Permission-based access helpers. Prefer these over hard-coded role names.
 */
class Access
{
    public static function can(?User $user, string $permission): bool
    {
        return (bool) $user?->can($permission);
    }

    public static function canAny(?User $user, array $permissions): bool
    {
        if (! $user) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Company-wide employee visibility (HR / payroll / accountants with write access).
     */
    public static function canViewAllEmployees(?User $user): bool
    {
        return self::canAny($user, [
            'employees-crud',
            'pay-employees-crud',
        ]);
    }

    /**
     * Unrestricted leave administration (typically HR/admin via employees-crud).
     */
    public static function canManageAllLeave(?User $user): bool
    {
        return self::can($user, 'employees-crud') && self::can($user, 'leave-crud');
    }

    /**
     * Accounts / payroll confirmation of leave payment treatment.
     */
    public static function canConfirmLeavePayment(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::canManageAllLeave($user)) {
            return true;
        }

        if (self::canAny($user, ['pay-employees-crud', 'view-payroll', 'manager-tax'])) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'accountant', 'payroll-accountant']);
    }

    /**
     * Company-wide user password management (settings → users).
     */
    public static function canManageAllUserPasswords(?User $user): bool
    {
        return self::can($user, 'manager-users');
    }

    /**
     * Company-wide Payroll other payment entry (not limited to subordinates).
     */
    public static function canManageCompanyPayrollAllowances(?User $user): bool
    {
        return self::canAny($user, [
            'pay-employees-crud',
            'employees-crud',
        ]);
    }

    /**
     * Company-wide day / trip work entry (not limited to subordinates).
     */
    public static function canManageCompanyDayWork(?User $user): bool
    {
        return self::canAny($user, [
            'pay-employees-crud',
            'employees-crud',
        ]);
    }

    /**
     * Company-wide scheduler shift management (GM / admin / HR — not limited to subordinates).
     */
    public static function canManageCompanyScheduler(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::canViewAllEmployees($user)) {
            return true;
        }

        if (self::can($user, 'scheduler-daily-metrics-crud')) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'gm']);
    }
}
