<?php

namespace App\Support;

use App\Models\User;
use App\Services\EmployeeFormAccessService;

class AuthUserPresenter
{
    public const ONBOARDING_SEEN_KEYS = [
        'employees',
        'scheduler',
        'timesheet',
        'leaves',
        'payroll',
        'admin',
    ];

    public static function present(User $user): array
    {
        $formAccess = app(EmployeeFormAccessService::class)->forUser($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'pictureUrl' => $user->pictureUrl,
            'role' => $user->getRoleNames()->first() ?? 'employee',
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'employeeFormAccess' => $formAccess,
            'preferences' => [
                'defaultModule' => $user->defaultModule(),
                'onboardingEnabled' => $user->preference('onboarding_enabled') !== false,
                'onboardingCompleted' => (bool) $user->preference('onboarding_completed', false),
                'onboardingSeen' => self::onboardingSeen($user),
            ],
            'hasPasskeys' => $user->webAuthnCredentials()->exists(),
        ];
    }

    /**
     * @param  array<string, mixed>  $seen
     * @return array<string, bool>
     */
    public static function normalizeOnboardingSeen(array $seen): array
    {
        $normalized = [];

        foreach (self::ONBOARDING_SEEN_KEYS as $key) {
            if (array_key_exists($key, $seen)) {
                $normalized[$key] = (bool) $seen[$key];
            }
        }

        return $normalized;
    }

    /**
     * @return object
     */
    private static function onboardingSeen(User $user): object
    {
        $raw = $user->preference('onboarding_seen', []);
        if (! is_array($raw)) {
            return (object) [];
        }

        return (object) self::normalizeOnboardingSeen($raw);
    }
}
