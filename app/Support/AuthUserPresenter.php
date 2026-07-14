<?php

namespace App\Support;

use App\Models\User;

class AuthUserPresenter
{
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first() ?? 'employee',
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
