<?php

namespace App\Support;

use App\Models\User;
use App\Services\EmployeeFormAccessService;

class AuthUserPresenter
{
    public static function present(User $user): array
    {
        $formAccess = app(EmployeeFormAccessService::class)->forUser($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first() ?? 'employee',
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'employeeFormAccess' => $formAccess,
            'preferences' => [
                'defaultModule' => $user->defaultModule(),
            ],
        ];
    }
}
