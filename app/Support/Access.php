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
     * Company-wide payroll allowance entry (not limited to subordinates).
     */
    public static function canManageCompanyPayrollAllowances(?User $user): bool
    {
        return self::canAny($user, [
            'pay-employees-crud',
            'employees-crud',
        ]);
    }
}
